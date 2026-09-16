<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use App\Infrastructure\Transactions\Expense\ExpenseRepository;
use App\Infrastructure\Transactions\Income\IncomeRepository;
use App\Infrastructure\Transactions\OwnerEquity\OwnerEquityTransactionRepository;
use App\Infrastructure\Transactions\Transfer\TransferRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * Suggests {@see MatchCandidate}s for one BankTransaction (M18, SRS
 * BNK-005) — read-only, never persists anything; see {@see BankTransactionMatch}'s own
 * docblock for why only a *confirmed* match is ever written.
 *
 * **The direction inversion, made explicit.** A BankAccount's linked
 * Account is always Asset-typed (Debit-normal, enforced by
 * {@see BankAccountLinkedAccountValidator}) — money arriving
 * ({@see BankTransactionDirection::MoneyIn}) is a ledger *Debit* on
 * that Account, and money leaving
 * ({@see BankTransactionDirection::MoneyOut}) is a ledger *Credit* —
 * see {@see BankTransactionDirection}'s own docblock for the full
 * reasoning. This is the one place that inversion is actually applied.
 *
 * **Exact-match only, one score tier.** A candidate requires the exact
 * same Account, amount, and Financial Date as the BankTransaction —
 * there is no fuzzy date-tolerance or partial-amount matching in v1.
 * SRS BNK-005 asks that "Skor dan rasional disimpan" (score and
 * rationale saved); this milestone's rationale is always the specific
 * Transactions-domain record identified, and the implicit score is
 * always "Exact" — a genuine fuzzy-scoring engine is deferred, tracked
 * work, not silently approximated here.
 *
 * **Already-confirmed Journals are excluded — except a Transfer's
 * second leg** (AETS-008 §12.2, `BNK-014`). SRS §10.5's "Baris sumber
 * bank yang sama tidak boleh dipost dua kali kepada peristiwa sama"
 * (the same bank source row must never be posted twice to the same
 * event) means a Journal already confirmed-matched to some *other*
 * BankTransaction is never suggested again — except a Transfer
 * Journal, which genuinely produces two bank-statement rows (money
 * leaving one of the Tenant's Bank Accounts, arriving at another) for
 * one accounting event. A Transfer Journal already confirmed-matched
 * once, on a *different* Bank Account than the one this call is
 * suggesting for, is suggested exactly once more, for its second and
 * final leg; a Transfer already matched twice, or already matched on
 * this same Bank Account, is excluded like any other fully-matched
 * Journal.
 */
final class BankTransactionMatchSuggester
{
    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ExpenseRepository $expenseRepository,
        private readonly IncomeRepository $incomeRepository,
        private readonly TransferRepository $transferRepository,
        private readonly OwnerEquityTransactionRepository $ownerEquityRepository,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    /**
     * @return list<MatchCandidate>
     */
    public function suggestFor(TenantId $tenantId, BankAccount $bankAccount, BankTransaction $bankTransaction): array
    {
        $expectedDirection = self::expectedJournalDirectionFor($bankTransaction->direction());

        /** @var list<string> $candidateJournalIds */
        $candidateJournalIds = $this->connection->table('journal_lines')
            ->join('journals', function ($join): void {
                $join->on('journal_lines.tenant_id', '=', 'journals.tenant_id')
                    ->on('journal_lines.journal_id', '=', 'journals.journal_id');
            })
            ->where('journal_lines.tenant_id', $tenantId->toString())
            ->where('journal_lines.account_id', $bankAccount->linkedAccountId()->toString())
            ->where('journal_lines.direction', $expectedDirection->name)
            ->where('journal_lines.amount', $this->money->toPersistedAmount($bankTransaction->amount()))
            ->where('journals.financial_date', $bankTransaction->transactionDate()->format('Y-m-d'))
            ->where('journals.state', 'Posted')
            ->pluck('journals.journal_id')
            ->all();

        if ($candidateJournalIds === []) {
            return [];
        }

        /** @var array<string, list<string>> $existingMatchBankAccountIdsByJournal */
        $existingMatchBankAccountIdsByJournal = [];

        foreach ($this->connection->table('matches')
            ->join('bank_transactions', function ($join): void {
                $join->on('matches.tenant_id', '=', 'bank_transactions.tenant_id')
                    ->on('matches.bank_transaction_id', '=', 'bank_transactions.id');
            })
            ->where('matches.tenant_id', $tenantId->toString())
            ->whereIn('matches.journal_id', $candidateJournalIds)
            ->select('matches.journal_id', 'bank_transactions.bank_account_id')
            ->get() as $row) {
            $existingMatchBankAccountIdsByJournal[$row->journal_id][] = $row->bank_account_id;
        }

        $candidates = [];

        foreach ($candidateJournalIds as $journalIdString) {
            $journalId = JournalId::of($journalIdString);
            $identified = $this->identifySource($tenantId, $journalId);

            if ($identified === null) {
                continue;
            }

            $existingBankAccountIds = $existingMatchBankAccountIdsByJournal[$journalIdString] ?? [];

            if (! self::isEligibleForAnotherMatch($identified[0], $existingBankAccountIds, $bankAccount->id())) {
                continue;
            }

            $candidates[] = new MatchCandidate($bankTransaction->id(), $journalId, $identified[0], $identified[1]);
        }

        return $candidates;
    }

    /**
     * @param  list<string>  $existingMatchBankAccountIds  the Bank Account
     *                                                     ID (as a raw string) of every Bank Transaction already
     *                                                     confirmed-matched to this Journal
     */
    private static function isEligibleForAnotherMatch(MatchSourceType $sourceType, array $existingMatchBankAccountIds, BankAccountId $candidateBankAccountId): bool
    {
        if ($sourceType !== MatchSourceType::Transfer) {
            return $existingMatchBankAccountIds === [];
        }

        if (count($existingMatchBankAccountIds) >= 2) {
            return false;
        }

        return ! in_array($candidateBankAccountId->toString(), $existingMatchBankAccountIds, true);
    }

    public static function expectedJournalDirectionFor(BankTransactionDirection $direction): JournalDirection
    {
        return match ($direction) {
            BankTransactionDirection::MoneyIn => JournalDirection::Debit,
            BankTransactionDirection::MoneyOut => JournalDirection::Credit,
        };
    }

    /**
     * @return array{0: MatchSourceType, 1: string}|null
     */
    private function identifySource(TenantId $tenantId, JournalId $journalId): ?array
    {
        if (($expense = $this->expenseRepository->findByJournalId($tenantId, $journalId)) !== null) {
            return [MatchSourceType::Expense, sprintf('Matches Expense: %s', $expense->description())];
        }

        if (($income = $this->incomeRepository->findByJournalId($tenantId, $journalId)) !== null) {
            return [MatchSourceType::Income, sprintf('Matches Income: %s', $income->description())];
        }

        if (($transfer = $this->transferRepository->findByJournalId($tenantId, $journalId)) !== null) {
            return [MatchSourceType::Transfer, sprintf('Matches Transfer: %s', $transfer->description())];
        }

        if (($ownerEquity = $this->ownerEquityRepository->findByJournalId($tenantId, $journalId)) !== null) {
            return [MatchSourceType::OwnerEquity, sprintf('Matches Owner Equity Transaction: %s', $ownerEquity->description())];
        }

        return null;
    }
}
