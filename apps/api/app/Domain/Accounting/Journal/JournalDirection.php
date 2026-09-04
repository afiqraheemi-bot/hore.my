<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal;

use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\ChartOfAccounts\NormalBalance;

/**
 * The Debit/Credit classification of a Journal Line (AETS-004 §6, §8)
 * — exactly one of two mutually exclusive values, never both, never
 * neither. Exactly two cases: Debit, Credit.
 *
 * **Magnitude-with-direction, not signed Money.** A Journal Line's
 * Money is always a non-negative magnitude; the accounting sign is
 * carried entirely by this Direction, never by negating the Money
 * value (AETS-004 §7, §8) — no real ledger ever writes "a debit of
 * -RM10.00," it writes "a credit of RM10.00." This enum carries no
 * numeric sign, multiplier, or any other relationship to Money's own
 * value — that association belongs to whatever future Journal Line
 * type pairs a Direction with a Money amount, not to this type.
 *
 * **Distinct from Normal Balance.** {@see NormalBalance}
 * is a classificatory property of an Account Type (AETS-005 §11) —
 * derived exclusively from Account Type, never chosen per Journal
 * Line. Direction is the opposite: chosen explicitly per Journal
 * Line, never derived from an Account's Normal Balance or Account
 * Type (AETS-004 §8). The two enums happen to share case names
 * (Debit, Credit) because both domains use the same two words for the
 * same real-world concept, but they are separate types with separate
 * purposes — this type is never reused as, aliased to, or converted
 * from/to {@see NormalBalance}.
 *
 * Deliberately unbacked, for the same reason as
 * {@see NormalBalance} and
 * {@see AccountType}: AETS-004
 * specifies no canonical persisted representation yet, so no backing
 * value is invented here.
 *
 * No JournalLine, Journal, or Posting Engine implementation exists
 * yet — this type names Direction only.
 */
enum JournalDirection
{
    case Debit;
    case Credit;
}
