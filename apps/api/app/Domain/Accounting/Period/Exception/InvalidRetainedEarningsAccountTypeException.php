<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Period\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Transactions\Expense\Exception\InvalidExpenseAccountTypeException;

/**
 * Thrown when a `PeriodClosingCommand`'s Retained Earnings Account does
 * not have Account Type `Equity` (AETS-014) — mirrors
 * {@see InvalidExpenseAccountTypeException}'s
 * own reasoning exactly: this is a Period-closing business rule, not
 * an Accounting Core one, and is strictly additive to
 * `PostingCommandAccountValidator`'s own existence/Tenant/Active/
 * posting-eligibility checks.
 */
final class InvalidRetainedEarningsAccountTypeException extends \RuntimeException
{
    public static function forNonEquityAccount(AccountId $accountId): self
    {
        return new self(sprintf(
            'Account "%s" cannot be used as the Retained Earnings Account because its Account Type is not Equity.',
            $accountId->toString(),
        ));
    }
}
