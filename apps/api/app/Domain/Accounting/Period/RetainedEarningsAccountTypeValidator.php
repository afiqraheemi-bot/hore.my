<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Period;

use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Period\Exception\InvalidRetainedEarningsAccountTypeException;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Transactions\Expense\ExpenseAccountTypeValidator;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;

/**
 * Validates the semantic Account Type a `PeriodClosingCommand`'s
 * Retained Earnings Account requires (AETS-014) — mirrors
 * {@see ExpenseAccountTypeValidator}'s
 * own placement and reasoning exactly: strictly additive to
 * `PostingCommandAccountValidator`'s existence/Tenant/Active/
 * posting-eligibility checks, which the closing Journal still goes
 * through unchanged once assembled.
 */
final class RetainedEarningsAccountTypeValidator
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
    ) {}

    /**
     * @throws RejectedAccountReferenceException if the Account cannot
     *                                           be resolved for the given Tenant.
     * @throws InvalidRetainedEarningsAccountTypeException if the
     *                                                     Account's type is not `Equity`.
     */
    public function validate(PeriodClosingCommand $command): void
    {
        $account = $this->accountRepository->findById($command->tenantId(), $command->retainedEarningsAccountId());

        if ($account === null) {
            throw RejectedAccountReferenceException::forUnresolvedAccount($command->retainedEarningsAccountId());
        }

        if ($account->type() !== AccountType::Equity) {
            throw InvalidRetainedEarningsAccountTypeException::forNonEquityAccount($command->retainedEarningsAccountId());
        }
    }
}
