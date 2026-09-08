<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
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
 *
 * **Soft-deleted (P1-3, 2026-09-08 audit remediation).** Every read
 * method below filters `deleted_at IS NULL` — a deallocated row is
 * never physically removed ({@see softDelete()}), so it never
 * contributes to a sum or a listing, but it survives as its own audit
 * trail (who deallocated it, and when) instead of vanishing without a
 * trace.
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

    /**
     * Marks an allocation deleted without physically removing it
     * (P1-3): `deleted_at`/`deleted_by_actor` are set, and every read
     * method below excludes it from that point on, but the row itself
     * — amount, Payment, Invoice, and now who deallocated it and when
     * — is retained.
     *
     * **Race-safe by construction, not by an application-level
     * check-then-act (P1-3 follow-up, 2026-09-11: an external audit
     * found the original version of this method had no such
     * guarantee).** The `deleted_at IS NULL` predicate is part of the
     * `UPDATE` statement itself, not a separate prior `SELECT` —
     * PostgreSQL evaluates an `UPDATE ... WHERE` predicate against the
     * row's current committed state and serializes concurrent writers
     * to the same row, so of two concurrent callers racing to
     * deallocate the same allocation, only the one that actually
     * transitions `deleted_at` from `NULL` can ever match this
     * predicate; the other's `UPDATE` matches zero rows once the first
     * commits. This is the standard atomic compare-and-swap pattern
     * for a conditional update — no explicit `SELECT ... FOR UPDATE`
     * is needed, unlike {@see AllocationService::allocate()}'s own
     * locking (a genuinely different shape of race: that one reads a
     * cross-row `SUM()` before deciding, which an `UPDATE ... WHERE`
     * predicate cannot express).
     *
     * @return bool `true` if this call actually transitioned the row
     *              from active to deleted; `false` if it had already been
     *              deleted (by this call or a concurrent one) by the time this
     *              statement ran — the caller MUST treat `false` as "lost the
     *              race, do not report success," never as a silent no-op,
     *              exactly as it would treat a `findById()` miss.
     */
    public function softDelete(TenantId $tenantId, PaymentAllocationId $allocationId, ActorReference $actor): bool
    {
        $affected = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $allocationId->toString())
            ->whereNull('deleted_at')
            ->update([
                'deleted_at' => now(),
                'deleted_by_actor' => $actor->toString(),
                'updated_at' => now(),
            ]);

        return $affected > 0;
    }

    public function findById(TenantId $tenantId, PaymentAllocationId $allocationId): ?PaymentAllocation
    {
        /** @var object{id: string, tenant_id: string, payment_id: string, invoice_id: string, amount: int|string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $allocationId->toString())
            ->whereNull('deleted_at')
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
            ->whereNull('deleted_at')
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
            ->whereNull('deleted_at')
            ->get()
            ->all();

        return array_map(fn (object $row): PaymentAllocation => $this->fromPersisted($row), $rows);
    }

    public function sumForPayment(TenantId $tenantId, PaymentId $paymentId, Currency $currency): Money
    {
        $sum = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('payment_id', $paymentId->toString())
            ->whereNull('deleted_at')
            ->sum('amount');

        return Money::fromMinorUnits(MinorUnits::of((string) $sum), $currency);
    }

    public function sumForInvoice(TenantId $tenantId, InvoiceId $invoiceId, Currency $currency): Money
    {
        $sum = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('invoice_id', $invoiceId->toString())
            ->whereNull('deleted_at')
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
