<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Structure\AbstractStructure;
use Tarot\Structure\Contact;
use Tarot\Structure\Deck;
use Tarot\Structure\Reading;
use Tarot\Structure\Spread;

#[CoversClass(AbstractStructure::class)]
#[CoversClass(Contact::class)]
#[CoversClass(Deck::class)]
#[CoversClass(Spread::class)]
#[CoversClass(Reading::class)]
final class StructureTest extends TestCase
{
    public function testConstructorPopulatesKnownProperties(): void
    {
        $deck = new Deck([
            'deck_id'            => 7,
            'name'               => 'Thoth',
            'deck_system_id'     => 2,
            'system_total_cards' => 78,
            'additional_cards'   => 3,
        ]);

        $this->assertSame(7, $deck->getDeckId());
        $this->assertSame('Thoth', $deck->getName());
        $this->assertSame(2, $deck->getDeckSystemId());
        $this->assertSame(78, $deck->getEffectiveTotalCards());
        $this->assertSame(3, $deck->getAdditionalCards());
    }

    public function testUnknownPropertiesAreIgnored(): void
    {
        $deck = new Deck([
            'deck_id'        => 3,
            'does_not_exist' => 'ignored',
        ]);

        $this->assertSame(3, $deck->getDeckId());
        $this->assertObjectNotHasProperty('does_not_exist', $deck);
    }

    public function testDeckDefaults(): void
    {
        $deck = new Deck();

        $this->assertSame(0, $deck->getDeckId());
        $this->assertSame(0, $deck->getAdditionalCards());
        // No system on a bare deck → effective total falls back to a standard 78.
        $this->assertSame(78, $deck->getEffectiveTotalCards());
    }

    public function testJsonSerializeReturnsEveryDeclaredProperty(): void
    {
        $spread = new Spread([
            'spread_id'   => 2,
            'name'        => 'Three Card',
            'description' => 'Past/Present/Future',
            'card_count'  => 3,
            'positions'   => [['order' => 1]],
        ]);

        $data = $spread->jsonSerialize();

        $this->assertSame(
            ['spread_id', 'name', 'description', 'card_count', 'positions'],
            array_keys($data)
        );
        $this->assertSame(2, $data['spread_id']);
        $this->assertSame('Three Card', $data['name']);
        $this->assertSame([['order' => 1]], $data['positions']);
    }

    public function testJsonEncodeRoundTrip(): void
    {
        $spread = new Spread(['spread_id' => 1, 'name' => 'Single']);

        $encoded = json_encode($spread, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true);

        $this->assertSame(1, $decoded['spread_id']);
        $this->assertSame('Single', $decoded['name']);
        $this->assertArrayHasKey('positions', $decoded);
    }


    public function testReadingSettersAndGetters(): void
    {
        $reading = new Reading();
        $reading->setReadingId('abc123');
        $reading->setReadingInfo('{"draw":[]}');
        $reading->setReadingTime('2026-05-29 12:00:00');

        $this->assertSame('abc123', $reading->getReadingId());
        $this->assertSame('{"draw":[]}', $reading->getReadingInfo());
        $this->assertSame('2026-05-29 12:00:00', $reading->getReadingTime());
    }

    public function testContactConstructorAndAccessors(): void
    {
        $contact = new Contact([
            'contact_id'   => 42,
            'user_id'      => 3,
            'name'         => 'Alice',
            'email'        => 'alice@example.com',
            'message'      => 'Hello world',
            'is_read'      => 1,
            'submitted_at' => '2026-06-01 10:00:00',
        ]);

        $this->assertSame(42, $contact->getContactId());
        $this->assertSame(3, $contact->getUserId());
        $this->assertSame('Alice', $contact->getName());
        $this->assertSame('alice@example.com', $contact->getEmail());
        $this->assertSame('Hello world', $contact->getMessage());
        $this->assertTrue($contact->isRead());
        $this->assertSame('2026-06-01 10:00:00', $contact->getSubmittedAt());
    }

    public function testContactDefaults(): void
    {
        $contact = new Contact();

        $this->assertSame(0, $contact->getContactId());
        $this->assertNull($contact->getUserId());
        $this->assertSame('', $contact->getName());
        $this->assertSame('', $contact->getEmail());
        $this->assertSame('', $contact->getMessage());
        $this->assertFalse($contact->isRead());
        $this->assertSame('', $contact->getSubmittedAt());
    }

    public function testContactJsonSerializeIncludesAllFields(): void
    {
        $contact = new Contact([
            'contact_id' => 1,
            'name'       => 'Bob',
            'email'      => 'bob@example.com',
            'message'    => 'Test',
        ]);

        $data = $contact->jsonSerialize();

        $this->assertArrayHasKey('contact_id', $data);
        $this->assertArrayHasKey('user_id', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('email', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('is_read', $data);
        $this->assertArrayHasKey('submitted_at', $data);
    }
}
