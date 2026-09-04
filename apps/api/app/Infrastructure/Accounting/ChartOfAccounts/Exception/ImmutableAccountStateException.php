<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\ChartOfAccounts\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;

/**
 * Thrown when {@see AccountRepository::save()} is given an Account that
 * reuses an existing {@see AccountId} but disagrees with the
 * already-persisted row on a field the current Account domain exposes
 * no valid business operation to change: TenantId, Account Code,
 * Account Name, Account Type, or Account Origin.
 *
 * This is not a database-constraint failure — nothing in the schema
 * forbids it — it is a repository-owned guard against becoming a
 * backdoor around domain invariants the Account aggregate itself
 * already protects (AETS-005 §7, §8, §9, §10, §15, §16, §17; `COA-002`,
 * `COA-004`, `COA-013`, `COA-017`). The attempted write never reaches
 * the database and the existing row is left exactly as it was.
 *
 * Deliberately does not report the attempted (invalid) value or any
 * other database detail beyond the field name and the Account/Tenant
 * identifiers already known to the caller.
 */
final class ImmutableAccountStateException extends \RuntimeException
{
    public static function forField(TenantId $tenantId, AccountId $accountId, string $fieldName): self
    {
        return new self(sprintf(
            'Account "%s" in Tenant "%s" already exists with a different %s. '
            .'This repository does not persist changes to immutable Account state.',
            $accountId->toString(),
            $tenantId->toString(),
            $fieldName,
        ));
    }
}
