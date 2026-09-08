<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Exception;

use App\Domain\Accounting\Posting\Exception\RejectedConflictingIdempotencyReuseException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * Thrown when a Task-creation request reuses a (TenantId,
 * Idempotency-Key) pair already settled by a prior Task, but proposes
 * a materially different Proposal from it — a different Command type,
 * amount, transaction date, Account reference, or description.
 * Mirrors {@see RejectedConflictingIdempotencyReuseException}
 * exactly, at the Task-creation boundary (ADR-0009, WTS-001 §5).
 *
 * **The original Task is never touched.** This is a pure rejection of
 * the *incoming* request — nothing about the Task the original use of
 * this Idempotency Key already resolved to is read for mutation or
 * changed in any way here; this exception is thrown strictly before
 * any such attempt could occur.
 */
final class TaskSubmissionConflictException extends \RuntimeException
{
    public static function forKey(TenantId $tenantId, IdempotencyKey $idempotencyKey): self
    {
        return new self(sprintf(
            'Idempotency Key "%s" for Tenant "%s" was already used to submit a materially different Task.',
            $idempotencyKey->toString(),
            $tenantId->toString(),
        ));
    }
}
