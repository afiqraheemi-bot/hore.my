<?php

declare(strict_types=1);

namespace App\Domain\Transactions\OwnerEquity\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;

/**
 * Thrown when a `RecordOwnerEquityTransactionCommand`'s Cash Account
 * does not have Account Type `Asset` (M15).
 *
 * This is a Transactions-domain business rule, not an Accounting Core
 * one. {@see PostingCommandAccountValidator} (M4) already independently
 * enforces existence, Tenant ownership, Active state, and
 * posting-eligibility for the same Account — this check is strictly
 * additive to that, not a replacement for any of it.
 */
final class InvalidCashAccountTypeException extends \RuntimeException
{
    public static function forAccount(AccountId $accountId): self
    {
        return new self(sprintf(
            'Account "%s" cannot be used as an Owner Equity Transaction\'s Cash Account because its Account Type is not Asset.',
            $accountId->toString(),
        ));
    }
}
