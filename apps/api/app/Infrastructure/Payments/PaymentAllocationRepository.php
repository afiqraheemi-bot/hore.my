<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Payments\AllocationService;
use App\Domain\Payments\PaymentAllocation;
use App\Domain\Payments\PaymentAllocationId;
use App\Domain\Payments\PaymentId;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the PaymentAllocation link (M21),
 * through the production `payment_allocations` table.
 *
 * **{@see sumForPayment()}/{@see sumForInvoice()}** back the two
 * cross-row invariants {@see AllocationService}
 * enforces — a `SUM(amount)` aggregate query, never a cached/stored
 * running total, mirroring "Baki authoritative datang daripada lejar"
 * (Master Context §10): an allocation total is always computed fresh
 * from the actual rows, never trusted from a separately-maintained
 * counter that could drift.
 */
final class PaymentAllocationRepository
{
    private const TABLE = 'payment_allocations';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    public function save(PaymentAllocation $allocation): void
    {
        $this->connection->table(self::TABLE)->insert([
            'id' => $allocation->id()->toString(),
            'tenant_id' => $allocation->tenantId()->toString(),
            'payment_id' => $allocation->paymentId()->toString(),
            'invoice_id' => $allocation->invoiceId()->toString(),
            'amount' => $this->money->toPersistedAmount($allocation->amount()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function delete(TenantId $tenantId, PaymentAllocationId $allocationId): void
    {
        $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $allocationId->toString())
            ->delete();
    }

    public function findById(TenantId $tenantId, PaymentAllocationId $allocationId): ?PaymentAllocation
    {
        /** @var object{id: string, tenant_id: string, payment_id: string, invoice_id: string, amount: int|string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $allocationId->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    /**
     * @return list<PaymentAllocation>
     */
    public function findByPayment(TenantId $tenantId, PaymentId $paymentId): array
    {
        /** @var list<object{id: string, tenant_id: string, payment_id: string, invoice_id: string, amount: int|string}> $rows */
        $rows = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('payment_id', $paymentId->toString())
            ->get()
            ->all();

        return array_map(fn (object $row): PaymentAllocation => $this->fromPersisted($row), $rows);
    }

    /**
     * @return list<PaymentAllocation>
     */
    public function findByInvoice(TenantId $tenantId, InvoiceId $invoiceId): array
    {
        /** @var list<object{id: string, tenant_id: string, payment_id: string, invoice_id: string, amount: int|string}> $rows */
        $rows = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('invoice_id', $invoiceId->toString())
            ->get()
            ->all();

        return array_map(fn (object $row): PaymentAllocation => $this->fromPersisted($row), $rows);
    }

    public function sumForPayment(TenantId $tenantId, PaymentId $paymentId, Currency $currency): Money
    {
        $sum = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('payment_id', $paymentId->toString())
            ->sum('amount');

        return Money::fromMinorUnits(MinorUnits::of((string) $sum), $currency);
    }

    public function sumForInvoice(TenantId $tenantId, InvoiceId $invoiceId, Currency $currency): Money
    {
        $sum = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('invoice_id', $invoiceId->toString())
            ->sum('amount');

        return Money::fromMinorUnits(MinorUnits::of((string) $sum), $currency);
    }

    /**
     * @param  object{id: string, tenant_id: string, payment_id: string, invoice_id: string, amount: int|string}  $row
     */
    private function fromPersisted(object $row): PaymentAllocation
    {
        // An allocation's own currency is never stored — it always
        // matches its Payment's currency (MYR-only throughout this
        // codebase today), so no separate currency column exists to
        // read here.
        $currency = Currency::of('MYR');

        return PaymentAllocation::reconstitute(
            PaymentAllocationId::of($row->id),
            TenantId::of($row->tenant_id),
            PaymentId::of($row->payment_id),
            InvoiceId::of($row->invoice_id),
            $this->money->fromPersisted((string) $row->amount, $currency->identifier()),
        );
    }
}
