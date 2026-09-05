<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\Journal;
use App\Infrastructure\Accounting\Journal\JournalRepository;

/**
 * Executes a single, non-retried, non-duplicate `PostingCommand`
 * through the currently available Journal persistence slice: Account
 * validation, fresh-vs-existing-Draft resolution, existing-Draft line
 * consistency where applicable, fresh assembly where applicable, the
 * existing `Journal::post()` transition, and persistence through the
 * existing `JournalRepository::save()`.
 *
 * **This is not the complete Posting Engine.** It performs no
 * Actor-to-Tenant resolution, no authorization, no Source Fingerprint
 * requirement policy or derivation, no Evidence, no Audit Event, no
 * Outbox event, no idempotency/replay handling, no concurrency or
 * duplicate-submission handling, and introduces no new database
 * transaction abstraction — it calls only the transactional boundary
 * `JournalRepository::save()` already provides. Every one of those
 * remains a separate, future responsibility.
 *
 * **Composed from the finer-grained primitives directly — not
 * `PostingCommandCandidateJournalResolver`.** That class deliberately
 * discards the fresh-vs-existing distinction its own return value
 * needs for the existing-Draft line-consistency check this executor
 * must also perform; it is not used here, and is not modified by this
 * class.
 *
 * **Execution order.** Account validation runs first, then Journal
 * state resolution (which itself rejects an existing Posted Journal
 * unchanged), then either fresh assembly or existing-Draft line
 * validation, and only once every one of those has succeeded does
 * `Journal::post()` run, followed by exactly one
 * `JournalRepository::save()` call. Every validation step happens
 * before any persistence is attempted; a failure at any step leaves
 * no new durable persistence effect, and an existing Draft rejected at
 * any step remains exactly as persisted, unchanged.
 *
 * **Atomicity boundary — explicitly partial.** `JournalRepository::save()`
 * already provides an atomic transaction for the Journal header and
 * its Journal Lines only (M3-T10) — this executor does not claim, and
 * does not attempt, the full AETS-007 §17 atomic-persistence
 * requirement (Evidence linkage, Audit Event, and Outbox event
 * committed in the same transaction); none of those exist yet, and
 * none is invented here.
 */
final class PostingCommandJournalExecutor
{
    public function __construct(
        private readonly PostingCommandJournalStateResolver $stateResolver,
        private readonly PostingCommandAccountValidator $accountValidator,
        private readonly PostingCommandExistingDraftLineValidator $existingDraftLineValidator,
        private readonly DraftJournalAssembler $assembler,
        private readonly JournalRepository $journalRepository,
    ) {}

    public function execute(PostingCommand $command): Journal
    {
        $this->accountValidator->validate($command);

        $state = $this->stateResolver->resolve($command);

        if ($state->isFresh()) {
            $candidate = $this->assembler->assemble($command);
        } else {
            /** @var Journal $existingDraft */
            $existingDraft = $state->existingDraftJournal();
            $this->existingDraftLineValidator->validate($command, $existingDraft);
            $candidate = $existingDraft;
        }

        $posted = $candidate->post();

        $this->journalRepository->save($posted);

        return $posted;
    }
}
