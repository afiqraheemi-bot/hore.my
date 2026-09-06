<?php

declare(strict_types=1);

namespace App\Domain\Transactions\OwnerEquity;

use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;
use App\Domain\Transactions\OwnerEquity\Exception\InvalidCashAccountTypeException;
use App\Domain\Transactions\OwnerEquity\Exception\InvalidEquityAccountTypeException;
use App\Domain\Transactions\Transfer\TransferAccountTypeValidator;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;

/**
 * Validates the semantic Account Type a
 * `RecordOwnerEquityTransactionCommand`'s Equity Account and Cash
 * Account each require (M15) — a Transactions-domain business rule,
 * not an Accounting Core one. The same two constraints apply
 * regardless of {@see OwnerEquityMovementType} (Contribution or
 * Drawing): the Equity Account must be Account Type `Equity`, the Cash
 * Account must be Account Type `Asset`. Since these two Account Types
 * are mutually exclusive per Account, the two Accounts can never
 * collide — unlike {@see TransferAccountTypeValidator},
 * this class has no same-account check to perform.
 *
 * **Strictly additive to M4's own Account validation.**
 * {@see PostingCommandAccountValidator} already independently enforces
 * existence, Tenant ownership, Active state, and posting-eligibility
 * for every Account a `PostingCommand`'s lines reference — this class
 * duplicates none of that.
 */
final class OwnerEquityAccountTypeValidator
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
    ) {}

    /**
     * @throws RejectedAccountReferenceException if either Account
     *                                           cannot be resolved for the given Tenant.
     * @throws InvalidEquityAccountTypeException if the Equity Account's
     *                                           type is not Equity.
     * @throws InvalidCashAccountTypeException if the Cash Account's
     *                                         type is not Asset.
     */
    public function validate(RecordOwnerEquityTransactionCommand $command): void
    {
        $equityAccount = $this->accountRepository->findById($command->tenantId(), $command->equityAccountId());

        if ($equityAccount === null) {
            throw RejectedAccountReferenceException::forUnresolvedAccount($command->equityAccountId());
        }

        if ($equityAccount->type() !== AccountType::Equity) {
            throw InvalidEquityAccountTypeException::forAccount($command->equityAccountId());
        }

        $cashAccount = $this->accountRepository->findById($command->tenantId(), $command->cashAccountId());

        if ($cashAccount === null) {
            throw RejectedAccountReferenceException::forUnresolvedAccount($command->cashAccountId());
        }

        if ($cashAccount->type() !== AccountType::Asset) {
            throw InvalidCashAccountTypeException::forAccount($command->cashAccountId());
        }
    }
}
