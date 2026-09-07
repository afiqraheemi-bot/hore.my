<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Transactions\OwnerEquity\Exception\InvalidCashAccountTypeException;

/**
 * Thrown when a BankAccount's linked Account does not have Account Type
 * `Asset` (M17) — a Transactions/Banking-domain business rule, not an
 * Accounting Core one, mirroring
 * {@see InvalidCashAccountTypeException}'s
 * own reasoning exactly: a bank account is, by definition, an Asset the
 * business holds — it is never how Revenue is earned, Expense is
 * incurred, or Equity moves.
 */
final class InvalidLinkedAccountTypeException extends \RuntimeException
{
    public static function forAccount(AccountId $accountId): self
    {
        return new self(sprintf(
            'Account "%s" cannot be linked to a BankAccount because its Account Type is not Asset.',
            $accountId->toString(),
        ));
    }
}
