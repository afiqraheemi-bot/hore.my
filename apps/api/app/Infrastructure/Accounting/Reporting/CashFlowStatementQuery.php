<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Reporting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Reporting\CashFlowActivity;
use App\Domain\Accounting\Reporting\CashFlowLine;
use App\Domain\Accounting\Reporting\CashFlowStatement;
use App\Domain\Accounting\Reporting\Exception\AmbiguousCashFlowClassificationException;
use App\Domain\Accounting\Reporting\NetBalance;
use App\Domain\Banking\BankAccount;
use App\Domain\Payments\PaymentToPostingCommandTranslator;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Computes a {@see CashFlowStatement} for a given Period (AETS-009
 * §22, SRS RPT-003) — deliberately its own self-contained query, not
 * an extension of {@see AccountBalanceAggregator}, so this genuinely
 * new derivation (cash-equivalent detection, counterparty
 * classification) can never change that class's own already-governed
 * behavior for Trial Balance, Profit & Loss, Balance Sheet, or
 * General Ledger.
 *
 * **Derivation rule.** A Tenant's cash-equivalent Accounts are every
 * Account with an active {@see BankAccount}
 * linked to it (`bank_accounts.active = true`) — the same set the
 * Bank Reconciliation module (AETS-008) already treats as "a bank
 * account," reused here rather than inventing a second marker. For
 * every Posted Journal, within the Period, that has at least one Line
 * on a cash-equivalent Account *and* at least one Line on a
 * non-cash-equivalent Account: the counterparty Line(s)' own Account
 * Type decide the {@see CashFlowActivity}
 * — Revenue, Expense, or a non-cash Asset (e.g. Accounts Receivable,
 * a working-capital movement) → Operating; Liability → Financing;
 * Equity → Financing. A Journal whose counterparty Lines span more
 * than one Account Type throws {@see AmbiguousCashFlowClassificationException}
 * (never observed from any command type this codebase currently
 * translates — see that exception's own docblock). A Journal touching
 * *only* cash-equivalent Accounts on both sides (a Cash↔Bank transfer)
 * is excluded entirely — it never changes the Tenant's total cash
 * position, exactly like real Cash Flow Statements exclude movements
 * within "cash and cash equivalents" itself.
 *
 * **No Investing activity exists yet in practice.** This codebase has
 * no Fixed Asset / long-term-investment command type, and a non-cash
 * Asset counterparty (the only way an "Investing" classification could
 * arise from a *different* Asset Type) is always treated as Operating
 * here, matching real accounting convention for working-capital Asset
 * movements (Accounts Receivable collection via {@see PaymentToPostingCommandTranslator}).
 * The Investing section therefore reports RM0.00 for every Tenant
 * today — the section still exists in the Statement's own shape
 * (§22) because a future Fixed Asset module would need a genuinely
 * new non-current-vs-current Asset distinction AETS-005's current
 * five-Account-Type contract does not define, not because this query
 * itself is incomplete.
 */
final class CashFlowStatementQuery
{
    private const ACCOUNT_TABLE = 'accounts';

    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const BANK_ACCOUNT_TABLE = 'bank_accounts';

    private const POSTED_STATE = 'Posted';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    public function forPeriod(TenantId $tenantId, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): CashFlowStatement
    {
        $cashAccountIds = $this->cashEquivalentAccountIds($tenantId);

        $cashAtPeriodStart = $this->cashBalanceAsOf($tenantId, $cashAccountIds, $periodStart->modify('-1 day'));
        $cashAtPeriodEnd = $this->cashBalanceAsOf($tenantId, $cashAccountIds, $periodEnd);

        if ($cashAccountIds === []) {
            return new CashFlowStatement($tenantId, $periodStart, $periodEnd, Currency::of('MYR'), [], [], [], $cashAtPeriodStart, $cashAtPeriodEnd);
        }

        $accountTypesById = $this->accountTypesById($tenantId);
        $currency = Currency::of('MYR');

        /** @var array<string, array{debit: Money, credit: Money}> $totalsByCounterparty */
        $totalsByCounterparty = [];

        $journalIds = $this->journalIdsTouchingCash($tenantId, $cashAccountIds, $periodStart, $periodEnd);
        $linesByJournal = $this->linesGroupedByJournal($tenantId, $journalIds);

        foreach ($linesByJournal as $journalId => $lines) {
            $counterpartyLines = array_values(array_filter(
                $lines,
                static fn (object $line): bool => ! in_array($line->account_id, $cashAccountIds, true),
            ));

            if ($counterpartyLines === []) {
                continue; // Cash-equivalent on every Line — excluded (§ Derivation rule).
            }

            $counterpartyTypes = array_unique(array_map(
                static fn (object $line): string => $accountTypesById[$line->account_id] ?? '',
                $counterpartyLines,
            ));

            if (count($counterpartyTypes) > 1) {
                throw AmbiguousCashFlowClassificationException::forJournal($journalId);
            }

            foreach ($counterpartyLines as $line) {
                $amount = $this->money->fromPersisted((string) $line->amount, $line->currency);
                $currency = $amount->currency();
                $zero = Money::fromMinorUnits(MinorUnits::of('0'), $currency);

                $totalsByCounterparty[$line->account_id] ??= ['debit' => $zero, 'credit' => $zero];

                // The cash-side impact is the counterparty's own Line
                // flipped: a counterparty Debit (e.g. an Expense debited)
                // means cash was credited (an outflow) by the identical
                // amount, and vice versa — the two always balance within
                // the same Journal, so the counterparty Line's own
                // amount is exact, never inferred from the cash Line.
                if ($line->direction === JournalDirection::Debit->name) {
                    $totalsByCounterparty[$line->account_id]['credit'] = $totalsByCounterparty[$line->account_id]['credit']->add($amount);
                } else {
                    $totalsByCounterparty[$line->account_id]['debit'] = $totalsByCounterparty[$line->account_id]['debit']->add($amount);
                }
            }
        }

        $operatingLines = [];
        $investingLines = [];
        $financingLines = [];

        foreach ($totalsByCounterparty as $accountId => $totals) {
            $netBalance = NetBalance::fromDebitCredit($totals['debit'], $totals['credit']);
            $line = new CashFlowLine(AccountId::of($accountId), $netBalance);

            match ($accountTypesById[$accountId] ?? null) {
                AccountType::Revenue->name, AccountType::Expense->name, AccountType::Asset->name => $operatingLines[] = $line,
                AccountType::Liability->name, AccountType::Equity->name => $financingLines[] = $line,
                default => null,
            };
        }

        return new CashFlowStatement($tenantId, $periodStart, $periodEnd, $currency, $operatingLines, $investingLines, $financingLines, $cashAtPeriodStart, $cashAtPeriodEnd);
    }

    /**
     * @return list<string>
     */
    private function cashEquivalentAccountIds(TenantId $tenantId): array
    {
        /** @var list<string> */
        return $this->connection->table(self::BANK_ACCOUNT_TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('active', true)
            ->pluck('linked_account_id')
            ->all();
    }

    /**
     * @return array<string, string> account_id => AccountType name
     */
    private function accountTypesById(TenantId $tenantId): array
    {
        /** @var array<string, string> */
        return $this->connection->table(self::ACCOUNT_TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->pluck('account_type', 'account_id')
            ->all();
    }

    /**
     * @param  list<string>  $cashAccountIds
     * @return list<string>
     */
    private function journalIdsTouchingCash(TenantId $tenantId, array $cashAccountIds, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): array
    {
        /** @var list<string> */
        return $this->connection->table(self::LINE_TABLE)
            ->join(self::JOURNAL_TABLE, function ($join): void {
                $join->on(self::LINE_TABLE.'.tenant_id', '=', self::JOURNAL_TABLE.'.tenant_id')
                    ->on(self::LINE_TABLE.'.journal_id', '=', self::JOURNAL_TABLE.'.journal_id');
            })
            ->where(self::JOURNAL_TABLE.'.tenant_id', $tenantId->toString())
            ->where(self::JOURNAL_TABLE.'.state', self::POSTED_STATE)
            ->where(self::JOURNAL_TABLE.'.financial_date', '>=', $periodStart->format('Y-m-d'))
            ->where(self::JOURNAL_TABLE.'.financial_date', '<=', $periodEnd->format('Y-m-d'))
            ->whereIn(self::LINE_TABLE.'.account_id', $cashAccountIds)
            ->distinct()
            ->pluck(self::JOURNAL_TABLE.'.journal_id')
            ->all();
    }

    /**
     * @param  list<string>  $journalIds
     * @return array<string, list<object{journal_id: string, account_id: string, direction: string, amount: int|string, currency: string}>>
     */
    private function linesGroupedByJournal(TenantId $tenantId, array $journalIds): array
    {
        if ($journalIds === []) {
            return [];
        }

        /** @var Collection<int, object{journal_id: string, account_id: string, direction: string, amount: int|string, currency: string}> $rows */
        $rows = $this->connection->table(self::LINE_TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->whereIn('journal_id', $journalIds)
            ->get(['journal_id', 'account_id', 'direction', 'amount', 'currency']);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->journal_id][] = $row;
        }

        return $grouped;
    }

    /**
     * @param  list<string>  $cashAccountIds
     */
    private function cashBalanceAsOf(TenantId $tenantId, array $cashAccountIds, \DateTimeImmutable $asOf): NetBalance
    {
        $currency = Currency::of('MYR');
        $totalDebit = Money::fromMinorUnits(MinorUnits::of('0'), $currency);
        $totalCredit = Money::fromMinorUnits(MinorUnits::of('0'), $currency);

        if ($cashAccountIds === []) {
            return NetBalance::fromDebitCredit($totalDebit, $totalCredit);
        }

        /** @var Collection<int, object{direction: string, amount: int|string, currency: string}> $rows */
        $rows = $this->connection->table(self::LINE_TABLE)
            ->join(self::JOURNAL_TABLE, function ($join): void {
                $join->on(self::LINE_TABLE.'.tenant_id', '=', self::JOURNAL_TABLE.'.tenant_id')
                    ->on(self::LINE_TABLE.'.journal_id', '=', self::JOURNAL_TABLE.'.journal_id');
            })
            ->where(self::JOURNAL_TABLE.'.tenant_id', $tenantId->toString())
            ->where(self::JOURNAL_TABLE.'.state', self::POSTED_STATE)
            ->where(self::JOURNAL_TABLE.'.financial_date', '<=', $asOf->format('Y-m-d'))
            ->whereIn(self::LINE_TABLE.'.account_id', $cashAccountIds)
            ->get([self::LINE_TABLE.'.direction', self::LINE_TABLE.'.amount', self::LINE_TABLE.'.currency']);

        foreach ($rows as $row) {
            $amount = $this->money->fromPersisted((string) $row->amount, $row->currency);

            if ($row->direction === JournalDirection::Debit->name) {
                $totalDebit = $totalDebit->add($amount);
            } else {
                $totalCredit = $totalCredit->add($amount);
            }
        }

        return NetBalance::fromDebitCredit($totalDebit, $totalCredit);
    }
}
