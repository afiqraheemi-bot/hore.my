<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Expense;

use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;
use App\Domain\Transactions\Expense\Exception\InvalidExpenseAccountTypeException;
use App\Domain\Transactions\Expense\Exception\InvalidPaymentAccountTypeException;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;

/**
 * Validates the semantic Account Type a `RecordExpenseCommand`'s
 * Expense Account and Payment Account each require (M7) — a
 * Transactions-domain business rule, not an Accounting Core one.
 *
 * **Strictly additive to M4's own Account validation.**
 * {@see PostingCommandAccountValidator}
 * already independently enforces existence, Tenant ownership, Active
 * state, and posting-eligibility for every Account a `PostingCommand`'s
 * lines reference, as part of the ordinary Posting Validation Pipeline
 * (AETS-007 §12) — this class duplicates none of that. It checks
 * exactly one additional fact M4 has no reason to know: whether an
 * Account's *type* is semantically appropriate for the role Expense
 * recording asks it to play (the debit side must be an Expense-type
 * Account; the credit side must be an Asset-type Account).
 *
 * **Runs before the Posting Command pipeline, not instead of it.** This
 * check happens first specifically so a semantically wrong Account
 * (for example, accidentally debiting a Revenue account as an
 * "expense") fails with a Transactions-domain-specific, actionable
 * error, before ever reaching M4 — not because M4's own checks are
 * insufficient, but because M4 has no way to express this constraint at
 * all (it is generic across every future Accounting Command, not
 * specific to Expense).
 *
 * **Cannot yet distinguish Cash/Bank from any other Asset Account** —
 * see {@see InvalidPaymentAccountTypeException}'s
 * own docblock for why, and the M7 closure report for the tracked gap.
 */
final class ExpenseAccountTypeValidator
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
    ) {}

    /**
     * @throws RejectedAccountReferenceException if either Account
     *                                           cannot be resolved for the given Tenant.
     * @throws InvalidExpenseAccountTypeException if the Expense
     *                                            Account's type is not `Expense`.
     * @throws InvalidPaymentAccountTypeException if the Payment
     *                                            Account's type is not `Asset`.
     */
    public function validate(RecordExpenseCommand $command): void
    {
        $expenseAccount = $this->accountRepository->findById($command->tenantId(), $command->expenseAccountId());

        if ($expenseAccount === null) {
            throw RejectedAccountReferenceException::forUnresolvedAccount($command->expenseAccountId());
        }

        if ($expenseAccount->type() !== AccountType::Expense) {
            throw InvalidExpenseAccountTypeException::forNonExpenseAccount($command->expenseAccountId());
        }

        $paymentAccount = $this->accountRepository->findById($command->tenantId(), $command->paymentAccountId());

        if ($paymentAccount === null) {
            throw RejectedAccountReferenceException::forUnresolvedAccount($command->paymentAccountId());
        }

        if ($paymentAccount->type() !== AccountType::Asset) {
            throw InvalidPaymentAccountTypeException::forNonAssetAccount($command->paymentAccountId());
        }
    }
}
