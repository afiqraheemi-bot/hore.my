<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Transactions\Income\Exception\InvalidDepositAccountTypeException;

/**
 * Thrown when a Payment's chosen deposit Account is not an Asset
 * Account (M21) — mirrors
 * {@see InvalidDepositAccountTypeException}'s
 * own reasoning.
 */
final class InvalidPaymentDepositAccountTypeException extends \InvalidArgumentException
{
    public static function forNonAssetAccount(AccountId $accountId): self
    {
        return new self(sprintf('Account "%s" is not an Asset Account and cannot be used as a Payment\'s deposit Account.', $accountId->toString()));
    }
}
