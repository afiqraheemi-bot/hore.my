<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Income;

use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;
use App\Domain\Transactions\Income\Exception\InvalidDepositAccountTypeException;
use App\Domain\Transactions\Income\Exception\InvalidIncomeAccountTypeException;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;

/**
 * Validates the semantic Account Type a `RecordIncomeCommand`'s
 * Income Account and Deposit Account each require (M9) — a
 * Transactions-domain business rule, not an Accounting Core one.
 *
 * **Strictly additive to M4's own Account validation.**
 * {@see PostingCommandAccountValidator}
 * already independently enforces existence, Tenant ownership, Active
 * state, and posting-eligibility for every Account a `PostingCommand`'s
 * lines reference, as part of the ordinary Posting Validation Pipeline
 * (AETS-007 §12) — this class duplicates none of that. It checks
 * exactly one additional fact M4 has no reason to know: whether an
 * Account's *type* is semantically appropriate for the role Income
 * recording asks it to play (the credit side must be a Revenue-type
 * Account; the debit side must be an Asset-type Account).
 *
 * **Runs before the Posting Command pipeline, not instead of it.** This
 * check happens first specifically so a semantically wrong Account
 * (for example, crediting an Asset account as "income") fails with a
 * Transactions-domain-specific, actionable
 * error, before ever reaching M4 — not because M4's own checks are
 * insufficient, but because M4 has no way to express this constraint at
 * all (it is generic across every future Accounting Command, not
 * specific to Income).
 *
 * **Cannot yet distinguish Cash/Bank from any other Asset Account** —
 * see {@see InvalidDepositAccountTypeException}'s
 * own docblock for why, and the M9 closure report for the tracked gap.
 */
final class IncomeAccountTypeValidator
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
    ) {}

    /**
     * @throws RejectedAccountReferenceException if either Account
     *                                           cannot be resolved for the given Tenant.
     * @throws InvalidIncomeAccountTypeException if the Income
     *                                           Account's type is not `Revenue`.
     * @throws InvalidDepositAccountTypeException if the Deposit
     *                                            Account's type is not `Asset`.
     */
    public function validate(RecordIncomeCommand $command): void
    {
        $incomeAccount = $this->accountRepository->findById($command->tenantId(), $command->incomeAccountId());

        if ($incomeAccount === null) {
            throw RejectedAccountReferenceException::forUnresolvedAccount($command->incomeAccountId());
        }

        if ($incomeAccount->type() !== AccountType::Revenue) {
            throw InvalidIncomeAccountTypeException::forNonRevenueAccount($command->incomeAccountId());
        }

        $depositAccount = $this->accountRepository->findById($command->tenantId(), $command->depositAccountId());

        if ($depositAccount === null) {
            throw RejectedAccountReferenceException::forUnresolvedAccount($command->depositAccountId());
        }

        if ($depositAccount->type() !== AccountType::Asset) {
            throw InvalidDepositAccountTypeException::forNonAssetAccount($command->depositAccountId());
        }
    }
}
