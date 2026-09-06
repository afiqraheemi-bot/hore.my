<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Transfer;

use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;
use App\Domain\Transactions\Income\IncomeAccountTypeValidator;
use App\Domain\Transactions\Transfer\Exception\InvalidTransferAccountTypeException;
use App\Domain\Transactions\Transfer\Exception\SameAccountTransferException;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;

/**
 * Validates the semantic Account Type a `RecordTransferCommand`'s
 * Source Account and Destination Account each require, and that the
 * two Accounts are not the same Account (M14) — a Transactions-domain
 * business rule, not an Accounting Core one.
 *
 * **Strictly additive to M4's own Account validation.**
 * {@see PostingCommandAccountValidator} already independently enforces
 * existence, Tenant ownership, Active state, and posting-eligibility
 * for every Account a `PostingCommand`'s lines reference, as part of
 * the ordinary Posting Validation Pipeline (AETS-007 §12) — this class
 * duplicates none of that. It checks facts M4 has no reason to know:
 * whether each Account's *type* is semantically appropriate for a
 * Transfer (Asset or Liability only — see
 * {@see InvalidTransferAccountTypeException}'s own docblock), and
 * whether the two Accounts are distinct.
 *
 * **Runs before the Posting Command pipeline, not instead of it** —
 * mirroring {@see IncomeAccountTypeValidator}'s
 * own sequencing exactly.
 */
final class TransferAccountTypeValidator
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
    ) {}

    /**
     * @throws RejectedAccountReferenceException if either Account
     *                                           cannot be resolved for the given Tenant.
     * @throws InvalidTransferAccountTypeException if either Account's
     *                                             type is neither Asset nor Liability.
     * @throws SameAccountTransferException if both Accounts are the
     *                                      same Account.
     */
    public function validate(RecordTransferCommand $command): void
    {
        if ($command->sourceAccountId()->equals($command->destinationAccountId())) {
            throw SameAccountTransferException::forAccount($command->sourceAccountId());
        }

        $sourceAccount = $this->accountRepository->findById($command->tenantId(), $command->sourceAccountId());

        if ($sourceAccount === null) {
            throw RejectedAccountReferenceException::forUnresolvedAccount($command->sourceAccountId());
        }

        if ($sourceAccount->type() !== AccountType::Asset && $sourceAccount->type() !== AccountType::Liability) {
            throw InvalidTransferAccountTypeException::forSourceAccount($command->sourceAccountId());
        }

        $destinationAccount = $this->accountRepository->findById($command->tenantId(), $command->destinationAccountId());

        if ($destinationAccount === null) {
            throw RejectedAccountReferenceException::forUnresolvedAccount($command->destinationAccountId());
        }

        if ($destinationAccount->type() !== AccountType::Asset && $destinationAccount->type() !== AccountType::Liability) {
            throw InvalidTransferAccountTypeException::forDestinationAccount($command->destinationAccountId());
        }
    }
}
