<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Journal\Exception;

use App\Domain\Accounting\Journal\JournalId;
use App\Infrastructure\Accounting\Journal\JournalRepository;

/**
 * Thrown when {@see JournalRepository::save()} attempts to insert a
 * brand-new Journal whose JournalId collides with one a genuinely
 * concurrent `save()` call has already committed — surfaced from the
 * real `journals_pkey` or `journals_tenant_id_journal_id_unique`
 * PostgreSQL constraint (M3-T9), never from an application-level
 * pre-check this repository invents on its own.
 *
 * **Distinct from {@see ImmutableJournalStateException}.** That
 * exception guards an *existing* persisted Journal against a
 * disagreeing rewrite — a repository-owned invariant, not a database
 * constraint failure. This exception is the opposite case: two
 * `save()` calls both observed no existing row (`$existingHeader ===
 * null`) before either committed, so neither ever reached the
 * immutability checks at all — the database's own uniqueness
 * constraint is the only thing that can, and does, catch this race.
 *
 * **Why this is safe to translate.** Unlike most `QueryException`
 * instances a caller cannot safely reinterpret without risking a
 * masked bug (an FK violation, a `CHECK` violation, a lock timeout, a
 * serialization failure), a genuine JournalId collision on a *fresh*
 * insert can only mean one thing: another concurrent `save()` for the
 * exact same identity has just won. That is a legitimate, expected
 * outcome a caller (the Posting transactional executor) can safely
 * recover from by re-resolving what actually happened — never a
 * signal to retry the same write blindly.
 */
final class DuplicateJournalIdentityException extends \RuntimeException
{
    public static function forJournalId(JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" was concurrently created by another transaction before this one could commit.',
            $journalId->toString(),
        ));
    }
}
