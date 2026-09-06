<?php

declare(strict_types=1);

namespace App\Infrastructure\Transactions\Expense;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Expense\Expense;
use App\Domain\Transactions\Expense\ExpenseId;
use App\Domain\Transactions\Expense\ExpenseRecordingService;
use App\Infrastructure\Accounting\Audit\AuditEventRepository;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the Expense record (M7), through the
 * production `expenses` table.
 *
 * **Transaction participation.** {@see record()} opens no transaction
 * of its own — the `INSERT` runs directly on `$this->connection`'s
 * current state, so when a caller has already started a transaction on
 * that same connection (the outer transaction
 * {@see ExpenseRecordingService}
 * manages, which also posts the Journal this Expense references), this
 * insert becomes part of it — the identical participation pattern
 * {@see PostingIdempotencyRepository::record()}
 * and {@see AuditEventRepository::record()}
 * already establish.
 *
 * **Immutable by design.** There is no `update()` and no `delete()` —
 * an Expense is corrected via M5 Reversal/Replacement of its Journal,
 * never by mutating this row.
 */
final class ExpenseRepository
{
    private const TABLE = 'expenses';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    public function record(Expense $expense): void
    {
        $evidenceReference = $expense->evidenceReference();

        $this->connection->table(self::TABLE)->insert([
            'id' => $expense->id()->toString(),
            'tenant_id' => $expense->tenantId()->toString(),
            'journal_id' => $expense->journalId()->toString(),
            'amount' => $this->money->toPersistedAmount($expense->amount()),
            'currency' => $this->money->toPersistedCurrency($expense->amount()),
            'transaction_date' => $expense->transactionDate()->format('Y-m-d'),
            'expense_account_id' => $expense->expenseAccountId()->toString(),
            'payment_account_id' => $expense->paymentAccountId()->toString(),
            'description' => $expense->description(),
            'evidence_reference' => $evidenceReference?->toString(),
        ]);
    }

    /**
     * Retrieve an Expense by its own stable identifier, scoped to the
     * given Tenant — an Expense belonging to a different Tenant, even
     * one with the same `$expenseId`, is never returned.
     */
    public function findById(TenantId $tenantId, ExpenseId $expenseId): ?Expense
    {
        /** @var object{id: string, tenant_id: string, journal_id: string, amount: int|string, currency: string, transaction_date: string, expense_account_id: string, payment_account_id: string, description: string, evidence_reference: string|null}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $expenseId->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    /**
     * Retrieve an Expense by the Journal it produced, scoped to the
     * given Tenant — used to resolve an idempotent replay back to its
     * originally-recorded Expense.
     */
    public function findByJournalId(TenantId $tenantId, JournalId $journalId): ?Expense
    {
        /** @var object{id: string, tenant_id: string, journal_id: string, amount: int|string, currency: string, transaction_date: string, expense_account_id: string, payment_account_id: string, description: string, evidence_reference: string|null}|null $row */
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
     * @param  object{id: string, tenant_id: string, journal_id: string, amount: int|string, currency: string, transaction_date: string, expense_account_id: string, payment_account_id: string, description: string, evidence_reference: string|null}  $row
     */
    private function fromPersisted(object $row): Expense
    {
        return Expense::reconstitute(
            ExpenseId::of($row->id),
            TenantId::of($row->tenant_id),
            JournalId::of($row->journal_id),
            $this->money->fromPersisted((string) $row->amount, $row->currency),
            new \DateTimeImmutable($row->transaction_date),
            AccountId::of($row->expense_account_id),
            AccountId::of($row->payment_account_id),
            $row->description,
            $row->evidence_reference === null ? null : EvidenceReference::of($row->evidence_reference),
        );
    }
}
