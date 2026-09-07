<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Journal\JournalDirection;

/**
 * Which way a {@see ReconciliationDifference} leans (M18) — the
 * Reconciliation's own user-entered closing balance is `Over` (higher
 * than) or `Short` (lower than) what the imported BankTransactions for
 * the period imply, deliberately named in plain bank-reconciliation
 * English rather than reusing {@see JournalDirection}'s
 * Debit/Credit — the same reasoning
 * {@see BankTransactionDirection}'s own docblock gives: this concept is
 * not a ledger Direction, and must never be confused for one.
 */
enum ReconciliationDifferenceSign
{
    case Over;
    case Short;
}
