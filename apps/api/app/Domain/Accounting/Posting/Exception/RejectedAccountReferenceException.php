<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountId;

/**
 * Thrown when a Posting Command's proposed Journal Line references an
 * Account that fails Account validation (AETS-007 §12, extending
 * AETS-005 §19's four-step sequence).
 *
 * **Three factory methods, not four — a deliberate, reported
 * deviation from AETS-007 §18's literal wording.** AETS-007 §18
 * states that "an Account that does not exist, does not belong to the
 * Tenant, is Inactive, or is not posting-eligible" must each be "its
 * own category, not merged." The existing, already-committed
 * `AccountRepository::findById(TenantId, AccountId)` deliberately
 * returns `null` for both "does not exist" and "belongs to a
 * different Tenant" alike (`COA-001`) — it never reveals whether a
 * given identifier belongs to *another* Tenant, which would itself be
 * a cross-tenant existence leak `AccountRepository`'s own docblock and
 * `COA-001` exist specifically to prevent. Distinguishing those two
 * cases would require either a new, tenant-unscoped lookup (a
 * cross-tenant existence check this task explicitly forbids
 * inventing) or weakening `findById()`'s own tenant-isolation
 * guarantee. Neither is acceptable, so this exception merges
 * "unresolved" (covering both "does not exist" and "wrong Tenant")
 * into {@see forUnresolvedAccount()} — satisfying ATS-007's own
 * `POST-T047`/`POST-T048` wording exactly (each requires only that
 * the command "is rejected," neither requires the two be
 * distinguishable from each other) while preserving the repository's
 * security property untouched. Inactive and non-posting-eligible
 * remain fully separate categories, satisfying AETS-007 §18 for those
 * two. This asymmetry is a reported spec/repository tension, not a
 * silent narrowing — see the task this class was implemented under
 * for the full analysis.
 */
final class RejectedAccountReferenceException extends \RuntimeException
{
    public static function forUnresolvedAccount(AccountId $accountId): self
    {
        return new self(sprintf(
            'Account "%s" could not be resolved for this Tenant.',
            $accountId->toString(),
        ));
    }

    public static function forInactiveAccount(AccountId $accountId): self
    {
        return new self(sprintf(
            'Account "%s" is Inactive and cannot accept a new posting.',
            $accountId->toString(),
        ));
    }

    public static function forNonPostingEligibleAccount(AccountId $accountId): self
    {
        return new self(sprintf(
            'Account "%s" is not posting-eligible.',
            $accountId->toString(),
        ));
    }
}
