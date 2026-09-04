<?php

declare(strict_types=1);

namespace App\Domain\Accounting\ChartOfAccounts\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * Thrown when an Account/parent Account pairing violates AETS-005
 * §12's hierarchy rules (`COA-006`, `COA-007`): an Account referencing
 * itself as its own parent, a parent belonging to a different Tenant,
 * a parent assignment that would make an Account its own ancestor
 * directly or transitively, or a parent assignment whose ancestry
 * cannot be fully proven cycle-free from the Accounts supplied —
 * cycle validation fails closed (M2-T5.2): an incomplete hierarchy
 * context is never treated as proof of safety.
 */
final class InvalidAccountHierarchyException extends \InvalidArgumentException
{
    public static function forSelfParenting(AccountId $accountId): self
    {
        return new self(sprintf(
            'Account "%s" cannot be its own parent.',
            $accountId->toString(),
        ));
    }

    public static function forCrossTenantParent(TenantId $childTenantId, TenantId $parentTenantId): self
    {
        return new self(sprintf(
            'A parent Account must belong to the same Tenant as its child: child belongs to "%s", parent belongs to "%s".',
            $childTenantId->toString(),
            $parentTenantId->toString(),
        ));
    }

    public static function forCycle(AccountId $accountId): self
    {
        return new self(sprintf(
            'Assigning this parent would make Account "%s" its own ancestor, directly or transitively.',
            $accountId->toString(),
        ));
    }

    /**
     * Thrown when a proposed parent's ancestry chain references an
     * Account not present in the supplied graph/context — the
     * assignment cannot be proven cycle-free, so it is rejected rather
     * than accepted as safe by assumption (`COA-006`).
     */
    public static function forIncompleteAncestryContext(AccountId $accountId, AccountId $missingAncestorId): self
    {
        return new self(sprintf(
            'Cannot prove the proposed parent assignment for Account "%s" is cycle-free: ancestor "%s" is not present in the supplied Account context.',
            $accountId->toString(),
            $missingAncestorId->toString(),
        ));
    }
}
