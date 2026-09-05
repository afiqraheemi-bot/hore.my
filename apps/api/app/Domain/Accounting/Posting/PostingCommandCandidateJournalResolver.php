<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\Journal;

/**
 * Resolves the candidate Journal a `PostingCommand` represents, by
 * composing the two already-independent building blocks AETS-007 §11
 * and §14 steps 3/6–8 require: {@see PostingCommandJournalStateResolver}
 * (what, if anything, already exists under the command's proposed
 * identity) and {@see DraftJournalAssembler} (structural/balance
 * validation for a fresh Journal).
 *
 * **Pure composition — no new rule invented.** This class contains no
 * validation logic of its own. It calls the state resolver first;
 * when the identity is fresh, it hands the command straight to the
 * assembler and returns whatever that produces, letting every
 * existing Journal-domain exception
 * (`InsufficientJournalLinesException`, `MixedCurrencyJournalException`,
 * `UnbalancedJournalException`) and the state resolver's own
 * `RejectedJournalStateException` propagate completely unchanged —
 * this is exactly how `POST-T044` (a fresh identity accompanied by an
 * incomplete or invalid line payload, rejected as malformed input) is
 * evidenced: through the same fresh-assembly path a genuinely valid
 * fresh command already uses, not a separate check.
 *
 * **The existing-Draft case is returned untouched.** When the state
 * resolver reports an existing Draft Journal, this class returns
 * exactly that Journal — the same instance, unmodified. It does not
 * compare the command's supplied lines against that Journal's
 * persisted lines, does not rebuild or replace those lines, and does
 * not mutate the Draft in any way. Whether, and how, an existing
 * Draft's own lines are ever reconciled with a command's supplied
 * ones is a separate, later concern this class does not decide.
 *
 * **What this does not do.** It never calls `Journal::post()` or
 * `JournalRepository::save()`, performs no Account validation, no
 * Actor/Tenant resolution, no Source Fingerprint policy enforcement,
 * no idempotency/replay handling, no persistence, no database
 * transaction, and no Audit/Outbox work. It is not, and must never
 * grow into, a Posting Engine — every one of those remains a separate,
 * future responsibility.
 */
final class PostingCommandCandidateJournalResolver
{
    public function __construct(
        private readonly PostingCommandJournalStateResolver $stateResolver,
        private readonly DraftJournalAssembler $assembler,
    ) {}

    public function resolve(PostingCommand $command): Journal
    {
        $state = $this->stateResolver->resolve($command);

        if ($state->isFresh()) {
            return $this->assembler->assemble($command);
        }

        /** @var Journal $existingDraft */
        $existingDraft = $state->existingDraftJournal();

        return $existingDraft;
    }
}
