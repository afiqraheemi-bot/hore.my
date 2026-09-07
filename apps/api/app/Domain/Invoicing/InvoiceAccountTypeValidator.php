<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Invoicing\Exception\InvalidReceivableAccountTypeException;
use App\Domain\Invoicing\Exception\InvalidRevenueAccountTypeException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Income\IncomeAccountTypeValidator;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;

/**
 * Validates the semantic Account Type an Invoice's Receivable Account
 * and Revenue Account each require (M20) — a Transactions-domain
 * business rule, not an Accounting Core one, mirroring
 * {@see IncomeAccountTypeValidator}'s
 * own reasoning exactly.
 *
 * **Runs at Draft creation/edit time, not deferred to Issue time.**
 * Unlike Income (which has no Draft phase and validates immediately
 * before posting), an Invoice's Receivable/Revenue Accounts are chosen
 * while still Draft — checking them then gives immediate feedback,
 * before the user has finished composing line items, and before
 * {@see InvoiceIssuingService} ever runs.
 */
final class InvoiceAccountTypeValidator
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
    ) {}

    /**
     * @throws RejectedAccountReferenceException if either Account
     *                                           cannot be resolved for the given Tenant.
     * @throws InvalidReceivableAccountTypeException if the Receivable
     *                                               Account's type is not `Asset`.
     * @throws InvalidRevenueAccountTypeException if the Revenue
     *                                            Account's type is not `Revenue`.
     */
    public function validate(TenantId $tenantId, AccountId $receivableAccountId, AccountId $revenueAccountId): void
    {
        $receivableAccount = $this->accountRepository->findById($tenantId, $receivableAccountId);

        if ($receivableAccount === null) {
            throw RejectedAccountReferenceException::forUnresolvedAccount($receivableAccountId);
        }

        if ($receivableAccount->type() !== AccountType::Asset) {
            throw InvalidReceivableAccountTypeException::forNonAssetAccount($receivableAccountId);
        }

        $revenueAccount = $this->accountRepository->findById($tenantId, $revenueAccountId);

        if ($revenueAccount === null) {
            throw RejectedAccountReferenceException::forUnresolvedAccount($revenueAccountId);
        }

        if ($revenueAccount->type() !== AccountType::Revenue) {
            throw InvalidRevenueAccountTypeException::forNonRevenueAccount($revenueAccountId);
        }
    }
}
