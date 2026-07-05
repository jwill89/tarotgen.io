<?php

namespace Tarot\Service;

use Random\RandomException;
use Tarot\Config\Env;
use Tarot\Repository\PluginClientRepository;
use Tarot\Repository\PluginMessageRepository;
use Tarot\Structure\PluginClient;
use Tarot\Structure\PluginMessage;

/**
 * The chatless-share relay engine. Every plugin install holds an opaque **client
 * token** (guest by default, optionally account-linked) which routes shares and
 * carries presence — see plugin/docs/sharing.md. Only token hashes are stored.
 *
 * Delivery is a persisted, short-TTL inbox drained by the recipient's poll, so a
 * plain short-poll behaves as a push with no socket. The server never sees game
 * state: senders address a self-published `Character@World`, and the party/friends
 * consent tiers are enforced client-side before the popup shows. The server hard-
 * enforces only the `nobody` tier and the block list.
 */
class ShareService
{
    /** Greppable prefix so a leaked client token is identifiable (distinct from the account `tg_pat_`). */
    private const string TOKEN_PREFIX = 'tg_pct_';

    /** A share waits at most this long for an offline recipient before the sweep drops it. */
    private const int MESSAGE_TTL_SECONDS = 300;

    /** Fan-out ceiling: a sender may reach at most this many distinct recipients per hour. */
    private const int MAX_DISTINCT_RECIPIENTS_PER_HOUR = 15;

    /**
     * Upper bound on identities one install may publish. A FFXIV service account
     * caps at 40 characters, so a legitimate user never hits this; the limit only
     * stops a client from squatting on / bloating the identity table with a huge
     * self-published roster (excess entries beyond the cap are ignored).
     */
    private const int MAX_IDENTITIES = 40;

    private const string SHARE_TYPE = 'reading_share';

    /** Consent tiers. Only `nobody` is hard-enforced server-side; the rest are client filters. */
    private const array ACCEPT_TIERS = ['nobody', 'party', 'friends', 'party_or_friends', 'anyone'];

    /**
     * Fallback key for the identity HMAC when PLUGIN_IDENTITY_SALT is unset. With
     * the env salt set (recommended on prod), a DB dump can't be brute-forced back
     * to character names; without it, the roster is still never stored in plaintext.
     */
    private const string DEFAULT_IDENTITY_SALT = 'tarotgen.plugin.identity.v1';

    public function __construct(
        private readonly PluginClientRepository $clients,
        private readonly PluginMessageRepository $messages,
    ) {
    }

    /**
     * Mint a client token and its routing row. `$userId` is null for a guest
     * install, or the account id when issued through the account-link flow.
     *
     * @return array{token:string,client_id:int}
     * @throws RandomException
     */
    public function issueClient(?int $userId): array
    {
        $token    = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
        $clientId = $this->clients->create($this->hash($token), $userId);

        return ['token' => $token, 'client_id' => $clientId];
    }

    /**
     * Resolve an `Authorization: Bearer …` client token to its client id, or null
     * when the header is absent/malformed or the token is unknown/revoked.
     */
    public function resolveClient(string $authorizationHeader): ?int
    {
        $token = $this->extractBearer($authorizationHeader);
        if ($token === null) {
            return null;
        }

        $row = $this->clients->findActive($this->hash($token));

        return $row['client_id'] ?? null;
    }

    /**
     * Publish/refresh this install's consent tier and its set of recipient
     * identities. One install can address several characters, so `$identities` is
     * the FULL desired set: it is synced (missing added, extras removed). Pass
     * `null` to leave the identity set untouched, or `[]` to unpublish everything.
     * Only the keyed hash of each Name@World is stored — never the plaintext.
     * Returns the client's own updated view.
     *
     * @param list<array{character_name:string,world:string}>|null $identities
     */
    public function register(int $clientId, ?string $acceptTier, ?array $identities): ?PluginClient
    {
        if ($acceptTier !== null && in_array($acceptTier, self::ACCEPT_TIERS, true)) {
            $this->clients->setAcceptTier($clientId, $acceptTier);
        }

        if ($identities !== null) {
            $hashes = [];
            foreach ($identities as $id) {
                $character = $this->clean($id['character_name'], 48);
                $homeWorld = $this->clean($id['world'], 48);
                if ($character !== '' && $homeWorld !== '') {
                    $hashes[$this->identityHash($character, $homeWorld)] = true;
                }

                if (count($hashes) >= self::MAX_IDENTITIES) {
                    break;
                }
            }
            $this->clients->syncIdentities($clientId, array_keys($hashes));
        }

        $this->clients->touchLastSeen($clientId);

        return $this->clients->findById($clientId);
    }

    /**
     * Drain the caller's inbox: bump presence, sweep expired rows, return queued
     * shares (each returned once).
     *
     * @return list<PluginMessage>
     */
    public function drainInbox(int $clientId): array
    {
        $this->clients->touchLastSeen($clientId);
        $this->messages->sweepExpired();

        return $this->messages->drain($clientId);
    }

    /**
     * Route a reading share to a self-published `Character@World`. Returns:
     *   invalid — the request was malformed (missing recipient or payload);
     *   sent    — otherwise, for EVERY well-formed request.
     *
     * The uniform `sent` response is the privacy guarantee: the sender is never told
     * whether a recipient exists, is accepting, or has blocked them, so `/share`
     * can't be used to probe who runs the plugin. Delivery happens only when a
     * valid, accepting, non-blocked, under-cap recipient is found; every other case
     * is silently dropped and still reports `sent`.
     */
    public function send(
        int $senderClientId,
        string $senderLabel,
        string $senderCharacter,
        string $senderWorld,
        string $recipientCharacter,
        string $recipientWorld,
        string $payload
    ): string {
        $character  = $this->clean($recipientCharacter, 48);
        $homeWorld  = $this->clean($recipientWorld, 48);
        $label      = $this->clean($senderLabel, 64);
        $fromChar   = $this->clean($senderCharacter, 48);
        $fromWorld  = $this->clean($senderWorld, 48);
        $code       = $this->clean($payload, 128);

        if ($character === '' || $homeWorld === '' || $code === '') {
            return 'invalid';
        }

        $this->messages->sweepExpired();

        $recipient = $this->clients->findByIdentity($this->identityHash($character, $homeWorld));
        $since     = date('Y-m-d H:i:s', time() - 3600);

        $deliver = $recipient !== null
            && $recipient['client_id'] !== $senderClientId
            && $recipient['accept_tier'] !== 'nobody'
            && !$this->clients->isBlocked($recipient['client_id'], $senderClientId)
            && $this->messages->distinctRecipientsSince($senderClientId, $since) < self::MAX_DISTINCT_RECIPIENTS_PER_HOUR;

        if ($deliver) {
            $this->messages->enqueue(
                $recipient['client_id'],
                $senderClientId,
                $label === '' ? 'A TarotGen user' : $label,
                $fromChar === '' ? null : $fromChar,
                $fromWorld === '' ? null : $fromWorld,
                self::SHARE_TYPE,
                $code,
                $this->expiresIn(self::MESSAGE_TTL_SECONDS)
            );
        }

        return 'sent';
    }

    public function block(int $ownerClientId, int $blockedClientId): void
    {
        if ($blockedClientId > 0 && $blockedClientId !== $ownerClientId) {
            $this->clients->block($ownerClientId, $blockedClientId);
        }
    }

    public function unblock(int $ownerClientId, int $blockedClientId): void
    {
        $this->clients->unblock($ownerClientId, $blockedClientId);
    }

    /**
     * A keyed, normalised hash of a `Name@World` identity. Case/space-insensitive
     * so a sender's target and the recipient's self-registration always match. The
     * plaintext name is never stored — only this digest — so the relay never holds
     * a readable roster of players.
     */
    private function identityHash(string $characterName, string $world): string
    {
        $salt = (string)(Env::get('PLUGIN_IDENTITY_SALT') ?? '');
        if ($salt === '') {
            $salt = self::DEFAULT_IDENTITY_SALT;
        }

        $normalised = mb_strtolower(trim($characterName)) . "\x1f" . mb_strtolower(trim($world));

        return hash_hmac('sha256', $normalised, $salt);
    }

    /** Trim, collapse to a max length, and normalise a null to ''. */
    private function clean(?string $value, int $max): string
    {
        $value = trim((string)$value);

        return $value === '' ? '' : mb_substr($value, 0, $max);
    }

    private function extractBearer(string $header): ?string
    {
        return preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches) === 1 ? $matches[1] : null;
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private function expiresIn(int $seconds): string
    {
        return date('Y-m-d H:i:s', time() + $seconds);
    }
}
