<?php

namespace Tarot\Service;

use JsonException;
use Random\RandomException;
use Tarot\Exception\ApiException;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Repository\SpreadRepository;
use Tarot\Repository\UserSpreadRepository;
use Tarot\Structure\Deck;
use Tarot\Structure\Reading;
use Tarot\Structure\Spread;
use Tarot\Structure\UserSpread;

/**
 * The reading engine: turns a request (deck + options, or a hand-authored set
 * of cards) into a persisted {@see Reading}.
 *
 * This is pure domain logic with no knowledge of HTTP. Client-facing failures
 * are signalled by throwing {@see ApiException}; controllers translate those
 * into responses. Persistence and card-name lookups are injected so the engine
 * can be unit-tested in isolation.
 */
class ReadingService
{
    /**
     * Probability that any single packet is turned (180°) during the
     * cut-and-turn pass. In a single pass this is also the approximate
     * per-card reversal rate, so tune this to taste (e.g. 0.27 ≈ 27%).
     */
    private const float REVERSAL_TURN_PROBABILITY = 0.27;

    public function __construct(
        private readonly ReadingRepository $readings,
        private readonly DeckRepository $decks,
        private readonly SpreadRepository $spreads,
        private readonly UserSpreadRepository $userSpreads,
        private readonly CardNameResolver $cardNames,
    ) {
    }

    /**
     * Generate a randomized reading from a deck (optionally shaped by a spread).
     *
     * @param array<string,mixed> $params Raw request fields.
     * @throws ApiException When the deck is invalid or the reading can't be saved.
     * @throws RandomException
     * @throws JsonException
     */
    public function generate(array $params, ?int $userId = null): Reading
    {
        $number_of_cards = max(1, (int)($params['number_of_cards'] ?? 1));
        $deck_id         = (int)($params['deck_id'] ?? 1);
        // Parse with FILTER_VALIDATE_BOOLEAN so the string "false" (which a
        // plain (bool) cast treats as true) is correctly read as false.
        $use_reversals        = filter_var($params['use_reversals'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $use_additional_cards = filter_var($params['use_additional_cards'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $spread_id            = $params['spread_id'] ?? null;
        $user_spread_id       = $params['user_spread_id'] ?? null;

        // If a spread was selected, load it. The spread dictates the number of
        // cards drawn (one per position) and is snapshotted into the reading.
        $spread = null;
        if ($spread_id !== null && $spread_id !== '' && (int)$spread_id > 0) {
            $loaded = $this->spreads->get((int)$spread_id);
            if ($loaded instanceof Spread) {
                $spread          = $loaded;
                $number_of_cards = max(1, $spread->getCardCount());
            }
        } elseif ($user_spread_id !== null && $user_spread_id !== '' && (int)$user_spread_id > 0) {
            $loaded = $this->userSpreads->findById((int)$user_spread_id);
            if ($loaded instanceof UserSpread) {
                // Wrap user spread into the same shape used for snapshots.
                $spread          = $this->userSpreadAsSpread($loaded);
                $number_of_cards = max(1, $spread->getCardCount());
            }
        }

        $deck = $this->decks->get($deck_id);
        if (!($deck instanceof Deck)) {
            throw new ApiException('InvalidDeckID', 400);
        }

        $total_cards = $deck->getSystemTotalCards() ?: $deck->getTotalCards();

        // If flag was sent, use additional cards. Default is 0 so mistakes won't affect outcome.
        if ($use_additional_cards) {
            $total_cards += $deck->getAdditionalCards();
        }

        // Never draw (or allocate work for) more cards than the deck holds.
        $number_of_cards = min($number_of_cards, max(1, $total_cards));

        // Generate the deck, then shuffle it with a single cryptographically
        // secure Fisher–Yates pass, and take the draw from the top.
        $deck_of_cards = $this->secureShuffle(range(1, $total_cards));
        $draw          = array_slice($deck_of_cards, 0, $number_of_cards);

        // Determine each card's orientation. When reversals are enabled we model
        // the reader's "cut and turn" over the drawn cards so orientations arrive
        // in realistic runs; otherwise every card stays upright.
        $reversal_values = $use_reversals
            ? $this->cutAndTurn($number_of_cards)
            : array_fill(0, $number_of_cards, false);

        // Resolve every drawn card's display name in at most two batched queries.
        $card_names = $this->cardNames->resolve($deck, $draw);

        $reading_data            = [];
        $reading_data['deck_id'] = $deck_id;

        // Snapshot the spread layout so shared reading links render correctly
        // even if the spread is later edited or deleted.
        if ($spread instanceof Spread) {
            $reading_data['spread'] = $this->buildSpreadSnapshot($spread, $params['position_titles'] ?? null);
        }

        $reading_data['draw'] = [];
        foreach ($draw as $key => $card_id) {
            $reading_data['draw'][$key]['card_id']  = $card_id;
            $reading_data['draw'][$key]['reversed'] = $reversal_values[$key];

            if (isset($card_names[$card_id])) {
                $reading_data['draw'][$key]['card_name'] = $card_names[$card_id];
            }
        }

        return $this->persist($reading_data, $this->buildOwnerOptions($userId, $params));
    }

    /**
     * Create a user-authored reading: the visitor chooses the exact card,
     * orientation, title and on-canvas position for each slot (e.g. to record a
     * physical reading). Stored in the same shape as a generated reading so
     * ReadingView renders it unchanged. Card names are resolved server-side and
     * never trusted from the client.
     *
     * @param array<string,mixed> $params Raw request fields.
     * @throws ApiException On any validation failure or save error.
     * @throws RandomException
     * @throws JsonException
     */
    public function createCustom(array $params, ?int $userId = null): Reading
    {
        $deck_id = (int)($params['deck_id'] ?? 0);

        $name = mb_substr(trim((string)($params['name'] ?? '')), 0, 100);
        if ($name === '') {
            $name = 'Custom Reading';
        }

        // The cards payload may arrive as a decoded array (JSON body) or a JSON
        // string (urlencoded body); accept either.
        $cards = $params['cards'] ?? [];
        if (is_string($cards)) {
            $decoded = json_decode($cards, true);
            $cards   = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($cards) || count($cards) === 0) {
            throw new ApiException('At least one card is required.', 400);
        }

        $deck = $this->decks->get($deck_id);
        if (!($deck instanceof Deck)) {
            throw new ApiException('InvalidDeckID', 400);
        }

        $available = max(1, ($deck->getSystemTotalCards() ?: $deck->getTotalCards()) + $deck->getAdditionalCards());

        // Never store more cards than the deck physically holds.
        $cards = array_slice(array_values($cards), 0, $available);

        // First pass: sanitize each entry and collect the card IDs to resolve.
        $entries  = [];
        $seen_ids = [];

        foreach ($cards as $idx => $card) {
            if (!is_array($card)) {
                throw new ApiException('Invalid card data.', 400);
            }

            $card_id = (int)($card['card_id'] ?? 0);
            if ($card_id < 1 || $card_id > $available) {
                throw new ApiException('A selected card is not part of this deck.', 400);
            }

            // A physical deck holds each card once, so disallow duplicates.
            if (isset($seen_ids[$card_id])) {
                throw new ApiException('Each card can only be used once in a reading.', 400);
            }
            $seen_ids[$card_id] = true;

            $rotation = (int)($card['rotation'] ?? 0) % 360;
            if ($rotation < 0) {
                $rotation += 360;
            }

            $entries[] = [
                'order'    => $idx + 1,
                'card_id'  => $card_id,
                'reversed' => filter_var($card['reversed'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'title'    => mb_substr(trim((string)($card['title'] ?? '')), 0, 100),
                'x'        => max(0.0, min(100.0, round((float)($card['x'] ?? 50), 2))),
                'y'        => max(0.0, min(100.0, round((float)($card['y'] ?? 50), 2))),
                'rotation' => $rotation,
            ];
        }

        // Resolve display names in at most two batched queries.
        $card_names = $this->cardNames->resolve($deck, array_keys($seen_ids));

        // Build the spread snapshot + ordered draw.
        $positions = [];
        $draw      = [];

        foreach ($entries as $key => $entry) {
            $card_id   = $entry['card_id'];
            $card_name = $card_names[$card_id] ?? null;

            if ($card_name === null) {
                throw new ApiException('A selected card could not be found.', 400);
            }

            $positions[] = [
                'order'    => $entry['order'],
                'title'    => $entry['title'],
                'x'        => $entry['x'],
                'y'        => $entry['y'],
                'rotation' => $entry['rotation'],
            ];

            $draw[$key] = [
                'card_id'   => $card_id,
                'reversed'  => $entry['reversed'],
                'card_name' => $card_name,
            ];
        }

        $reading_data = [
            'deck_id' => $deck_id,
            'spread'  => [
                'spread_id'   => 0,
                'name'        => $name,
                'description' => '',
                'positions'   => $positions,
            ],
            'draw'    => $draw,
        ];

        return $this->persist($reading_data, $this->buildOwnerOptions($userId, $params), 500);
    }

    /**
     * Resolve the owner-only options (name, hidden author, view password) from a
     * request. These are honored only when a user is logged in ($userId set);
     * for guests an empty set is returned so the reading is anonymous and public.
     *
     * @param array<string,mixed> $params
     * @return array{user_id?:int,hide_user?:bool,reading_name?:?string,password_hash?:?string}
     * @throws ApiException
     */
    private function buildOwnerOptions(?int $userId, array $params): array
    {
        if ($userId === null) {
            return [];
        }

        $name = mb_substr(trim((string)($params['reading_name'] ?? '')), 0, 100);

        $passwordHash = null;
        $password = (string)($params['password'] ?? '');
        if ($password !== '') {
            if (mb_strlen($password) < 4) {
                throw new ApiException('Reading password must be at least 4 characters.', 400);
            }
            $passwordHash = password_hash($password, AuthService::passwordAlgo());
        }

        $notes = mb_substr((string)($params['reading_notes'] ?? ''), 0, 20000);

        return [
            'user_id'       => $userId,
            'hide_user'     => filter_var($params['hide_user'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'reading_name'  => $name !== '' ? $name : null,
            'reading_notes' => trim($notes) !== '' ? $notes : null,
            'password_hash' => $passwordHash,
        ];
    }

    /**
     * Build a spread snapshot, applying any custom position titles. Titles
     * arrive as a JSON array aligned with positions sorted by order; a blank
     * entry keeps the spread's default title for that position.
     *
     * @return array<string,mixed>
     */
    private function buildSpreadSnapshot(Spread $spread, mixed $rawTitles): array
    {
        $positions = $spread->getPositions();

        $custom_titles = [];
        if (is_string($rawTitles) && $rawTitles !== '') {
            $decoded = json_decode($rawTitles, true);
            if (is_array($decoded)) {
                $custom_titles = $decoded;
            }
        }

        usort($positions, static fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        foreach ($positions as $idx => $pos) {
            $custom = $custom_titles[$idx] ?? null;
            if (is_string($custom)) {
                $custom = mb_substr(trim($custom), 0, 100);
                if ($custom !== '') {
                    $positions[$idx]['title'] = $custom;
                }
            }
        }

        return [
            'spread_id'   => $spread->getSpreadId(),
            'name'        => $spread->getName(),
            'description' => $spread->getDescription(),
            'positions'   => $positions,
        ];
    }

    /**
     * Wrap a UserSpread into a Spread structure so the reading engine can treat
     * personal spreads identically to public ones.
     */
    private function userSpreadAsSpread(UserSpread $userSpread): Spread
    {
        return new Spread([
            'spread_id'   => 0, // not a public spread
            'name'        => $userSpread->getName(),
            'description' => $userSpread->getDescription(),
            'card_count'  => $userSpread->getCardCount(),
            'positions'   => $userSpread->getPositions(),
        ]);
    }

    /**
     * Wrap reading data in a {@see Reading}, assign an id, and save it.
     *
     * @param array<string,mixed> $reading_data
     * @throws ApiException When the save fails.
     * @throws JsonException
     * @throws RandomException
     */
    private function persist(array $reading_data, array $owner = [], int $failureStatus = 404): Reading
    {
        $reading = new Reading();
        $reading->setReadingId(bin2hex(random_bytes(5)));
        $reading->setReadingInfo(json_encode($reading_data, JSON_THROW_ON_ERROR));

        if (isset($owner['user_id'])) {
            $reading->setUserId((int)$owner['user_id']);
            $reading->setHideUser((bool)($owner['hide_user'] ?? false));
            $reading->setReadingName($owner['reading_name'] ?? null);
            $reading->setReadingNotes($owner['reading_notes'] ?? null);
        }

        $saved = $this->readings->save($reading, $owner['password_hash'] ?? null);
        if (!($saved instanceof Reading)) {
            throw new ApiException('ErrorGeneratingReading', $failureStatus);
        }

        return $saved;
    }

    /**
     * Fisher–Yates shuffle backed by random_int() (CSPRNG). A single pass
     * produces a uniformly random permutation.
     *
     * @param  int[] $array
     * @return int[]
     * @throws RandomException
     */
    private function secureShuffle(array $array): array
    {
        for ($i = count($array) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$array[$i], $array[$j]] = [$array[$j], $array[$i]];
        }

        return $array;
    }

    /**
     * Model a reader's "cut and turn": split the deck into a few contiguous
     * packets at random boundaries and turn each one (flip the orientation of
     * every card in it) on a weighted coin flip. Returns a per-position array of
     * booleans where true means the card ended up reversed.
     *
     * Reversals therefore arrive in realistic runs rather than independently,
     * while the per-card rate tracks REVERSAL_TURN_PROBABILITY.
     *
     * @return bool[]
     * @throws RandomException
     */
    private function cutAndTurn(int $count): array
    {
        if ($count < 1) {
            return [];
        }

        // Every card starts upright; a turned packet flips its cards.
        $orientations = array_fill(0, $count, false);

        // Split into a handful of packets at random, unique boundaries.
        $max_cuts  = min($count - 1, 6);
        $cut_count = $max_cuts > 0 ? random_int(min(3, $max_cuts), $max_cuts) : 0;

        $cuts = [];
        while (count($cuts) < $cut_count) {
            $cuts[random_int(1, $count - 1)] = true;
        }
        $boundaries = array_keys($cuts);
        sort($boundaries);
        $boundaries[] = $count; // final packet ends at the bottom of the deck

        // Integer threshold for a CSPRNG coin flip at the configured rate.
        $threshold = (int)round(self::REVERSAL_TURN_PROBABILITY * 1_000_000);

        $start = 0;
        foreach ($boundaries as $end) {
            if (random_int(1, 1_000_000) <= $threshold) {
                for ($i = $start; $i < $end; $i++) {
                    $orientations[$i] = true;
                }
            }
            $start = $end;
        }

        return $orientations;
    }
}
