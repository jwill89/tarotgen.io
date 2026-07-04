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
 * client-token issuance/resolution, identity registration + consent, addressed
 * delivery with the consent/block/throttle guards, and once-only inbox drain.
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

        $view = $this->service->register($recipient['client_id'], 'Y\'shtola Rhul', 'Zurvan', 'anyone');
        $this->assertNotNull($view);
        $this->assertSame('anyone', $view->accept_tier);

        // The identity is resolvable: a share addressed to it is delivered.
        $this->service->send($sender['client_id'], 'S', 'S', 'Zurvan', 'Y\'shtola Rhul', 'Zurvan', 'CODE');
        $this->assertCount(1, $this->service->drainInbox($recipient['client_id']));
    }

    public function testIdentityMatchIsCaseAndSpaceInsensitive(): void
    {
        $recipient = $this->service->issueClient(null);
        $sender    = $this->service->issueClient(null);
        $this->service->register($recipient['client_id'], 'Alisaie Leveilleur', 'Zurvan', 'anyone');

        // A sender's differently-cased/spaced target still resolves to the same hash.
        $this->service->send($sender['client_id'], 'S', 'S', 'Zurvan', '  alisaie leveilleur ', 'ZURVAN', 'X');
        $this->assertCount(1, $this->service->drainInbox($recipient['client_id']));
    }

    public function testRegisterWithBlankIdentityClearsAddressing(): void
    {
        $recipient = $this->service->issueClient(null);
        $sender    = $this->service->issueClient(null);
        $this->service->register($recipient['client_id'], 'Somebody', 'Zurvan', 'anyone');

        $view = $this->service->register($recipient['client_id'], '', '', null);
        $this->assertNotNull($view);

        // With the identity cleared, a share to the old name is silently dropped.
        $this->service->send($sender['client_id'], 'S', 'S', 'Zurvan', 'Somebody', 'Zurvan', 'X');
        $this->assertCount(0, $this->service->drainInbox($recipient['client_id']));
    }

    public function testInvalidAcceptTierIsIgnored(): void
    {
        $client = $this->service->issueClient(null);

        $view = $this->service->register($client['client_id'], 'A', 'B', 'everyone-lol');

        $this->assertNotNull($view);
        $this->assertSame('party_or_friends', $view->accept_tier);
    }

    public function testSendQueuesToRegisteredRecipientThenDrainsOnce(): void
    {
        $sender    = $this->service->issueClient(null);
        $recipient = $this->service->issueClient(null);
        $this->service->register($recipient['client_id'], 'Tataru', 'Zurvan', 'anyone');

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
        $this->service->register($client['client_id'], 'Estinien', 'Zurvan', 'anyone');

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
        $this->service->register($recipient['client_id'], 'Urianger', 'Zurvan', 'nobody');

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
        $this->service->register($recipient['client_id'], 'Thancred', 'Zurvan', 'anyone');
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
            $this->service->register($recipient['client_id'], "Char{$i}", 'Zurvan', 'anyone');
            $this->assertSame(
                'sent',
                $this->service->send($sender['client_id'], 'S', 'Sender', 'Zurvan', "Char{$i}", 'Zurvan', 'X')
            );
            $this->assertCount(1, $this->service->drainInbox($recipient['client_id']));
        }

        $extra = $this->service->issueClient(null);
        $this->service->register($extra['client_id'], 'Char16', 'Zurvan', 'anyone');
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
}
