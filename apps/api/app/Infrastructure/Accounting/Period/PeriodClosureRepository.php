<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Period;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Period\PeriodClosingService;
use App\Domain\Accounting\Period\PeriodClosure;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Transactions\Expense\ExpenseRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for Period closures (AETS-014), through the
 * production `period_closures` table — an append-only history of every
 * closing this Tenant has ever performed, never updated or deleted.
 *
 * **Transaction participation.** {@see record()} opens no transaction
 * of its own, mirroring every other repository on the Posting
 * transaction path (e.g. {@see ExpenseRepository}) —
 * it participates in whatever transaction {@see PeriodClosingService}
 * already has open.
 */
final class PeriodClosureRepository
{
    private const TABLE = 'period_closures';

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function record(PeriodClosure $closure): void
    {
        $this->connection->table(self::TABLE)->insert([
            'tenant_id' => $closure->tenantId()->toString(),
            'closed_through_date' => $closure->closedThroughDate()->format('Y-m-d'),
            'closing_journal_id' => $closure->closingJournalId()->toString(),
            'closed_at' => $closure->closedAt()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The Tenant's current closed-period watermark — the most recent
     * (by `closed_through_date`) closure on record, or `null` if this
     * Tenant has never closed a Period. Every ordinary Posting Command
     * must carry a Financial Date strictly after this.
     */
    public function findLatestForTenant(TenantId $tenantId): ?PeriodClosure
    {
        /** @var object{tenant_id: string, closed_through_date: string, closing_journal_id: string, closed_at: string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->orderBy('closed_through_date', 'desc')
            ->first();

        if ($row === null) {
            return null;
        }

        return new PeriodClosure(
            TenantId::of($row->tenant_id),
            new \DateTimeImmutable($row->closed_through_date),
            JournalId::of($row->closing_journal_id),
            new \DateTimeImmutable($row->closed_at),
        );
    }

    /**
     * The most recent closure strictly *before* `$exclusiveDate` — used
     * to compute the correct lower bound when aggregating Revenue/
     * Expense balances for a closing attempt: aggregating from account
     * inception, unconditionally, would on a *replay* of an
     * already-closed date also aggregate that same closing Journal's
     * own zeroing lines (dated exactly at that date), making every
     * Account appear to net to zero and incorrectly reporting nothing
     * to close. Aggregating only *since* the previous closure (this
     * method) instead reconstructs the exact same balances the
     * original closing computed, both on first attempt (no previous
     * closure) and on replay.
     */
    public function findLatestBefore(TenantId $tenantId, \DateTimeImmutable $exclusiveDate): ?PeriodClosure
    {
        /** @var object{tenant_id: string, closed_through_date: string, closing_journal_id: string, closed_at: string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('closed_through_date', '<', $exclusiveDate->format('Y-m-d'))
            ->orderBy('closed_through_date', 'desc')
            ->first();

        if ($row === null) {
            return null;
        }

        return new PeriodClosure(
            TenantId::of($row->tenant_id),
            new \DateTimeImmutable($row->closed_through_date),
            JournalId::of($row->closing_journal_id),
            new \DateTimeImmutable($row->closed_at),
        );
    }

    /**
     * The closure record for an exact `closed_through_date`, if one
     * exists — used to resolve an idempotent replay of a Period-closing
     * request back to the closure it already produced.
     */
    public function findForTenantAndDate(TenantId $tenantId, \DateTimeImmutable $closedThroughDate): ?PeriodClosure
    {
        /** @var object{tenant_id: string, closed_through_date: string, closing_journal_id: string, closed_at: string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('closed_through_date', $closedThroughDate->format('Y-m-d'))
            ->first();

        if ($row === null) {
            return null;
        }

        return new PeriodClosure(
            TenantId::of($row->tenant_id),
            new \DateTimeImmutable($row->closed_through_date),
            JournalId::of($row->closing_journal_id),
            new \DateTimeImmutable($row->closed_at),
        );
    }
}
