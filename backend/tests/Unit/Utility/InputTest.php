<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tarot\Utility\Input;

/**
 * Locks in the coercion rules for untrusted request input: the stringy-boolean
 * handling and the trim + multibyte length cap used across the API layer.
 */
#[CoversClass(Input::class)]
final class InputTest extends TestCase
{
    /**
     * @return array<string,array{mixed,bool}>
     */
    public static function boolCases(): array
    {
        return [
            'true bool'      => [true, true],
            'false bool'     => [false, false],
            'string true'    => ['true', true],
            'string false'   => ['false', false],
            'string one'     => ['1', true],
            'string zero'    => ['0', false],
            'on'             => ['on', true],
            'off'            => ['off', false],
            'empty string'   => ['', false],
            'int one'        => [1, true],
            'int zero'       => [0, false],
        ];
    }

    #[DataProvider('boolCases')]
    public function testBoolCoercesLikeFilterVar(mixed $value, bool $expected): void
    {
        self::assertSame($expected, Input::bool($value));
    }

    public function testBoolMissingValueUsesDefault(): void
    {
        self::assertFalse(Input::bool(null));
        self::assertTrue(Input::bool(null, true));
    }

    public function testStringTrimsAndCasts(): void
    {
        self::assertSame('hello', Input::string('  hello  ', 100));
        self::assertSame('42', Input::string(42, 100));
        self::assertSame('', Input::string(null, 100));
    }

    public function testStringCapsToMaxLengthMultibyteSafe(): void
    {
        self::assertSame('abcde', Input::string('abcdefgh', 5));
        // Caps by characters, not bytes.
        self::assertSame('héllo', Input::string('héllo wörld', 5));
    }

    public function testStringCanSkipTrim(): void
    {
        self::assertSame('  hi  ', Input::string('  hi  ', 100, trim: false));
    }

    public function testNullableStringReturnsNullWhenEmptyAfterTrim(): void
    {
        self::assertNull(Input::nullableString('   ', 100));
        self::assertNull(Input::nullableString(null, 100));
        self::assertNull(Input::nullableString('', 100));
    }

    public function testNullableStringPreservesUntrimmedValueWhenTrimDisabled(): void
    {
        // Whitespace-only is still null, but a real value keeps its inner spacing.
        self::assertNull(Input::nullableString('   ', 100, trim: false));
        self::assertSame(' keep me ', Input::nullableString(' keep me ', 100, trim: false));
    }
}
