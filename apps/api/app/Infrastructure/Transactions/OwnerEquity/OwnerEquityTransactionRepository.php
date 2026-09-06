<?php

declare(strict_types=1);

namespace App\Infrastructure\Transactions\OwnerEquity;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\OwnerEquity\OwnerEquityMovementType;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransaction;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionId;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionRecordingService;
use App\Infrastructure\Accounting\Audit\AuditEventRepository;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the Owner Equity Transaction record
 * (M15), through the production `owner_equity_transactions` table.
 *
 * **`movement_type` persisted as a canonical string** ('Contribution'
 * or 'Drawing') — the mapping this class alone owns, mirroring how
 * every other enum-backed column in this schema (`journals.state`,
 * `accounts.account_type`, ...) is mapped at the persistence boundary,
 * never inside the Domain enum itself.
 *
 * **Transaction participation.** {@see record()} opens no transaction
 * of its own — the `INSERT` runs directly on `$this->connection`'s
 * current state, so when a caller has already started a transaction on
 * that same connection (the outer transaction
 * {@see OwnerEquityTransactionRecordingService} manages, which also
 * posts the Journal this record references), this insert becomes part
 * of it — the identical participation pattern
 * {@see PostingIdempotencyRepository::record()} and
 * {@see AuditEventRepository::record()} already establish.
 *
 * **Immutable by design.** There is no `update()` and no `delete()` —
 * a record is corrected via M5 Reversal/Replacement of its Journal,
 * never by mutating this row.
 */
final class OwnerEquityTransactionRepository
{
    private const TABLE = 'owner_equity_transactions';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    public function record(OwnerEquityTransaction $transaction): void
    {
        $evidenceReference = $transaction->evidenceReference();

        $this->connection->table(self::TABLE)->insert([
            'id' => $transaction->id()->toString(),
            'tenant_id' => $transaction->tenantId()->toString(),
            'journal_id' => $transaction->journalId()->toString(),
            'movement_type' => $this->toPersistedMovementType($transaction->movementType()),
            'amount' => $this->money->toPersistedAmount($transaction->amount()),
            'currency' => $this->money->toPersistedCurrency($transaction->amount()),
            'transaction_date' => $transaction->transactionDate()->format('Y-m-d'),
            'equity_account_id' => $transaction->equityAccountId()->toString(),
            'cash_account_id' => $transaction->cashAccountId()->toString(),
            'description' => $transaction->description(),
            'evidence_reference' => $evidenceReference?->toString(),
        ]);
    }

    /**
     * Retrieve an Owner Equity Transaction by its own stable
     * identifier, scoped to the given Tenant.
     */
    public function findById(TenantId $tenantId, OwnerEquityTransactionId $id): ?OwnerEquityTransaction
    {
        /** @var object{id: string, tenant_id: string, journal_id: string, movement_type: string, amount: int|string, currency: string, transaction_date: string, equity_account_id: string, cash_account_id: string, description: string, evidence_reference: string|null}|null $row */
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
     * Retrieve an Owner Equity Transaction by the Journal it produced,
     * scoped to the given Tenant — used to resolve an idempotent replay
     * back to its originally-recorded record.
     */
    public function findByJournalId(TenantId $tenantId, JournalId $journalId): ?OwnerEquityTransaction
    {
        /** @var object{id: string, tenant_id: string, journal_id: string, movement_type: string, amount: int|string, currency: string, transaction_date: string, equity_account_id: string, cash_account_id: string, description: string, evidence_reference: string|null}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('journal_id', $journalId->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    private function toPersistedMovementType(OwnerEquityMovementType $movementType): string
    {
        return match ($movementType) {
            OwnerEquityMovementType::Contribution => 'Contribution',
            OwnerEquityMovementType::Drawing => 'Drawing',
        };
    }

    private function fromPersistedMovementType(string $value): OwnerEquityMovementType
    {
        return match ($value) {
            'Contribution' => OwnerEquityMovementType::Contribution,
            'Drawing' => OwnerEquityMovementType::Drawing,
            default => throw new \RuntimeException(sprintf('Unrecognized persisted Owner Equity movement type "%s".', $value)),
        };
    }

    /**
     * @param  object{id: string, tenant_id: string, journal_id: string, movement_type: string, amount: int|string, currency: string, transaction_date: string, equity_account_id: string, cash_account_id: string, description: string, evidence_reference: string|null}  $row
     */
    private function fromPersisted(object $row): OwnerEquityTransaction
    {
        return OwnerEquityTransaction::reconstitute(
            OwnerEquityTransactionId::of($row->id),
            TenantId::of($row->tenant_id),
            JournalId::of($row->journal_id),
            $this->fromPersistedMovementType($row->movement_type),
            $this->money->fromPersisted((string) $row->amount, $row->currency),
            new \DateTimeImmutable($row->transaction_date),
            AccountId::of($row->equity_account_id),
            AccountId::of($row->cash_account_id),
            $row->description,
            $row->evidence_reference === null ? null : EvidenceReference::of($row->evidence_reference),
        );
    }
}
