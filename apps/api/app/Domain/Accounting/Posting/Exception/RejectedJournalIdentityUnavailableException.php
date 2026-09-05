<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Exception;

use App\Domain\Accounting\Journal\JournalId;

/**
 * Thrown when a Posting Command's proposed JournalId is not found for
 * the command's own Tenant, but that identity is already reserved
 * somewhere else — a different Tenant's Journal already exists under
 * it (AETS-007 §7, §11, §21; `POST-001`).
 *
 * **Not the same thing as "fresh."** `JournalId` is a single, global
 * primary key (M3-T9) — it is not scoped per Tenant. A command
 * proposing an identity another Tenant's Journal already occupies
 * cannot legitimately be treated as a fresh, not-yet-used identity:
 * assembling and posting against it would either collide with that
 * other Journal at the database level, or — if it somehow didn't —
 * would still represent this Tenant attempting to claim an identity
 * it does not own. Both outcomes are rejected here, before any
 * persistent effect is attempted.
 *
 * **No cross-tenant disclosure.** Per AETS-007 §21, a Posting Command
 * failing at the tenant-ownership step must never be "revealed to the
 * caller as anything more specific than a tenant-ownership failure
 * category that would leak the existence of another Tenant's record."
 * This exception's message names only the rejected identity itself —
 * never the owning Tenant, never any detail about that other Journal,
 * and never even a direct confirmation that the identity belongs to
 * *another Tenant* specifically, as opposed to any other reason it
 * might be unavailable.
 *
 * **Distinct from `DuplicateJournalIdentityException`.**
 * That exception is a write-race signal — two genuinely concurrent
 * `save()` calls for the same brand-new identity, detected only once
 * an `INSERT` is actually attempted, and safely recoverable by
 * re-resolving what happened. This exception is a normal, read-only,
 * pre-validation rejection — the identity was never available in the
 * first place, there is no race to recover from, and no retry can
 * ever make it succeed.
 */
final class RejectedJournalIdentityUnavailableException extends \RuntimeException
{
    public static function forJournalId(JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal identity "%s" is not available for a fresh Journal.',
            $journalId->toString(),
        ));
    }
}
