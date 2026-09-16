<?php

declare(strict_types=1);

namespace App\Infrastructure\Banking;

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\BankTransactionId;
use App\Domain\Banking\Exception\ReconciliationNotFoundException;
use App\Domain\Banking\Reconciliation;
use App\Domain\Banking\ReconciliationId;
use App\Domain\Banking\ReconciliationReopening;
use App\Domain\Banking\ReconciliationState;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * The persistence boundary for the Reconciliation aggregate and its
 * {@see ReconciliationReopening} history (M18), through the production
 * `reconciliations` and `reconciliation_reopenings` tables.
 */
final class ReconciliationRepository
{
    private const TABLE = 'reconciliations';

    private const REOPENING_TABLE = 'reconciliation_reopenings';

    private const SNAPSHOT_TABLE = 'reconciliation_completion_snapshots';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    public function record(Reconciliation $reconciliation): void
    {
        $this->connection->table(self::TABLE)->insert([
            'id' => $reconciliation->id()->toString(),
            'tenant_id' => $reconciliation->tenantId()->toString(),
            'bank_account_id' => $reconciliation->bankAccountId()->toString(),
            'period_start' => $reconciliation->periodStart()->format('Y-m-d'),
            'period_end' => $reconciliation->periodEnd()->format('Y-m-d'),
            'opening_balance' => $this->money->toPersistedAmount($reconciliation->openingBalance()),
            'closing_balance' => $this->money->toPersistedAmount($reconciliation->closingBalance()),
            'currency' => $this->money->toPersistedCurrency($reconciliation->openingBalance()),
            'state' => $reconciliation->state()->name,
            'completed_at' => $reconciliation->completedAt()?->format('Y-m-d H:i:s'),
            'created_at' => $reconciliation->createdAt()->format('Y-m-d H:i:s'),
            'updated_at' => $reconciliation->createdAt()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Persists a state-transition result — only `state` and
     * `completed_at` ever change after {@see record()}'s initial
     * insert; every other field is immutable for a Reconciliation's
     * lifetime.
     */
    public function updateState(Reconciliation $reconciliation): void
    {
        $this->connection->table(self::TABLE)
            ->where('tenant_id', $reconciliation->tenantId()->toString())
            ->where('id', $reconciliation->id()->toString())
            ->update([
                'state' => $reconciliation->state()->name,
                'completed_at' => $reconciliation->completedAt()?->format('Y-m-d H:i:s'),
                'updated_at' => now(),
            ]);
    }

    public function findById(TenantId $tenantId, ReconciliationId $id): ?Reconciliation
    {
        /** @var object{id: string, tenant_id: string, bank_account_id: string, period_start: string, period_end: string, opening_balance: int|string, closing_balance: int|string, currency: string, state: string, created_at: string, completed_at: string|null}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $id->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    /**
     * @throws ReconciliationNotFoundException if no such Reconciliation
     *                                         exists for this Tenant.
     */
    public function getById(TenantId $tenantId, ReconciliationId $id): Reconciliation
    {
        return $this->findById($tenantId, $id) ?? throw ReconciliationNotFoundException::forId($id);
    }

    /**
     * Reloads and locks one tenant-scoped Reconciliation for the
     * remainder of the caller's transaction. Lifecycle services use
     * this boundary so concurrent transitions cannot both act on the
     * same stale state.
     *
     * @throws ReconciliationNotFoundException if no such Reconciliation
     *                                         exists for this Tenant.
     */
    public function getByIdForUpdate(TenantId $tenantId, ReconciliationId $id): Reconciliation
    {
        /** @var object{id: string, tenant_id: string, bank_account_id: string, period_start: string, period_end: string, opening_balance: int|string, closing_balance: int|string, currency: string, state: string, created_at: string, completed_at: string|null}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $id->toString())
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw ReconciliationNotFoundException::forId($id);
        }

        return $this->fromPersisted($row);
    }

    /**
     * @return list<Reconciliation>
     */
    public function findByBankAccount(TenantId $tenantId, BankAccountId $bankAccountId): array
    {
        /** @var list<object{id: string, tenant_id: string, bank_account_id: string, period_start: string, period_end: string, opening_balance: int|string, closing_balance: int|string, currency: string, state: string, created_at: string, completed_at: string|null}> $rows */
        $rows = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('bank_account_id', $bankAccountId->toString())
            ->orderByDesc('period_start')
            ->get()
            ->all();

        return array_map(fn (object $row): Reconciliation => $this->fromPersisted($row), $rows);
    }

    public function recordReopening(ReconciliationReopening $reopening): void
    {
        $this->connection->table(self::REOPENING_TABLE)->insert([
            'id' => $reopening->id(),
            'tenant_id' => $reopening->tenantId()->toString(),
            'reconciliation_id' => $reopening->reconciliationId()->toString(),
            'reason' => $reopening->reason(),
            'reopened_by' => $reopening->reopenedBy()->toString(),
            'reopened_at' => $reopening->reopenedAt()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<ReconciliationReopening>
     */
    public function findReopeningsFor(TenantId $tenantId, ReconciliationId $reconciliationId): array
    {
        /** @var list<object{id: string, tenant_id: string, reconciliation_id: string, reason: string, reopened_by: string, reopened_at: string}> $rows */
        $rows = $this->connection->table(self::REOPENING_TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('reconciliation_id', $reconciliationId->toString())
            ->orderBy('reopened_at')
            ->get()
            ->all();

        return array_map(static fn (object $row): ReconciliationReopening => new ReconciliationReopening(
            $row->id,
            TenantId::of($row->tenant_id),
            ReconciliationId::of($row->reconciliation_id),
            $row->reason,
            ActorReference::of($row->reopened_by),
            new \DateTimeImmutable($row->reopened_at),
        ), $rows);
    }

    /**
     * Durably records the exact set of BankTransactions `complete()`
     * verified this time (AETS-008 §12.3, `BNK-017`). No-op for an
     * empty set — an empty period has nothing to snapshot.
     *
     * @param  list<BankTransactionId>  $bankTransactionIds
     */
    public function recordCompletionSnapshot(TenantId $tenantId, ReconciliationId $reconciliationId, array $bankTransactionIds, \DateTimeImmutable $completedAt): void
    {
        if ($bankTransactionIds === []) {
            return;
        }

        $this->connection->table(self::SNAPSHOT_TABLE)->insert(array_map(
            static fn (BankTransactionId $bankTransactionId): array => [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId->toString(),
                'reconciliation_id' => $reconciliationId->toString(),
                'bank_transaction_id' => $bankTransactionId->toString(),
                'completed_at' => $completedAt->format('Y-m-d H:i:s'),
            ],
            $bankTransactionIds,
        ));
    }

    /**
     * Clears a Reconciliation's completion snapshot (AETS-008 §12.3,
     * `BNK-017`) — called on `reopen()`, mirroring how `completed_at`
     * itself is already cleared there. The permanent audit trail of why
     * and when lives in `reconciliation_reopenings`; this table only
     * ever represents the *current* completion's scope, so a later
     * `complete()` gets a fresh snapshot rather than an accumulated one.
     */
    public function clearCompletionSnapshot(TenantId $tenantId, ReconciliationId $reconciliationId): void
    {
        $this->connection->table(self::SNAPSHOT_TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('reconciliation_id', $reconciliationId->toString())
            ->delete();
    }

    /**
     * @return list<BankTransactionId>
     */
    public function completionSnapshotBankTransactionIds(TenantId $tenantId, ReconciliationId $reconciliationId): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->table(self::SNAPSHOT_TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('reconciliation_id', $reconciliationId->toString())
            ->pluck('bank_transaction_id')
            ->all();

        return array_map(static fn (string $id): BankTransactionId => BankTransactionId::of($id), $ids);
    }

    /**
     * @param  object{id: string, tenant_id: string, bank_account_id: string, period_start: string, period_end: string, opening_balance: int|string, closing_balance: int|string, currency: string, state: string, created_at: string, completed_at: string|null}  $row
     */
    private function fromPersisted(object $row): Reconciliation
    {
        return Reconciliation::reconstitute(
            ReconciliationId::of($row->id),
            TenantId::of($row->tenant_id),
            BankAccountId::of($row->bank_account_id),
            new \DateTimeImmutable($row->period_start),
            new \DateTimeImmutable($row->period_end),
            $this->money->fromPersisted((string) $row->opening_balance, $row->currency),
            $this->money->fromPersisted((string) $row->closing_balance, $row->currency),
            self::stateFromPersisted($row->state),
            new \DateTimeImmutable($row->created_at),
            $row->completed_at === null ? null : new \DateTimeImmutable($row->completed_at),
        );
    }

    private static function stateFromPersisted(string $value): ReconciliationState
    {
        foreach (ReconciliationState::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw new \RuntimeException(sprintf('Unrecognized persisted Reconciliation state "%s".', $value));
    }
}
