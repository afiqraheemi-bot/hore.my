<?php

declare(strict_types=1);

namespace App\Infrastructure\Banking;

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\Exception\ReconciliationNotFoundException;
use App\Domain\Banking\Reconciliation;
use App\Domain\Banking\ReconciliationId;
use App\Domain\Banking\ReconciliationReopening;
use App\Domain\Banking\ReconciliationState;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the Reconciliation aggregate and its
 * {@see ReconciliationReopening} history (M18), through the production
 * `reconciliations` and `reconciliation_reopenings` tables.
 */
final class ReconciliationRepository
{
    private const TABLE = 'reconciliations';

    private const REOPENING_TABLE = 'reconciliation_reopenings';

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
