<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Payments;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Customers\CustomerId;
use App\Domain\Payments\Payment;
use App\Domain\Payments\PaymentId;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;

final class PaymentTest extends TestCase
{
    public function test_records_a_valid_payment(): void
    {
        $payment = Payment::record(
            PaymentId::of('payment-0001'),
            TenantId::of('tenant-0001'),
            JournalId::of('journal-0001'),
            CustomerId::of('customer-0001'),
            Money::fromDecimalString('500.00', Currency::of('MYR')),
            new \DateTimeImmutable('2026-09-08'),
            AccountId::of('account-bank'),
            AccountId::of('account-receivable'),
            'REF-001',
        );

        $this->assertSame('500.00', $payment->amount()->toDecimalString());
        $this->assertSame('REF-001', $payment->reference());
    }

    public function test_records_with_a_null_reference(): void
    {
        $payment = Payment::record(
            PaymentId::of('payment-0001'),
            TenantId::of('tenant-0001'),
            JournalId::of('journal-0001'),
            CustomerId::of('customer-0001'),
            Money::fromDecimalString('500.00', Currency::of('MYR')),
            new \DateTimeImmutable('2026-09-08'),
            AccountId::of('account-bank'),
            AccountId::of('account-receivable'),
            null,
        );

        $this->assertNull($payment->reference());
    }

    public function test_rejects_a_reference_exceeding_the_max_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Payment::record(
            PaymentId::of('payment-0001'),
            TenantId::of('tenant-0001'),
            JournalId::of('journal-0001'),
            CustomerId::of('customer-0001'),
            Money::fromDecimalString('500.00', Currency::of('MYR')),
            new \DateTimeImmutable('2026-09-08'),
            AccountId::of('account-bank'),
            AccountId::of('account-receivable'),
            str_repeat('a', 256),
        );
    }

    public function test_equals_compares_by_identifier(): void
    {
        $a = Payment::record(PaymentId::of('payment-0001'), TenantId::of('tenant-0001'), JournalId::of('journal-0001'), CustomerId::of('customer-0001'), Money::fromDecimalString('1.00', Currency::of('MYR')), new \DateTimeImmutable('2026-09-08'), AccountId::of('a'), AccountId::of('b'), null);
        $b = Payment::record(PaymentId::of('payment-0001'), TenantId::of('tenant-9999'), JournalId::of('journal-9999'), CustomerId::of('customer-9999'), Money::fromDecimalString('2.00', Currency::of('MYR')), new \DateTimeImmutable('2026-01-01'), AccountId::of('c'), AccountId::of('d'), null);
        $c = Payment::record(PaymentId::of('payment-0002'), TenantId::of('tenant-0001'), JournalId::of('journal-0001'), CustomerId::of('customer-0001'), Money::fromDecimalString('1.00', Currency::of('MYR')), new \DateTimeImmutable('2026-09-08'), AccountId::of('a'), AccountId::of('b'), null);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
