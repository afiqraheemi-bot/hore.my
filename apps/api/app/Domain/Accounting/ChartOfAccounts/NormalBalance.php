<?php

declare(strict_types=1);

namespace App\Domain\Accounting\ChartOfAccounts;

/**
 * The Debit or Credit side an Account Type is expected to carry
 * (AETS-005 §11) — derived exclusively from {@see AccountType}, never
 * independently chosen. Exactly two cases: Debit, Credit.
 *
 * Deliberately unbacked, for the same reason as {@see AccountType}:
 * AETS-005 specifies no canonical persisted representation, so no
 * backing value is invented here.
 *
 * Normal Balance is a classificatory property of an Account, never a
 * substitute for a Journal Line's own explicit Debit/Credit Direction
 * (AETS-004 §8; AETS-005 §11) — this enum carries no relationship to
 * Journal or Journal Line, and none should be added here.
 */
enum NormalBalance
{
    case Debit;
    case Credit;
}
