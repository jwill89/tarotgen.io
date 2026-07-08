<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\PluginClientData;
use Tarot\Data\PluginMessageData;
use Tarot\Repository\PluginClientRepository;
use Tarot\Repository\PluginMessageRepository;
use Tarot\Service\ShareService;

/**
 * Exercises the chatless-share relay engine against an in-memory SQLite DB:
 * client-token issuance/resolution, multi-character identity registration +
 * consent, addressed delivery with the consent/block/throttle guards, and
 * once-only inbox drain.
 */
#[CoversClass(ShareService::class)]
#[CoversClass(PluginClientData::class)]
#[CoversClass(PluginMessageData::class)]
final class ShareServiceTest extends TestCase
{
    private PDO $pdo;
    private ShareService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(
            "CREATE TABLE plugin_clients (
                client_id      INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash     TEXT    NOT NULL UNIQUE,
                user_id        INTEGER NULL,
                identity_hash  TEXT    DEFAULT NULL,
                accept_tier    TEXT    NOT NULL DEFAULT 'party_or_friends',
                last_seen      TEXT    DEFAULT NULL,
                created_at     TEXT    NOT NULL,
                revoked_at     TEXT    DEFAULT NULL
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE plugin_client_identities (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id     INTEGER NOT NULL,
                identity_hash TEXT    NOT NULL,
                last_seen     TEXT    DEFAULT NULL,
                created_at    TEXT    NOT NULL,
                UNIQUE (client_id, identity_hash)
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE plugin_messages (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                recipient_client_id INTEGER NOT NULL,
                sender_client_id    INTEGER NOT NULL,
                sender_label        TEXT    NOT NULL,
                sender_character    TEXT    DEFAULT NULL,
                sender_world        TEXT    DEFAULT NULL,
                type                TEXT    NOT NULL DEFAULT 'reading_share',
                payload             TEXT    NOT NULL,
                created_at          TEXT    NOT NULL,
                delivered_at        TEXT    DEFAULT NULL,
                expires_at          TEXT    DEFAULT NULL
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE plugin_blocks (
                owner_client_id   INTEGER NOT NULL,
                blocked_client_id INTEGER NOT NULL,
                created_at        TEXT    NOT NULL,
                PRIMARY KEY (owner_client_id, blocked_client_id)
            )"
        );

        $this->service = new ShareService(
            new PluginClientRepository(new PluginClientData($this->pdo)),
            new PluginMessageRepository(new PluginMessageData($this->pdo)),
        );
    }

    /** @return list<array{character_name:string,world:string}> */
    private static function ident(string $character, string $world): array
    {
        return [['character_name' => $character, 'world' => $world]];
    }

    public function testIssueGuestClientMintsResolvableToken(): void
    {
        $client = $this->service->issueClient(null);

        $this->assertStringStartsWith('tg_pct_', $client['token']);
        $this->assertGreaterThan(0, $client['client_id']);
        $this->assertSame($client['client_id'], $this->service->resolveClient('Bearer ' . $client['token']));
    }

    public function testResolveClientRejectsUnknownAndMalformed(): void
    {
        $this->assertNull($this->service->resolveClient('Bearer tg_pct_deadbeef'));
        $this->assertNull($this->service->resolveClient(''));
        $this->assertNull($this->service->resolveClient('Basic abc'));
        $this->assertNull($this->service->resolveClient('Bearer'));
    }

    public function testRegisterPublishesIdentityAndTier(): void
    {
        $recipient = $this->service->issueClient(null);
        $sender    = $this->service->issueClient(null);

        $view = $this->service->register($recipient['client_id'], 'anyone', self::ident('Y\'shtola Rhul', 'Zurvan'));
        $this->assertNotNull($view);
        $this->assertSame('anyone', $view->accept_tier);

        // The identity is resolvable: a share addressed to it is delivered.
        $this->service->send($sender['client_id'], 'S', 'S', 'Zurvan', 'Y\'shtola Rhul', 'Zurvan', 'CODE');
        $this->assertCount(1, $this->service->drainInbox($recipient['client_id']));
    }

    public function testMultipleCharactersAreEachAddressable(): void
    {
        $recipient = $this->service->issueClient(null);
        $sender    = $this->service->issueClient(null);

        // One install links two characters — both deliver to the same inbox.
        $this->service->register($recipient['client_id'], 'anyone', [
            ['character_name' => 'Alt One', 'world' => 'Zurvan'],
            ['character_name' => 'Alt Two', 'world' => 'Twintania'],
        ]);

        $this->service->send($sender['client_id'], 'S', 'S', 'Zurvan', 'Alt One', 'Zurvan', 'A');
        $this->service->send($sender['client_id'], 'S', 'S', 'Zurvan', 'Alt Two', 'Twintania', 'B');
        $this->assertCount(2, $this->service->drainInbox($recipient['client_id']));
    }

    public function testSyncRemovesUnlistedIdentity(): void
    {
        $recipient = $this->service->issueClient(null);
        $sender    = $this->service->issueClient(null);

        $this->service->register($recipient['client_id'], 'anyone', [
            ['character_name' => 'Keep', 'world' => 'Zurvan'],
            ['character_name' => 'Drop', 'world' => 'Zurvan'],
        ]);
        // Re-sync with only one character: the other is unpublished.
        $this->service->register($recipient['client_id'], 'anyone', self::ident('Keep', 'Zurvan'));

        $this->service->send($sender['client_id'], 'S', 'S', 'Zurvan', 'Drop', 'Zurvan', 'X');
        $this->assertCount(0, $this->service->drainInbox($recipient['client_id']));

        $this->service->send($sender['client_id'], 'S', 'S', 'Zurvan', 'Keep', 'Zurvan', 'Y');
        $this->assertCount(1, $this->service->drainInbox($recipient['client_id']));
    }

    public function testIdentityMatchIsCaseAndSpaceInsensitive(): void
    {
        $recipient = $this->service->issueClient(null);
        $sender    = $this->service->issueClient(null);
        $this->service->register($recipient['client_id'], 'anyone', self::ident('Alisaie Leveilleur', 'Zurvan'));

        // A sender's differently-cased/spaced target still resolves to the same hash.
        $this->service->send($sender['client_id'], 'S', 'S', 'Zurvan', '  alisaie leveilleur ', 'ZURVAN', 'X');
        $this->assertCount(1, $this->service->drainInbox($recipient['client_id']));
    }

    public function testEmptyIdentityListClearsAddressing(): void
    {
        $recipient = $this->service->issueClient(null);
        $sender    = $this->service->issueClient(null);
        $this->service->register($recipient['client_id'], 'anyone', self::ident('Somebody', 'Zurvan'));

        // An empty list unpublishes everything.
        $this->service->register($recipient['client_id'], null, []);

        $this->service->send($sender['client_id'], 'S', 'S', 'Zurvan', 'Somebody', 'Zurvan', 'X');
        $this->assertCount(0, $this->service->drainInbox($recipient['client_id']));
    }

    public function testNullIdentitiesLeavesSetUntouched(): void
    {
        $recipient = $this->service->issueClient(null);
        $sender    = $this->service->issueClient(null);
        $this->service->register($recipient['client_id'], 'anyone', self::ident('Stay', 'Zurvan'));

        // Registering with null identities (e.g. just a tier change) keeps the set.
        $this->service->register($recipient['client_id'], 'party', null);

        $this->service->send($sender['client_id'], 'S', 'S', 'Zurvan', 'Stay', 'Zurvan', 'X');
        $this->assertCount(1, $this->service->drainInbox($recipient['client_id']));
    }

    public function testInvalidAcceptTierIsIgnored(): void
    {
        $client = $this->service->issueClient(null);

        $view = $this->service->register($client['client_id'], 'everyone-lol', self::ident('A', 'B'));

        $this->assertNotNull($view);
        $this->assertSame('party_or_friends', $view->accept_tier);
    }

    public function testSendQueuesToRegisteredRecipientThenDrainsOnce(): void
    {
        $sender    = $this->service->issueClient(null);
        $recipient = $this->service->issueClient(null);
        $this->service->register($recipient['client_id'], 'anyone', self::ident('Tataru', 'Zurvan'));

        $status = $this->service->send(
            $sender['client_id'],
            'Alphinaud',
            'Alphinaud',
            'Zurvan',
            'Tataru',
            'Zurvan',
            'ABC123'
        );
        $this->assertSame('sent', $status);

        $drained = $this->service->drainInbox($recipient['client_id']);
        $this->assertCount(1, $drained);
        $this->assertSame('Alphinaud', $drained[0]->sender_label);
        $this->assertSame('Alphinaud', $drained[0]->sender_character);
        $this->assertSame('Zurvan', $drained[0]->sender_world);
        $this->assertSame('ABC123', $drained[0]->payload);
        $this->assertSame($sender['client_id'], $drained[0]->sender_client_id);

        // A second drain yields nothing (each share is delivered once).
        $this->assertCount(0, $this->service->drainInbox($recipient['client_id']));
    }

    public function testInvalidRequestReportsInvalid(): void
    {
        $sender = $this->service->issueClient(null);

        $this->assertSame(
            'invalid',
            $this->service->send($sender['client_id'], 'S', 'S', 'Zurvan', '', '', '')
        );
    }

    public function testSendToUnknownIdentityIsUniformlyAccepted(): void
    {
        $sender = $this->service->issueClient(null);

        // No such recipient, yet the response is the same 'sent' — no presence oracle.
        $this->assertSame(
            'sent',
            $this->service->send($sender['client_id'], 'Me', 'Me', 'Zurvan', 'Nobody', 'Nowhere', 'X')
        );
    }

    public function testSendToSelfIsNotDelivered(): void
    {
        $client = $this->service->issueClient(null);
        $this->service->register($client['client_id'], 'anyone', self::ident('Estinien', 'Zurvan'));

        $this->assertSame(
            'sent',
            $this->service->send($client['client_id'], 'Estinien', 'Estinien', 'Zurvan', 'Estinien', 'Zurvan', 'X')
        );
        $this->assertCount(0, $this->service->drainInbox($client['client_id']));
    }

    public function testNobodyTierIsNotDelivered(): void
    {
        $sender    = $this->service->issueClient(null);
        $recipient = $this->service->issueClient(null);
        $this->service->register($recipient['client_id'], 'nobody', self::ident('Urianger', 'Zurvan'));

        $this->assertSame(
            'sent',
            $this->service->send($sender['client_id'], 'S', 'Sender', 'Zurvan', 'Urianger', 'Zurvan', 'X')
        );
        $this->assertCount(0, $this->service->drainInbox($recipient['client_id']));
    }

    public function testBlockedSenderIsNotDelivered(): void
    {
        $sender    = $this->service->issueClient(null);
        $recipient = $this->service->issueClient(null);
        $this->service->register($recipient['client_id'], 'anyone', self::ident('Thancred', 'Zurvan'));
        $this->service->block($recipient['client_id'], $sender['client_id']);

        // Uniform 'sent', but nothing is delivered — a block can't be probed.
        $this->assertSame(
            'sent',
            $this->service->send($sender['client_id'], 'S', 'Sender', 'Zurvan', 'Thancred', 'Zurvan', 'X')
        );
        $this->assertCount(0, $this->service->drainInbox($recipient['client_id']));

        // Unblocking restores delivery.
        $this->service->unblock($recipient['client_id'], $sender['client_id']);
        $this->service->send($sender['client_id'], 'S', 'Sender', 'Zurvan', 'Thancred', 'Zurvan', 'X');
        $this->assertCount(1, $this->service->drainInbox($recipient['client_id']));
    }

    public function testFanOutBeyondCapIsSilentlyDropped(): void
    {
        $sender = $this->service->issueClient(null);

        // 15 distinct recipients are delivered; the 16th is silently dropped.
        for ($i = 1; $i <= 15; $i++) {
            $recipient = $this->service->issueClient(null);
            $this->service->register($recipient['client_id'], 'anyone', self::ident("Char{$i}", 'Zurvan'));
            $this->assertSame(
                'sent',
                $this->service->send($sender['client_id'], 'S', 'Sender', 'Zurvan', "Char{$i}", 'Zurvan', 'X')
            );
            $this->assertCount(1, $this->service->drainInbox($recipient['client_id']));
        }

        $extra = $this->service->issueClient(null);
        $this->service->register($extra['client_id'], 'anyone', self::ident('Char16', 'Zurvan'));
        $this->assertSame(
            'sent',
            $this->service->send($sender['client_id'], 'S', 'Sender', 'Zurvan', 'Char16', 'Zurvan', 'X')
        );
        $this->assertCount(0, $this->service->drainInbox($extra['client_id']));
    }

    public function testExpiredMessagesAreNotDelivered(): void
    {
        $recipient = $this->service->issueClient(null);

        // Insert a message that already expired; the drain must skip (and sweep) it.
        $this->pdo->prepare(
            "INSERT INTO plugin_messages
                (recipient_client_id, sender_client_id, sender_label, type, payload, created_at, expires_at)
             VALUES (:rid, 1, 'Ghost', 'reading_share', 'OLD', :created, :expired)"
        )->execute([
            ':rid'     => $recipient['client_id'],
            ':created' => date('Y-m-d H:i:s', time() - 600),
            ':expired' => date('Y-m-d H:i:s', time() - 60),
        ]);

        $this->assertCount(0, $this->service->drainInbox($recipient['client_id']));
    }

    public function testIdentityCountIsCappedPerClient(): void
    {
        $recipient = $this->service->issueClient(null);
        $sender    = $this->service->issueClient(null);

        // Ask to publish 41 identities; only the first 40 (the cap) are kept — a
        // guard against a client squatting on / bloating the identity table.
        $idents = [];
        for ($i = 1; $i <= 41; $i++) {
            $idents[] = ['character_name' => "Char{$i}", 'world' => 'Zurvan'];
        }
        $this->service->register($recipient['client_id'], 'anyone', $idents);

        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM plugin_client_identities')->fetchColumn();
        $this->assertSame(40, $count);

        // A character within the cap is addressable; the 41st was dropped.
        $this->service->send($sender['client_id'], 'S', 'S', 'Zurvan', 'Char1', 'Zurvan', 'A');
        $this->service->send($sender['client_id'], 'S', 'S', 'Zurvan', 'Char41', 'Zurvan', 'B');
        $this->assertCount(1, $this->service->drainInbox($recipient['client_id']));
    }

    public function testRoutingPrefersTheMostRecentlyPresentClient(): void
    {
        $sender   = $this->service->issueClient(null);
        $installA = $this->service->issueClient(null);
        $installB = $this->service->issueClient(null);

        // Both installs publish the SAME character (e.g. a reinstall/relink left the
        // old client row active, since a client is never revoked).
        $this->service->register($installA['client_id'], 'anyone', self::ident('Shared', 'Zurvan'));
        $this->service->register($installB['client_id'], 'anyone', self::ident('Shared', 'Zurvan'));

        // Make install A the one that has polled most recently (freshest presence),
        // even though it has the LOWER client_id (so client_id DESC would pick B).
        $this->pdo->prepare('UPDATE plugin_clients SET last_seen = :ts WHERE client_id = :id')
            ->execute([':ts' => date('Y-m-d H:i:s', time()), ':id' => $installA['client_id']]);
        $this->pdo->prepare('UPDATE plugin_clients SET last_seen = :ts WHERE client_id = :id')
            ->execute([':ts' => date('Y-m-d H:i:s', time() - 120), ':id' => $installB['client_id']]);

        $this->service->send($sender['client_id'], 'S', 'S', 'Zurvan', 'Shared', 'Zurvan', 'X');

        // The online install (A) receives it; the stale one (B) does not.
        $this->assertCount(1, $this->service->drainInbox($installA['client_id']));
        $this->assertCount(0, $this->service->drainInbox($installB['client_id']));
    }
}
