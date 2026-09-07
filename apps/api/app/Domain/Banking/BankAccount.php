<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Banking\Exception\InvalidBankAccountNameException;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * The BankAccount aggregate root (M17) — a Tenant's own real-world bank
 * account, linked to exactly one Asset Account in its Chart of Accounts
 * (its Account Type is validated by
 * {@see BankAccountLinkedAccountValidator}, not by this class itself,
 * mirroring how `Account` itself never validates its own Tenant's
 * existence).
 *
 * **Reference data, not a transactional aggregate** — mirrors `Account`
 * (AETS-005 §6) exactly: registering a BankAccount produces no Journal
 * and carries no lifecycle event stream.
 *
 * **`accountNumberLast4` only** — see the owning migration's own
 * docblock for the data-minimisation reasoning.
 */
final class BankAccount
{
    private const MAX_BANK_NAME_LENGTH = 100;

    private function __construct(
        private readonly BankAccountId $id,
        private readonly TenantId $tenantId,
        private readonly AccountId $linkedAccountId,
        private readonly string $bankName,
        private readonly ?string $accountNumberLast4,
        private readonly bool $active,
    ) {}

    /**
     * @throws InvalidBankAccountNameException if `$bankName` is empty
     *                                         or exceeds the defensive length bound.
     */
    public static function register(
        BankAccountId $id,
        TenantId $tenantId,
        AccountId $linkedAccountId,
        string $bankName,
        ?string $accountNumberLast4,
    ): self {
        if ($bankName === '') {
            throw InvalidBankAccountNameException::forEmpty();
        }

        if (strlen($bankName) > self::MAX_BANK_NAME_LENGTH) {
            throw InvalidBankAccountNameException::forExceedingMaxLength(self::MAX_BANK_NAME_LENGTH);
        }

        return new self($id, $tenantId, $linkedAccountId, $bankName, $accountNumberLast4, true);
    }

    /**
     * Reconstruct an already-registered BankAccount from persisted
     * state — no validation beyond each field's own Value Object,
     * mirroring `Account::reconstitute()`'s own reasoning.
     */
    public static function reconstitute(
        BankAccountId $id,
        TenantId $tenantId,
        AccountId $linkedAccountId,
        string $bankName,
        ?string $accountNumberLast4,
        bool $active,
    ): self {
        return new self($id, $tenantId, $linkedAccountId, $bankName, $accountNumberLast4, $active);
    }

    public function id(): BankAccountId
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function linkedAccountId(): AccountId
    {
        return $this->linkedAccountId;
    }

    public function bankName(): string
    {
        return $this->bankName;
    }

    public function accountNumberLast4(): ?string
    {
        return $this->accountNumberLast4;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }
}
