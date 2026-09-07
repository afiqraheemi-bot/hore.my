<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Transactions\Income\Exception\InvalidDepositAccountTypeException;

/**
 * Thrown when an Invoice's chosen Receivable Account is not an Asset
 * Account (M20) — mirrors
 * {@see InvalidDepositAccountTypeException}'s
 * own reasoning.
 */
final class InvalidReceivableAccountTypeException extends \InvalidArgumentException
{
    public static function forNonAssetAccount(AccountId $accountId): self
    {
        return new self(sprintf('Account "%s" is not an Asset Account and cannot be used as an Invoice\'s Receivable Account.', $accountId->toString()));
    }
}
