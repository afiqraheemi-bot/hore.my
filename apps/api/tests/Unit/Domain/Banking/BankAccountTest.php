<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Banking;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\Exception\InvalidBankAccountNameException;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;

/**
 * Covers the `BankAccount` aggregate's own construction-level
 * invariants (M17).
 */
final class BankAccountTest extends TestCase
{
    public function test_registers_every_field_exactly(): void
    {
        $bankAccount = BankAccount::register(
            BankAccountId::of('bank-account-0001'),
            TenantId::of('tenant-0001'),
            AccountId::of('account-bank'),
            'Maybank',
            '1234',
        );

        $this->assertSame('Maybank', $bankAccount->bankName());
        $this->assertSame('1234', $bankAccount->accountNumberLast4());
        $this->assertTrue($bankAccount->isActive());
        $this->assertTrue($bankAccount->linkedAccountId()->equals(AccountId::of('account-bank')));
    }

    public function test_account_number_last4_is_optional(): void
    {
        $bankAccount = BankAccount::register(
            BankAccountId::of('bank-account-0001'),
            TenantId::of('tenant-0001'),
            AccountId::of('account-bank'),
            'Maybank',
            null,
        );

        $this->assertNull($bankAccount->accountNumberLast4());
    }

    public function test_empty_bank_name_is_rejected(): void
    {
        $this->expectException(InvalidBankAccountNameException::class);

        BankAccount::register(
            BankAccountId::of('bank-account-0001'),
            TenantId::of('tenant-0001'),
            AccountId::of('account-bank'),
            '',
            null,
        );
    }

    public function test_bank_name_exceeding_max_length_is_rejected(): void
    {
        $this->expectException(InvalidBankAccountNameException::class);

        BankAccount::register(
            BankAccountId::of('bank-account-0001'),
            TenantId::of('tenant-0001'),
            AccountId::of('account-bank'),
            str_repeat('a', 101),
            null,
        );
    }

    public function test_always_active_at_registration(): void
    {
        $bankAccount = BankAccount::register(
            BankAccountId::of('bank-account-0001'),
            TenantId::of('tenant-0001'),
            AccountId::of('account-bank'),
            'Maybank',
            null,
        );

        $this->assertTrue($bankAccount->isActive());
    }

    public function test_reconstitute_restores_every_field_without_revalidating(): void
    {
        $bankAccount = BankAccount::reconstitute(
            BankAccountId::of('bank-account-0001'),
            TenantId::of('tenant-0001'),
            AccountId::of('account-bank'),
            'Maybank',
            '1234',
            false,
        );

        $this->assertFalse($bankAccount->isActive());
    }

    public function test_equals_is_identity_based(): void
    {
        $a = BankAccount::register(BankAccountId::of('id-1'), TenantId::of('tenant-0001'), AccountId::of('account-bank'), 'Maybank', null);
        $b = BankAccount::register(BankAccountId::of('id-1'), TenantId::of('tenant-0001'), AccountId::of('account-bank'), 'CIMB', null);

        $this->assertTrue($a->equals($b));
    }

    public function test_no_public_mutator_exists(): void
    {
        $reflection = new \ReflectionClass(BankAccount::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertStringStartsNotWith('set', $method->getName());
        }
    }
}
