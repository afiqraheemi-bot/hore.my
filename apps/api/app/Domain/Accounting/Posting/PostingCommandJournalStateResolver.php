<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\JournalState;
use App\Domain\Accounting\Posting\Exception\RejectedJournalStateException;
use App\Infrastructure\Accounting\Journal\JournalRepository;

/**
 * Resolves whether a `PostingCommand`'s proposed Journal identity is
 * fresh (not yet persisted) or references an existing Draft Journal,
 * rejecting outright if it references an existing Posted Journal
 * (AETS-007 §11, §14 step 3; `POST-018`).
 *
 * **The state-resolution boundary only.** This class answers exactly
 * one question — "what, if anything, already exists under this
 * command's proposed identity, for this Tenant?" — and nothing else.
 * It does not assemble a candidate Journal (`DraftJournalAssembler`'s
 * job, deliberately not called from here), does not compare the
 * command's supplied lines against an existing Draft's persisted
 * lines, does not decide how an existing Draft is updated or
 * replaced, does not perform the Draft -> Posted transition, and
 * calls neither `Journal::post()` nor `JournalRepository::save()`. It
 * performs no mutation and no persistence of any kind — a single
 * read-only lookup is its entire effect.
 *
 * **Tenant-scoped, by the existing repository contract alone.**
 * `JournalRepository::findById(TenantId, JournalId)` is already
 * tenant-scoped — it returns `null` both when no Journal exists under
 * that identifier at all, and when one exists only under a
 * *different* Tenant. This resolver relies on that existing behavior
 * exactly as it stands, exactly as `PostingCommandAccountValidator`
 * already does for Account lookups: it introduces no second,
 * tenant-unscoped lookup, so a wrong-Tenant Journal reference is
 * observationally identical to a fresh identity here — safely, since
 * `journal_id` remains a real, database-enforced unique constraint
 * (defense in depth) if that identity were ever actually persisted
 * against.
 */
final class PostingCommandJournalStateResolver
{
    public function __construct(
        private readonly JournalRepository $journalRepository,
    ) {}

    /**
     * @throws RejectedJournalStateException if a Journal already
     *                                       exists under the command's proposed identity, for its Tenant,
     *                                       and is already Posted.
     */
    public function resolve(PostingCommand $command): PostingCommandJournalState
    {
        $journal = $this->journalRepository->findById($command->tenantId(), $command->journalId());

        if ($journal === null) {
            return PostingCommandJournalState::fresh();
        }

        if ($journal->state() === JournalState::Posted) {
            throw RejectedJournalStateException::forNonDraftJournal($command->journalId());
        }

        return PostingCommandJournalState::existingDraft($journal);
    }
}
