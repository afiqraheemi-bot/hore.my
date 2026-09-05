<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Posting\Exception\RejectedJournalLineMismatchException;

/**
 * Validates that a `PostingCommand` referencing an existing Draft
 * Journal supplies exactly the same Journal Lines the Draft already
 * has persisted — same count, same content, same order — before any
 * persistence is attempted (AETS-004 §9; `JRN-005`, `JRN-006`).
 *
 * **An early boundary, not a new rule.** The current Journal domain
 * exposes no line-mutation API at all, and `JournalRepository::save()`
 * already enforces, at persistence time, that an existing Draft's
 * line set cannot silently change (M3-T10) — comparing incoming lines
 * against the persisted ones and rejecting on any difference. This
 * class enforces exactly that same, already-settled fact one step
 * earlier, at the Posting Command boundary, so a mismatch fails
 * before persistence is even attempted rather than only at write
 * time. It does not relax, duplicate as a competing rule, or in any
 * way change what `JournalRepository::save()` itself does — that
 * repository is not modified by this class and remains the final,
 * authoritative write-time guard regardless of whether this earlier
 * check ever runs.
 *
 * **Comparison, not mutation.** This class performs a pure,
 * read-only comparison between two already-in-memory line lists,
 * using `JournalLine`'s own existing value-equality contract for each
 * corresponding pair, plus an explicit count and order check. It
 * never mutates, rebuilds,
 * reorders, or replaces either list, never calls `Journal::post()` or
 * `JournalRepository::save()`, and performs no persistence, no
 * database transaction, no Account/Actor/Tenant/idempotency/Source
 * Fingerprint concern, and no Audit/Outbox work — every one of those
 * remains a separate, future responsibility.
 */
final class PostingCommandExistingDraftLineValidator
{
    /**
     * @throws RejectedJournalLineMismatchException if `$command`'s
     *                                              supplied Journal Lines do not exactly match `$existingDraft`'s
     *                                              persisted lines, in content or in order.
     */
    public function validate(PostingCommand $command, Journal $existingDraft): void
    {
        $commandLines = $command->lines();
        $draftLines = $existingDraft->lines();

        if (count($commandLines) !== count($draftLines)) {
            throw RejectedJournalLineMismatchException::forJournalId($existingDraft->id());
        }

        foreach ($commandLines as $index => $commandLine) {
            if (! $commandLine->equals($draftLines[$index])) {
                throw RejectedJournalLineMismatchException::forJournalId($existingDraft->id());
            }
        }
    }
}
