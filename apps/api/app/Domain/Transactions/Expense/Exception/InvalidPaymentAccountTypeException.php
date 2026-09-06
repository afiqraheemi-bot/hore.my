<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Expense\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountType;

/**
 * Thrown when a `RecordExpenseCommand`'s Payment Account does not have
 * Account Type `Asset` (M7).
 *
 * **Deliberately coarser than "Cash/Bank" specifically.** AETS-005's
 * current Account Type contract ({@see AccountType})
 * has exactly five cases — Asset, Liability, Equity, Revenue, Expense —
 * with no finer Cash/Bank sub-classification of Asset. This check
 * therefore rejects the clearly-wrong cases (a Liability, Equity,
 * Revenue, or Expense account used as a payment source) but cannot yet
 * distinguish a genuine Cash/Bank account from another Asset account
 * (for example, Accounts Receivable) — that distinction does not exist
 * in the current Chart of Accounts contract and is not invented here
 * (a known, tracked gap; see the M7 closure report).
 */
final class InvalidPaymentAccountTypeException extends \RuntimeException
{
    public static function forNonAssetAccount(AccountId $accountId): self
    {
        return new self(sprintf(
            'Account "%s" cannot be used as a Payment Account because its Account Type is not Asset.',
            $accountId->toString(),
        ));
    }
}
