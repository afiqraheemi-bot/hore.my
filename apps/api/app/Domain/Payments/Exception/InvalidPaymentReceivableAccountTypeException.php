<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountId;

/**
 * Thrown when a Payment's chosen Receivable Account is not an Asset
 * Account (M21).
 */
final class InvalidPaymentReceivableAccountTypeException extends \InvalidArgumentException
{
    public static function forNonAssetAccount(AccountId $accountId): self
    {
        return new self(sprintf('Account "%s" is not an Asset Account and cannot be used as a Payment\'s Receivable Account.', $accountId->toString()));
    }
}
