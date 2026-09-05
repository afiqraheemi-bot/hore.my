<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Exception;

use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * Thrown when a `PostingCommand` reuses an (TenantId, Idempotency Key)
 * pair already settled by a prior Journal, but is a materially
 * different logical request from it — a different proposed Journal
 * identity, different Journal Lines, different Account references, or
 * a different amount (AETS-007 §6.1, §15; `POST-004`, `POST-T021`).
 *
 * **The original Journal is never touched.** This is a pure rejection
 * of the *incoming* command — nothing about the Journal the original
 * use of this Idempotency Key already resolved to is read for mutation
 * or changed in any way here (`POST-T022`); this exception is thrown
 * strictly before any such attempt could occur.
 *
 * **Not the same thing as a fresh conflict on a different key.** A
 * *different* Idempotency Key for the same Tenant, even with an
 * otherwise identical logical request, is an entirely new command and
 * never reaches this exception (`POST-T023`) — only a reused pair with
 * a materially different payload does.
 */
final class RejectedConflictingIdempotencyReuseException extends \RuntimeException
{
    public static function forKey(TenantId $tenantId, IdempotencyKey $idempotencyKey): self
    {
        return new self(sprintf(
            'Idempotency Key "%s" for Tenant "%s" was already used for a materially different Posting Command.',
            $idempotencyKey->toString(),
            $tenantId->toString(),
        ));
    }
}
