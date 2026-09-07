<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Journal\JournalDirection;

/**
 * The Money In/Money Out classification of one imported bank statement
 * row (M17, SRS BNK-003's "debit/kredit") — deliberately named in
 * plain, bank-statement-native English, never `Debit`/`Credit`.
 *
 * **Why this is not, and must never become, {@see JournalDirection}.**
 * A bank statement's own "credit" (a deposit into the account) is the
 * *opposite* of what crediting that same account means on hore.my's own
 * ledger side: the linked BankAccount's Account is Asset-typed
 * (Debit-normal), so money arriving is a ledger Debit, and money
 * leaving is a ledger Credit — the exact reverse of the bank's own
 * "credit"/"debit" labels on the statement. Reusing `JournalDirection`
 * here, or naming these cases `Debit`/`Credit`, would silently invite
 * that inversion into code that has no accounting context to catch it.
 * `MoneyIn`/`MoneyOut` describes only "which way did cash move",
 * leaving the ledger-side Debit/Credit mapping to whichever future
 * matching/posting logic (M18+) actually needs it, computed explicitly
 * at that point — never assumed here.
 */
enum BankTransactionDirection
{
    case MoneyIn;
    case MoneyOut;
}
