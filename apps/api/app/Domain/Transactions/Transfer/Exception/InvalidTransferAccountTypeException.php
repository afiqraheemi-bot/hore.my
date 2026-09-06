<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Transfer\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;

/**
 * Thrown when a `RecordTransferCommand`'s Source Account or Destination
 * Account does not have Account Type `Asset` or `Liability` (M14).
 *
 * This is a Transactions-domain business rule, not an Accounting Core
 * one — a Transfer moves value between two accounts the business itself
 * already holds (a bank account, a petty cash float, a credit card or
 * loan liability); it is never how Revenue is earned, Expense is
 * incurred, or Equity moves — those are Income's, Expense's, and a
 * future Capital/Drawings command's own concerns, each with their own
 * accounting mapping. {@see PostingCommandAccountValidator} (M4) already
 * independently enforces existence, Tenant ownership, Active state, and
 * posting-eligibility for the same Account, as part of the ordinary
 * Posting Command pipeline — this check is strictly additive to that,
 * not a replacement for any of it.
 */
final class InvalidTransferAccountTypeException extends \RuntimeException
{
    public static function forSourceAccount(AccountId $accountId): self
    {
        return new self(sprintf(
            'Account "%s" cannot be used as a Transfer Source Account because its Account Type is neither Asset nor Liability.',
            $accountId->toString(),
        ));
    }

    public static function forDestinationAccount(AccountId $accountId): self
    {
        return new self(sprintf(
            'Account "%s" cannot be used as a Transfer Destination Account because its Account Type is neither Asset nor Liability.',
            $accountId->toString(),
        ));
    }
}
