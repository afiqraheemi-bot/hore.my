<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Transactions\Income\Exception\InvalidIncomeAccountTypeException;

/**
 * Thrown when an Invoice's chosen Revenue Account is not a Revenue
 * Account (M20) — mirrors
 * {@see InvalidIncomeAccountTypeException}'s
 * own reasoning.
 */
final class InvalidRevenueAccountTypeException extends \InvalidArgumentException
{
    public static function forNonRevenueAccount(AccountId $accountId): self
    {
        return new self(sprintf('Account "%s" is not a Revenue Account and cannot be used as an Invoice\'s Revenue Account.', $accountId->toString()));
    }
}
