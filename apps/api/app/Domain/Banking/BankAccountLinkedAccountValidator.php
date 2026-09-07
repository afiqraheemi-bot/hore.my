<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Banking\Exception\InvalidLinkedAccountTypeException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\OwnerEquity\OwnerEquityAccountTypeValidator;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;

/**
 * Validates that a BankAccount's linked Account exists for the given
 * Tenant and has Account Type `Asset` (M17) — a Banking-domain business
 * rule, not an Accounting Core one, mirroring
 * {@see OwnerEquityAccountTypeValidator}'s
 * own reasoning and sequencing exactly.
 */
final class BankAccountLinkedAccountValidator
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
    ) {}

    /**
     * @throws RejectedAccountReferenceException if the Account cannot
     *                                           be resolved for the given Tenant.
     * @throws InvalidLinkedAccountTypeException if the Account's type
     *                                           is not Asset.
     */
    public function validate(TenantId $tenantId, AccountId $accountId): void
    {
        $account = $this->accountRepository->findById($tenantId, $accountId);

        if ($account === null) {
            throw RejectedAccountReferenceException::forUnresolvedAccount($accountId);
        }

        if ($account->type() !== AccountType::Asset) {
            throw InvalidLinkedAccountTypeException::forAccount($accountId);
        }
    }
}
