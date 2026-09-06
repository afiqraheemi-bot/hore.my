<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Income\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;

/**
 * Thrown when a `RecordIncomeCommand`'s Income Account does not have
 * Account Type `Revenue` (M9).
 *
 * This is a Transactions-domain business rule, not an Accounting Core
 * one — AETS-005's Account contract has no concept of "the account a
 * given business transaction type must credit," and this class invents
 * none at that level. {@see PostingCommandAccountValidator}
 * (M4) already independently enforces existence, Tenant ownership,
 * Active state, and posting-eligibility for the same Account, as part
 * of the ordinary Posting Command pipeline — this check is strictly
 * additive to that, not a replacement for any of it.
 */
final class InvalidIncomeAccountTypeException extends \RuntimeException
{
    public static function forNonRevenueAccount(AccountId $accountId): self
    {
        return new self(sprintf(
            'Account "%s" cannot be used as an Income Account because its Account Type is not Revenue.',
            $accountId->toString(),
        ));
    }
}
