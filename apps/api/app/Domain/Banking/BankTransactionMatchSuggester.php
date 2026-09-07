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
 * **Already-confirmed Journals are excluded** — SRS §10.5's "Baris
 * sumber bank yang sama tidak boleh dipost dua kali kepada peristiwa
 * sama" (the same bank source row must never be posted twice to the
 * same event) extends here to mean a Journal already confirmed-matched
 * to some *other* BankTransaction is never suggested again.
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
            ->whereNotIn('journals.journal_id', function ($query) use ($tenantId): void {
                $query->select('journal_id')->from('matches')->where('tenant_id', $tenantId->toString());
            })
            ->pluck('journals.journal_id')
            ->all();

        $candidates = [];

        foreach ($candidateJournalIds as $journalIdString) {
            $journalId = JournalId::of($journalIdString);
            $identified = $this->identifySource($tenantId, $journalId);

            if ($identified === null) {
                continue;
            }

            $candidates[] = new MatchCandidate($bankTransaction->id(), $journalId, $identified[0], $identified[1]);
        }

        return $candidates;
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
