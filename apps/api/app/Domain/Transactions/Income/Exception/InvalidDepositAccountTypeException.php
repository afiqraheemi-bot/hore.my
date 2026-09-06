<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Income\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountType;

/**
 * Thrown when a `RecordIncomeCommand`'s Deposit Account does not have
 * Account Type `Asset` (M9).
 *
 * **Deliberately coarser than "Cash/Bank" specifically.** AETS-005's
 * current Account Type contract ({@see AccountType})
 * has exactly five cases — Asset, Liability, Equity, Revenue, Income —
 * with no finer Cash/Bank sub-classification of Asset. This check
 * therefore rejects the clearly-wrong cases (a Liability, Equity,
 * Revenue, or Income account used as a deposit source) but cannot yet
 * distinguish a genuine Cash/Bank account from another Asset account
 * (for example, Accounts Receivable) — that distinction does not exist
 * in the current Chart of Accounts contract and is not invented here
 * (a known, tracked gap; see the M9 closure report).
 */
final class InvalidDepositAccountTypeException extends \RuntimeException
{
    public static function forNonAssetAccount(AccountId $accountId): self
    {
        return new self(sprintf(
            'Account "%s" cannot be used as a Deposit Account because its Account Type is not Asset.',
            $accountId->toString(),
        ));
    }
}
