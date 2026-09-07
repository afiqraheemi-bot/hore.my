<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Banking;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\Exception\InvalidBankAccountIdException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Covers BankAccount identity (M17) as a bare Value Object, mirroring
 * every other opaque identifier's own test coverage in this codebase.
 */
final class BankAccountIdTest extends TestCase
{
    public function test_exact_string_round_trip(): void
    {
        $id = BankAccountId::of('bank-account-0001');

        $this->assertSame('bank-account-0001', $id->toString());
    }

    public function test_same_value_is_equal(): void
    {
        $this->assertTrue(BankAccountId::of('x')->equals(BankAccountId::of('x')));
    }

    public function test_different_value_is_not_equal(): void
    {
        $this->assertFalse(BankAccountId::of('x')->equals(BankAccountId::of('y')));
    }

    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidBankAccountIdException::class);

        BankAccountId::of('');
    }

    public function test_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidBankAccountIdException::class);

        BankAccountId::of(' x ');
    }

    public function test_control_character_is_rejected(): void
    {
        $this->expectException(InvalidBankAccountIdException::class);

        BankAccountId::of("x\0");
    }

    public function test_adversarially_long_input_is_rejected(): void
    {
        $this->expectException(InvalidBankAccountIdException::class);

        BankAccountId::of(str_repeat('a', 65));
    }

    public function test_bank_account_id_is_immutable(): void
    {
        $reflection = new ReflectionClass(BankAccountId::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly());
        }
    }

    public function test_is_a_distinct_type_from_journal_id(): void
    {
        $bankAccountId = BankAccountId::of('shared-value');
        $journalId = JournalId::of('shared-value');

        $this->assertNotInstanceOf(JournalId::class, $bankAccountId);
        $this->assertNotInstanceOf(BankAccountId::class, $journalId);
    }

    public function test_exposes_no_generation_method(): void
    {
        $reflection = new ReflectionClass(BankAccountId::class);

        $publicMethodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(['of', 'toString', 'equals'], $publicMethodNames);
    }
}
