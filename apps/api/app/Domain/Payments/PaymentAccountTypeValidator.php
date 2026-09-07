<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Payments\Exception\InvalidPaymentDepositAccountTypeException;
use App\Domain\Payments\Exception\InvalidPaymentReceivableAccountTypeException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Income\IncomeAccountTypeValidator;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;

/**
 * Validates the semantic Account Type a Payment's deposit Account and
 * Receivable Account each require (M21) — both must be Asset Accounts
 * (cash/bank receiving the money, and the AR control account being
 * reduced). Mirrors
 * {@see IncomeAccountTypeValidator}'s
 * own reasoning exactly.
 */
final class PaymentAccountTypeValidator
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
    ) {}

    /**
     * @throws RejectedAccountReferenceException if either
     *                                           Account cannot be resolved for the given Tenant.
     * @throws InvalidPaymentDepositAccountTypeException if the
     *                                                   deposit Account's type is not `Asset`.
     * @throws InvalidPaymentReceivableAccountTypeException if the
     *                                                      Receivable Account's type is not `Asset`.
     */
    public function validate(TenantId $tenantId, AccountId $depositAccountId, AccountId $receivableAccountId): void
    {
        $depositAccount = $this->accountRepository->findById($tenantId, $depositAccountId);

        if ($depositAccount === null) {
            throw RejectedAccountReferenceException::forUnresolvedAccount($depositAccountId);
        }

        if ($depositAccount->type() !== AccountType::Asset) {
            throw InvalidPaymentDepositAccountTypeException::forNonAssetAccount($depositAccountId);
        }

        $receivableAccount = $this->accountRepository->findById($tenantId, $receivableAccountId);

        if ($receivableAccount === null) {
            throw RejectedAccountReferenceException::forUnresolvedAccount($receivableAccountId);
        }

        if ($receivableAccount->type() !== AccountType::Asset) {
            throw InvalidPaymentReceivableAccountTypeException::forNonAssetAccount($receivableAccountId);
        }
    }
}
