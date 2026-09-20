<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reporting;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Money\Money;
use App\Infrastructure\Accounting\Reporting\GeneralLedgerQuery;

/**
 * A per-row running balance for a {@see GeneralLedgerAccountActivity}'s
 * own `entries()` (AETS-009 §21, `RPT-020`, added v1.9.0) — the one
 * genuinely new derived value the extended-PDF export introduces.
 *
 * `GeneralLedgerEntry` itself carries no running balance, and
 * {@see GeneralLedgerQuery}
 * computes `openingBalance()`/`closingBalance()` independently via
 * `AccountBalanceAggregator`, never by walking `entries()`. This class
 * accumulates each entry's already-computed amount+direction onto the
 * previous row's balance, starting from the activity's own
 * `openingBalance()`, in the exact chronological order `entries()`
 * already returns them (financial_date, then posted_at) — the same
 * Debit/Credit netting {@see NetBalance::fromDebitCredit()} already
 * encodes, applied incrementally rather than to two grand totals. The
 * final row is guaranteed to tie out exactly to the activity's own
 * `closingBalance()`, since both are the identical net effect of the
 * same opening balance plus the same entries, computed two different
 * ways — proven directly by
 * `ReportingQueriesIntegrationTest::test_general_ledger_running_balance_ties_out_to_the_closing_balance()`.
 */
final class GeneralLedgerRunningBalance
{
    /**
     * @param  list<GeneralLedgerEntry>  $entries
     * @return list<NetBalance> one running balance per entry, same order
     */
    public static function forEntries(NetBalance $opening, array $entries): array
    {
        $amount = $opening->amount();
        $direction = $opening->direction();

        $balances = [];
        foreach ($entries as $entry) {
            [$amount, $direction] = self::applyEntry($amount, $direction, $entry->amount(), $entry->direction());
            $balances[] = self::netBalanceOf($amount, $direction);
        }

        return $balances;
    }

    /**
     * One step of the same Debit/Credit netting
     * {@see NetBalance::fromDebitCredit()} already encodes: an entry in
     * the same direction as the running balance adds to it; an entry
     * in the opposite direction nets against it, flipping direction if
     * the entry outweighs the running balance.
     *
     * @return array{0: Money, 1: JournalDirection|null}
     */
    private static function applyEntry(Money $amount, ?JournalDirection $direction, Money $entryAmount, JournalDirection $entryDirection): array
    {
        if ($direction === null || $direction === $entryDirection) {
            return [$amount->add($entryAmount), $direction ?? $entryDirection];
        }

        $comparison = $amount->compareTo($entryAmount);

        if ($comparison > 0) {
            return [$amount->subtract($entryAmount), $direction];
        }

        if ($comparison < 0) {
            return [$entryAmount->subtract($amount), $entryDirection];
        }

        return [$amount->subtract($amount), null];
    }

    /**
     * The only way to construct a {@see NetBalance} with a specific
     * amount+direction through its own public API
     * ({@see NetBalance::fromDebitCredit()}, its sole factory) — feeds
     * the amount as whichever side (debit/credit) the direction calls
     * for, and a same-currency zero (`$amount->subtract($amount)`) as
     * the other, the identical idiom `fromDebitCredit()` itself uses
     * for its own zero case.
     */
    private static function netBalanceOf(Money $amount, ?JournalDirection $direction): NetBalance
    {
        $zero = $amount->subtract($amount);

        return match ($direction) {
            JournalDirection::Debit => NetBalance::fromDebitCredit($amount, $zero),
            JournalDirection::Credit => NetBalance::fromDebitCredit($zero, $amount),
            null => NetBalance::fromDebitCredit($zero, $zero),
        };
    }
}
