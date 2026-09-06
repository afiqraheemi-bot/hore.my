<?php

declare(strict_types=1);

namespace App\Infrastructure\Transactions\Income;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Income\Income;
use App\Domain\Transactions\Income\IncomeId;
use App\Domain\Transactions\Income\IncomeRecordingService;
use App\Infrastructure\Accounting\Audit\AuditEventRepository;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the Income record (M9), through the
 * production `incomes` table.
 *
 * **Transaction participation.** {@see record()} opens no transaction
 * of its own — the `INSERT` runs directly on `$this->connection`'s
 * current state, so when a caller has already started a transaction on
 * that same connection (the outer transaction
 * {@see IncomeRecordingService}
 * manages, which also posts the Journal this Income references), this
 * insert becomes part of it — the identical participation pattern
 * {@see PostingIdempotencyRepository::record()}
 * and {@see AuditEventRepository::record()}
 * already establish.
 *
 * **Immutable by design.** There is no `update()` and no `delete()` —
 * an Income is corrected via M5 Reversal/Replacement of its Journal,
 * never by mutating this row.
 */
final class IncomeRepository
{
    private const TABLE = 'incomes';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    public function record(Income $income): void
    {
        $evidenceReference = $income->evidenceReference();

        $this->connection->table(self::TABLE)->insert([
            'id' => $income->id()->toString(),
            'tenant_id' => $income->tenantId()->toString(),
            'journal_id' => $income->journalId()->toString(),
            'amount' => $this->money->toPersistedAmount($income->amount()),
            'currency' => $this->money->toPersistedCurrency($income->amount()),
            'transaction_date' => $income->transactionDate()->format('Y-m-d'),
            'income_account_id' => $income->incomeAccountId()->toString(),
            'deposit_account_id' => $income->depositAccountId()->toString(),
            'description' => $income->description(),
            'evidence_reference' => $evidenceReference?->toString(),
        ]);
    }

    /**
     * Retrieve an Income by its own stable identifier, scoped to the
     * given Tenant — an Income belonging to a different Tenant, even
     * one with the same `$incomeId`, is never returned.
     */
    public function findById(TenantId $tenantId, IncomeId $incomeId): ?Income
    {
        /** @var object{id: string, tenant_id: string, journal_id: string, amount: int|string, currency: string, transaction_date: string, income_account_id: string, deposit_account_id: string, description: string, evidence_reference: string|null}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $incomeId->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    /**
     * Retrieve an Income by the Journal it produced, scoped to the
     * given Tenant — used to resolve an idempotent replay back to its
     * originally-recorded Income.
     */
    public function findByJournalId(TenantId $tenantId, JournalId $journalId): ?Income
    {
        /** @var object{id: string, tenant_id: string, journal_id: string, amount: int|string, currency: string, transaction_date: string, income_account_id: string, deposit_account_id: string, description: string, evidence_reference: string|null}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('journal_id', $journalId->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    /**
     * @param  object{id: string, tenant_id: string, journal_id: string, amount: int|string, currency: string, transaction_date: string, income_account_id: string, deposit_account_id: string, description: string, evidence_reference: string|null}  $row
     */
    private function fromPersisted(object $row): Income
    {
        return Income::reconstitute(
            IncomeId::of($row->id),
            TenantId::of($row->tenant_id),
            JournalId::of($row->journal_id),
            $this->money->fromPersisted((string) $row->amount, $row->currency),
            new \DateTimeImmutable($row->transaction_date),
            AccountId::of($row->income_account_id),
            AccountId::of($row->deposit_account_id),
            $row->description,
            $row->evidence_reference === null ? null : EvidenceReference::of($row->evidence_reference),
        );
    }
}
