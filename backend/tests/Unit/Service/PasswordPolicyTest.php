<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Service\PasswordPolicy;

#[CoversClass(PasswordPolicy::class)]
final class PasswordPolicyTest extends TestCase
{
    public function testAcceptsALongPassphrase(): void
    {
        $this->assertNull(PasswordPolicy::validate('correct horse battery staple'));
    }

    public function testRejectsTooShort(): void
    {
        $this->assertNotNull(PasswordPolicy::validate('short1'));
    }

    public function testRejectsTooLong(): void
    {
        $this->assertNotNull(PasswordPolicy::validate(str_repeat('a', PasswordPolicy::MAX_LENGTH + 1)));
    }

    public function testRejectsSingleRepeatedCharacter(): void
    {
        $this->assertNotNull(PasswordPolicy::validate(str_repeat('a', PasswordPolicy::MIN_LENGTH + 2)));
    }

    public function testRejectsCommonPassword(): void
    {
        $this->assertNotNull(PasswordPolicy::validate('password1234'));
    }

    public function testExactMinimumLengthIsAccepted(): void
    {
        // 12 distinct-ish characters, not on the blocklist.
        $this->assertNull(PasswordPolicy::validate('Tr0ub4dor&3x'));
    }
}
