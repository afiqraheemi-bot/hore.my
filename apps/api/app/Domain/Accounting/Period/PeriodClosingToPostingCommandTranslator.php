<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Period;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Period\Exception\NothingToCloseException;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Accounting\Reporting\AccountBalance;
use App\Domain\Accounting\Reporting\NetBalance;
use App\Domain\Transactions\Expense\ExpenseToPostingCommandTranslator;
use App\Infrastructure\Accounting\Reporting\AccountBalanceAggregator;

/**
 * Translates a `PeriodClosingCommand` (Accounting Core) plus the
 * caller-supplied list of every Revenue/Expense Account balance being
 * closed into a `PostingCommand` (M4) — the standard double-entry
 * closing-entry construction: each Revenue/Expense Account is zeroed
 * by a line of the opposite Direction to its own current net balance,
 * and one final "plug" line rolls the combined net effect into the
 * Retained Earnings Account.
 *
 * **Why the caller supplies the balances, not this class.** Computing
 * each Account's current net balance requires querying
 * `journal_lines`/`journals` — {@see AccountBalanceAggregator}'s
 * own job, already proven correct by M10. This class stays a pure,
 * storage-free translator, exactly like every other Posting Command
 * translator in this codebase (mirroring
 * {@see ExpenseToPostingCommandTranslator}'s
 * own placement).
 *
 * **The plug algebra.** Let `R` be the sum of every zeroing Debit line
 * produced for a Revenue Account netting Credit (the normal case), and
 * `E` be the sum of every zeroing Credit line produced for an Expense
 * Account netting Debit (also normal). For the whole Journal to
 * balance, Retained Earnings must receive a Credit of `R - E` when
 * `R > E` (a profit increases Equity) or a Debit of `E - R` when
 * `E > R` (a loss decreases Equity) — computed via
 * {@see NetBalance::fromDebitCredit()} exactly like every other
 * direction-agnostic computation in this codebase (AETS-009 §7's own
 * documented algebra), never by assuming which side is larger. When
 * `R === E` exactly, no plug line is produced at all — the zeroing
 * lines alone already balance, and Retained Earnings is correctly left
 * untouched.
 *
 * **An Account netting its own non-normal Direction is handled
 * identically**, not as a special case: a Revenue Account that happens
 * to net Debit (e.g. after an oversized Reversal) is zeroed by a
 * Credit line, and contributes to the "credit" pool of the plug
 * computation exactly as an Expense Account normally would — the same
 * direction-agnostic technique `ProfitAndLossStatement.netIncomeBalance()`
 * already established.
 */
final class PeriodClosingToPostingCommandTranslator
{
    private const SOURCE_REFERENCE_PREFIX = 'period-closing:';

    /**
     * @param  list<AccountBalance>  $revenueAndExpenseBalances  every
     *                                                           Revenue and Expense Account belonging to the Tenant, as of
     *                                                           `$command->closedThroughDate()` — Accounts with a zero net
     *                                                           balance are skipped, contributing no line.
     *
     * @throws NothingToCloseException if every supplied balance nets to
     *                                 zero — there is nothing to roll into Retained Earnings.
     */
    public function translate(PeriodClosingCommand $command, array $revenueAndExpenseBalances, Currency $currency): PostingCommand
    {
        $zero = Money::fromMinorUnits(MinorUnits::of('0'), $currency);
        $lines = [];
        $debitPool = $zero;
        $creditPool = $zero;

        foreach ($revenueAndExpenseBalances as $balance) {
            $net = $balance->netBalance();

            if ($net->isZero()) {
                continue;
            }

            if ($net->direction() === JournalDirection::Credit) {
                $lines[] = JournalLine::create($balance->accountId(), $net->amount(), JournalDirection::Debit);
                $debitPool = $debitPool->add($net->amount());
            } else {
                $lines[] = JournalLine::create($balance->accountId(), $net->amount(), JournalDirection::Credit);
                $creditPool = $creditPool->add($net->amount());
            }
        }

        if ($lines === []) {
            throw NothingToCloseException::forTenantAndDate($command->tenantId(), $command->closedThroughDate());
        }

        $plug = NetBalance::fromDebitCredit($debitPool, $creditPool);

        if (! $plug->isZero()) {
            $plugDirection = $plug->direction() === JournalDirection::Debit
                ? JournalDirection::Credit
                : JournalDirection::Debit;

            $lines[] = JournalLine::create($command->retainedEarningsAccountId(), $plug->amount(), $plugDirection);
        }

        return new PostingCommand(
            $command->idempotencyKey(),
            $command->tenantId(),
            $command->actor(),
            $this->sourceReferenceFor($command),
            $command->closingJournalId(),
            $lines,
            $command->closedThroughDate(),
        );
    }

    private function sourceReferenceFor(PeriodClosingCommand $command): SourceReference
    {
        return SourceReference::of(self::SOURCE_REFERENCE_PREFIX.$command->closedThroughDate()->format('Y-m-d'));
    }
}
