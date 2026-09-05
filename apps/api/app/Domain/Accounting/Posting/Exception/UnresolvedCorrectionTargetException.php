<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Exception;

use App\Domain\Accounting\Journal\JournalId;

/**
 * Thrown when a `ReverseJournalCommand` or `ReplaceJournalCommand`
 * names a JournalId that cannot be resolved for the requesting Tenant
 * (M5).
 *
 * **"Unresolved" merges "does not exist" and "belongs to a different
 * Tenant", deliberately.** `JournalRepository::findById(TenantId,
 * JournalId)` is already tenant-scoped — it returns `null` for both
 * cases alike (`JRN-001`) — mirroring the identical, already-committed
 * asymmetry {@see RejectedAccountReferenceException} documents for
 * `AccountRepository::findById()`. This exception does not, and must
 * not, introduce a second, tenant-unscoped lookup to tell the two
 * apart, since doing so would itself leak cross-tenant Journal
 * existence.
 *
 * **Not the same thing as an invalid-but-resolved target.** A Journal
 * that resolves successfully but is not eligible to be reversed or
 * replaced (Draft, already a correction, or not a Reversal) is
 * rejected by `Journal::reverse()` or `Journal::createReplacement()`
 * themselves, via their own typed exceptions — never this one.
 */
final class UnresolvedCorrectionTargetException extends \RuntimeException
{
    public static function forJournalId(JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" could not be resolved for this Tenant.',
            $journalId->toString(),
        ));
    }
}
