<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\JournalState;
use App\Domain\Accounting\Posting\Exception\RejectedJournalIdentityUnavailableException;
use App\Domain\Accounting\Posting\Exception\RejectedJournalStateException;
use App\Infrastructure\Accounting\Journal\JournalRepository;

/**
 * Resolves whether a `PostingCommand`'s proposed Journal identity is
 * fresh (not yet persisted anywhere), references an existing Draft
 * Journal for its own Tenant, or is unavailable — either because it
 * references an existing Posted Journal (AETS-007 §11, §14 step 3;
 * `POST-018`), or because the identity is already reserved by a
 * different Tenant entirely (AETS-007 §7, §21; `POST-001`).
 *
 * **The state-resolution boundary only.** This class answers exactly
 * one question — "what, if anything, already exists under this
 * command's proposed identity?" — and nothing else. It does not
 * assemble a candidate Journal (`DraftJournalAssembler`'s job,
 * deliberately not called from here), does not compare the command's
 * supplied lines against an existing Draft's persisted lines, does not
 * decide how an existing Draft is updated or replaced, does not
 * perform the Draft -> Posted transition, and calls neither
 * `Journal::post()` nor `JournalRepository::save()`. It performs no
 * mutation and no persistence of any kind — read-only lookups are its
 * entire effect.
 *
 * **Tenant-scoped first, global second — never the reverse.**
 * `JournalRepository::findById(TenantId, JournalId)` remains
 * tenant-scoped, exactly as `PostingCommandAccountValidator` already
 * relies on for Account lookups: it returns `null` both when no
 * Journal exists under that identifier at all, and when one exists
 * only under a *different* Tenant. Unlike Account (`COA-001`), a
 * `JournalId` is a single *global* primary key (M3-T9), not a
 * per-Tenant business identifier — so a wrong-Tenant Journal reference
 * is not, and must not be treated as, "fresh": posting against it
 * would either collide with that other Tenant's real Journal at the
 * database level, or represent this Tenant claiming an identity it
 * does not own. Only once the tenant-scoped lookup finds nothing does
 * this resolver ask the narrow, boolean-only
 * {@see JournalRepository::existsById()} whether that identity is
 * reserved *anywhere* — never before, and never in place of, the
 * tenant-scoped check. That second lookup returns no Tenant, no
 * Journal, and no other identifying detail (AETS-007 §21) — it can
 * only ever change "fresh" into "unavailable," never reveal *why* or
 * *to whom*.
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
     * @throws RejectedJournalIdentityUnavailableException if no Journal
     *                                                     exists under the command's proposed identity for its own
     *                                                     Tenant, but that identity is already reserved by a
     *                                                     different Tenant.
     */
    public function resolve(PostingCommand $command): PostingCommandJournalState
    {
        $journal = $this->journalRepository->findById($command->tenantId(), $command->journalId());

        if ($journal !== null) {
            if ($journal->state() === JournalState::Posted) {
                throw RejectedJournalStateException::forNonDraftJournal($command->journalId());
            }

            return PostingCommandJournalState::existingDraft($journal);
        }

        if ($this->journalRepository->existsById($command->journalId())) {
            throw RejectedJournalIdentityUnavailableException::forJournalId($command->journalId());
        }

        return PostingCommandJournalState::fresh();
    }
}
