<?php

namespace Routes\Internal;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Random\RandomException;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\CardRepository;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Repository\SpecialCardRepository;
use Tarot\Structure\Reading;

class ReadingController extends AbstractController
{
    public function getReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        // Initialize Reading ID if provided
        $reading_id = $this->parseParameters($args, 'reading_id', null);

        // Assume OK status
        $status = 200;

        // Default Data
        $data = [];

        // Setup Repository
        $repo = new ReadingRepository();

        // If we have a Reading ID, retrieve it.
        if ($reading_id !== null) {
            $data = $repo->get($reading_id);
        }

        // Check for Invalid Reading Data
        if ($reading_id !== null && !($data instanceof Reading)) {
            $data = ['error' => 'InvalidReadingID'];
            $status = 404;
        }

        return $response->withJson($data, $status);
    }

    /**
     * @throws RandomException
     * @throws JsonException
     */
    public function newReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        // Initialize Required Variables
        $params = $request->getParsedBody();
        $number_of_cards = (int)$this->parseParameters($params, 'number_of_cards', 1);
        $deck_id = (int)$this->parseParameters($params, 'deck_id', 1);
        $reversal_chance = (int)$this->parseParameters($params, 'reversal_chance', 0);
        $number_of_shuffles = (int)$this->parseParameters($params, 'number_of_shuffles', 1);
        $use_additional_cards = (bool)$this->parseParameters($params, 'use_additional_cards', false);

        // Assume OK status
        $status = 200;

        // Default Data
        $data = [];

        // Setup Repositories
        $reading_repo = new ReadingRepository();
        $card_repo = new CardRepository();
        $deck_repo = new DeckRepository();
        $special_card_repo = new SpecialCardRepository();

        // Get Deck Data
        $deck = $deck_repo->get($deck_id);

        // Total Cards
        $total_cards = $deck->getTotalCards();

        // If flag was sent, use additional cards. Default is 0 so mistakes won't effect outcome.
        if ($use_additional_cards) {
            $total_cards += $deck->getAdditionalCards();
        }


        // Generate Deck - Based on Deck's Data
        $deck_of_cards = range(1, $total_cards);

        // Shuffle the deck.
        for ($i = 0; $i < $number_of_shuffles; $i++) {
            shuffle($deck_of_cards);
        }

        // Get the draw
        $draw = array_slice($deck_of_cards, 0, $number_of_cards);

        // Initialize Reversal Values
        $reversal_values = [];

        // If no reversals, all false
        if ($reversal_chance === 0) {
            $reversal_values = array_fill(0, $number_of_cards, false);
        // Generate array of reversal values for each card.
        } else {
            for ($i = 0; $i < $number_of_cards; $i++) {
                $reversal_values[] = (bool)(random_int(1, 100) <= $reversal_chance);
            }
        }

        // Batch fetch all cards to avoid N+1 queries
        $standard_card_ids = [];
        $special_card_ids = [];

        foreach ($draw as $card_id) {
            if ($card_id > 78 || $deck->isNonStandard()) {
                $special_card_ids[] = $card_id;
            } else {
                $standard_card_ids[] = $card_id;
            }
        }

        // Fetch all needed cards in two queries max
        $standard_cards = [];
        if (!empty($standard_card_ids)) {
            foreach ($card_repo->getMultiple($standard_card_ids) as $card) {
                $standard_cards[$card->getCardId()] = $card;
            }
        }

        $special_cards = [];
        if (!empty($special_card_ids)) {
            foreach ($special_card_repo->getMultiple($deck_id, $special_card_ids) as $card) {
                $special_cards[$card->getCardId()] = $card;
            }
        }

        // Build the Reading
        $reading = new Reading();

        // Generate Reading ID
        $reading_id = bin2hex(random_bytes(5));
        $reading->setReadingId($reading_id);

        // Set up the Reading Data
        $reading_data = [];
        $reading_data['deck_id'] = $deck_id;
        $reading_data['draw'] = [];

        // Loop through the draw and set the card data for each card
        foreach ($draw as $key => $card_id) {
            // Set normal properties
            $reading_data['draw'][$key]['card_id'] = $card_id;
            $reading_data['draw'][$key]['reversed'] = $reversal_values[$key];

            // Look up card from pre-fetched data
            if ($card_id > 78 || $deck->isNonStandard()) {
                $card = $special_cards[$card_id] ?? null;
            } else {
                $card = $standard_cards[$card_id] ?? null;
            }

            // Set Card Name
            if ($card) {
                $reading_data['draw'][$key]['card_name'] = (!$deck->isThoth()) ? $card->getName() : $card->getNameThoth();
            }
        }

        // Set Reading Info
        $reading->setReadingInfo(json_encode($reading_data, JSON_THROW_ON_ERROR));

        // Save the Reading
        $reading = $reading_repo->save($reading);

        // Save data
        $data = $reading;

        // Check for Invalid Reading Data
        if (!($data instanceof Reading)) {
            $data = ['error' => 'ErrorGeneratingReading'];
            $status = 404;
        }

        return $response->withJson($data, $status);
    }
}
