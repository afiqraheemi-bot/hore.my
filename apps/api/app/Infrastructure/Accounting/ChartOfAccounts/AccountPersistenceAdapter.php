<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\Account;
use App\Domain\Accounting\ChartOfAccounts\AccountCode;
use App\Domain\Accounting\ChartOfAccounts\AccountHierarchyPolicy;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountName;
use App\Domain\Accounting\ChartOfAccounts\AccountOrigin;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountCodeException;
use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountIdException;
use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountNameException;
use App\Domain\Shared\Tenancy\Exception\InvalidTenantIdException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\ChartOfAccounts\Exception\InvalidPersistedAccountOriginException;
use App\Infrastructure\Accounting\ChartOfAccounts\Exception\InvalidPersistedAccountTypeException;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;

/**
 * Maps hore.my's Account aggregate to and from a persistence-safe
 * representation (AETS-005 §6; ATS-005 §23's persistence
 * expectations).
 *
 * This is the only code with knowledge of both the Account domain
 * contract and the primitive shape a future storage layer would
 * persist — Account itself remains persistence-agnostic, exactly as
 * {@see MoneyPersistenceAdapter}
 * already establishes for Money. It performs no I/O of its own: it
 * produces and consumes a plain array shape, leaving the actual
 * read/write to its caller. No repository or business table exists
 * yet — this adapter defines the mapping contract those will
 * eventually rely on.
 *
 * Normal Balance is never part of the persisted row: it is always
 * re-derived from Account Type on read, through
 * {@see Account::reconstitute()}, exactly as it is always derived on
 * construction — never an independent source of truth (AETS-005 §11,
 * `COA-005`). No monetary balance, debit total, credit total, or other
 * authoritative ledger figure is part of this shape either (AETS-005
 * §6, `COA-012`) — an Account carries none, and this adapter invents
 * none.
 *
 * **Account Type and Account Origin persistence.** AETS-005 does not
 * lock a database representation for either Account Type (§10, §25)
 * or Account Origin (§15, §16). Rather than convert either domain enum
 * into a backed enum merely to support persistence, this adapter
 * privately translates each to and from its own PHP enum case name
 * (`AccountType::Asset->name === 'Asset'`, `AccountOrigin::System->name
 * === 'System'`, and so on) — a deterministic, self-describing
 * representation already inherent to the type, not an invented
 * numbering or abbreviation scheme. Both translations are confined
 * entirely to this adapter; neither domain enum is backed or aware of
 * either translation.
 *
 * **Hierarchy note.** The read path restores a persisted `parentId`
 * through {@see Account::reconstitute()}, which performs no hierarchy
 * validation of its own (M2-T5.3) — restoring a stored reference is
 * not a business parent-assignment decision, and this adapter does not
 * call, weaken, or duplicate
 * {@see AccountHierarchyPolicy}.
 * Full hierarchy integrity across a reconstructed graph remains a
 * future repository/orchestration-layer responsibility.
 */
final class AccountPersistenceAdapter
{
    /**
     * Extract an Account's persistence-safe representation.
     *
     * Every value is exact and unmodified — no trimming, no
     * normalization, no derived Normal Balance field, and no balance
     * field of any kind.
     *
     * @return array{
     *     tenant_id: string,
     *     account_id: string,
     *     account_code: string,
     *     account_name: string,
     *     account_type: string,
     *     account_origin: string,
     *     active: bool,
     *     posting_eligible: bool,
     *     parent_id: string|null,
     * }
     */
    public function toPersistedRow(Account $account): array
    {
        return [
            'tenant_id' => $account->tenantId()->toString(),
            'account_id' => $account->id()->toString(),
            'account_code' => $account->code()->toString(),
            'account_name' => $account->name()->toString(),
            'account_type' => self::toPersistedAccountType($account->type()),
            'account_origin' => self::toPersistedAccountOrigin($account->origin()),
            'active' => $account->isActive(),
            'posting_eligible' => $account->isPostingEligible(),
            'parent_id' => $account->parentId()?->toString(),
        ];
    }

    /**
     * Reconstruct an Account from a persisted row, through
     * {@see Account::reconstitute()} and every Value Object's own
     * validated factory — a raw database value is never treated as
     * trusted domain state. A malformed `tenant_id`, `account_id`,
     * `account_code`, or `account_name` fails loudly through that
     * Value Object's own existing exception; a malformed
     * `account_type` fails loudly through
     * {@see InvalidPersistedAccountTypeException}; a malformed
     * `account_origin` fails loudly through
     * {@see InvalidPersistedAccountOriginException}. Normal Balance is
     * never read from the row — it is always re-derived from the
     * reconstructed Account Type.
     *
     * @param  array{
     *     tenant_id: string,
     *     account_id: string,
     *     account_code: string,
     *     account_name: string,
     *     account_type: string,
     *     account_origin: string,
     *     active: bool,
     *     posting_eligible: bool,
     *     parent_id: string|null,
     * }  $row
     *
     * @throws InvalidTenantIdException if `tenant_id` is not canonical.
     * @throws InvalidAccountIdException if `account_id` or a
     *                                   present `parent_id` is not canonical.
     * @throws InvalidAccountCodeException if `account_code` is
     *                                     not canonical.
     * @throws InvalidAccountNameException if `account_name` is
     *                                     not canonical.
     * @throws InvalidPersistedAccountTypeException if `account_type` is not a canonical Account Type.
     * @throws InvalidPersistedAccountOriginException if `account_origin` is not a canonical Account Origin.
     */
    public function fromPersistedRow(array $row): Account
    {
        return Account::reconstitute(
            TenantId::of($row['tenant_id']),
            AccountId::of($row['account_id']),
            AccountCode::of($row['account_code']),
            AccountName::of($row['account_name']),
            self::fromPersistedAccountType($row['account_type']),
            $row['active'],
            $row['posting_eligible'],
            self::fromPersistedAccountOrigin($row['account_origin']),
            $row['parent_id'] === null ? null : AccountId::of($row['parent_id']),
        );
    }

    private static function toPersistedAccountType(AccountType $type): string
    {
        return $type->name;
    }

    private static function fromPersistedAccountType(string $value): AccountType
    {
        foreach (AccountType::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw InvalidPersistedAccountTypeException::forValue($value);
    }

    private static function toPersistedAccountOrigin(AccountOrigin $origin): string
    {
        return $origin->name;
    }

    private static function fromPersistedAccountOrigin(string $value): AccountOrigin
    {
        foreach (AccountOrigin::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw InvalidPersistedAccountOriginException::forValue($value);
    }
}
