<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Posting\Exception;

use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * Thrown when `PostingIdempotencyRepository::record()` attempts to
 * insert a (Tenant, Idempotency Key) mapping that already exists —
 * surfaced from the real `PRIMARY KEY (tenant_id, idempotency_key)`
 * PostgreSQL constraint on `posting_idempotency_keys` (M4-T15,
 * AETS-007 §15), never from an application-level pre-check the
 * repository invents on its own.
 *
 * This is deliberately silent on *why* the pair already exists — it
 * carries no opinion on whether the caller is safely replaying an
 * identical prior request or has produced a materially different,
 * conflicting one. That distinction is a `PostingCommand` logical-
 * equivalence decision this repository has no part in (see
 * `PostingCommandLogicalEquivalence` and its own docblock); a caller
 * who needs to tell the two apart must look up the existing mapping's
 * Journal separately and compare.
 */
final class DuplicatePostingIdempotencyKeyException extends \RuntimeException
{
    public static function forKey(TenantId $tenantId, IdempotencyKey $idempotencyKey): self
    {
        return new self(sprintf(
            'A Posting idempotency mapping already exists for Tenant "%s" and Idempotency Key "%s".',
            $tenantId->toString(),
            $idempotencyKey->toString(),
        ));
    }
}
