<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Payments;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Payments\Exception\InvalidAllocationAmountException;
use App\Domain\Payments\PaymentAllocation;
use App\Domain\Payments\PaymentAllocationId;
use App\Domain\Payments\PaymentId;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;

final class PaymentAllocationTest extends TestCase
{
    public function test_creates_a_valid_allocation(): void
    {
        $allocation = PaymentAllocation::create(
            PaymentAllocationId::of('allocation-0001'),
            TenantId::of('tenant-0001'),
            PaymentId::of('payment-0001'),
            InvoiceId::of('invoice-0001'),
            Money::fromDecimalString('100.00', Currency::of('MYR')),
        );

        $this->assertSame('100.00', $allocation->amount()->toDecimalString());
    }

    public function test_rejects_a_zero_amount(): void
    {
        $this->expectException(InvalidAllocationAmountException::class);

        PaymentAllocation::create(
            PaymentAllocationId::of('allocation-0001'),
            TenantId::of('tenant-0001'),
            PaymentId::of('payment-0001'),
            InvoiceId::of('invoice-0001'),
            Money::fromDecimalString('0.00', Currency::of('MYR')),
        );
    }

    public function test_equals_compares_by_identifier(): void
    {
        $a = PaymentAllocation::create(PaymentAllocationId::of('allocation-0001'), TenantId::of('tenant-0001'), PaymentId::of('payment-0001'), InvoiceId::of('invoice-0001'), Money::fromDecimalString('1.00', Currency::of('MYR')));
        $b = PaymentAllocation::create(PaymentAllocationId::of('allocation-0001'), TenantId::of('tenant-9999'), PaymentId::of('payment-9999'), InvoiceId::of('invoice-9999'), Money::fromDecimalString('2.00', Currency::of('MYR')));

        $this->assertTrue($a->equals($b));
    }
}
