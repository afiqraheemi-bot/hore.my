<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Exception;

use App\Domain\Shared\Tenancy\TenantId;

/**
 * Thrown when a `PostingCommand`'s Financial Date falls on or before
 * the Tenant's current closed-period watermark (AETS-014) — the Period
 * that date belongs to has already been closed, and closing a Period
 * is a one-way commitment: no ordinary Posting Command may add,
 * change, or backdate a ledger effect into it afterward. A correction
 * to an already-closed Period requires reopening it first (deferred,
 * AETS-014 §2.2) — never a new ordinary posting.
 *
 * The Period-closing Posting Command itself is never rejected by this
 * check: it is dated exactly at the new watermark being established,
 * and the check compares against the watermark as it stood *before*
 * this transaction's own closure record is written.
 */
final class RejectedClosedPeriodPostingException extends \RuntimeException
{
    public static function forDateWithinClosedPeriod(TenantId $tenantId, \DateTimeImmutable $financialDate, \DateTimeImmutable $closedThroughDate): self
    {
        return new self(sprintf(
            'Tenant "%s" cannot post a Journal with Financial Date "%s" — the Accounting Period '
            .'is closed through "%s". Reopen the Period before posting into it (not yet supported).',
            $tenantId->toString(),
            $financialDate->format('Y-m-d'),
            $closedThroughDate->format('Y-m-d'),
        ));
    }
}
