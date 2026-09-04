<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\ChartOfAccounts\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountCode;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;

/**
 * Thrown when {@see AccountRepository::save()} attempts to persist an
 * Account whose (Tenant, Account Code) pair already belongs to a
 * different Account — surfaced from the real `UNIQUE (tenant_id,
 * account_code)` PostgreSQL constraint (AETS-005 §8, `COA-003`), never
 * from an application-level pre-check the repository invents on its
 * own. The database remains the authoritative, race-safe source of
 * this guarantee; this exception only translates its rejection into a
 * hore.my-owned type instead of leaking a raw `QueryException`.
 *
 * This is deliberately origin-agnostic: the same constraint, and so
 * this same exception, rejects a collision regardless of whether
 * either Account is a System or a User-Created Account (`COA-T056`) —
 * uniqueness at the database boundary does not distinguish Origin.
 */
final class DuplicateAccountCodeException extends \RuntimeException
{
    public static function forCode(TenantId $tenantId, AccountCode $accountCode): self
    {
        return new self(sprintf(
            'Account Code "%s" already exists for Tenant "%s".',
            $accountCode->toString(),
            $tenantId->toString(),
        ));
    }
}
