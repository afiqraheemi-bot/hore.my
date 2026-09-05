<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Posting\Exception\CorruptPostingIdempotencyMappingException;
use App\Domain\Accounting\Posting\Exception\RejectedConflictingIdempotencyReuseException;
use App\Domain\Accounting\Posting\Exception\UnresolvedCorrectionTargetException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Journal\Exception\DuplicateJournalIdentityException;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use App\Infrastructure\Accounting\Posting\Exception\DuplicatePostingIdempotencyKeyException;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The smallest transactional orchestration providing atomic
 * first-submission Journal Correction (M5 architecture decision — gap
 * E), a deliberate sibling of {@see PostingCommandTransactionalExecutor}
 * rather than a modification to it: a first submission posts a new
 * Reversal or Replacement Journal and records its idempotency mapping
 * together, in one transaction; an exact replay performs zero writes; a
 * conflicting reuse is a deterministic rejection; and a genuinely
 * concurrent race resolves without ever creating a second correction
 * Journal for the same (TenantId, Idempotency Key).
 *
 * **Why a sibling, not a refactor of `PostingCommandTransactionalExecutor`.**
 * That class is the most critical, concurrency-proven collaborator in
 * the existing Posting pipeline (M4-T18/T18B) — generalizing it to
 * accept either a `PostingCommand` or a Journal Correction candidate
 * would touch its own already-locked-down race-handling logic for the
 * sake of one new caller. This class instead duplicates only the thin
 * transaction-open-plus-catch-two-exceptions orchestration
 * (~10-15 lines, explicitly permitted by the M5 architecture decision)
 * while reusing, unchanged: {@see PostingIdempotencyRepository},
 * {@see JournalRepository}, both
 * {@see DuplicatePostingIdempotencyKeyException} and
 * {@see DuplicateJournalIdentityException}, and
 * {@see PostingCommandExecutionResult} itself as the result type — it
 * is already generic (a Journal plus an isNewlyPosted flag), so no new
 * result type is introduced.
 *
 * **Decision (and assembly) before write, always.** For each entry
 * point, {@see JournalCorrectionCandidateAssembler} first builds the
 * candidate Journal (loading and validating whatever it references),
 * then {@see JournalCorrectionIdempotencyResolver::resolve()} runs —
 * both complete, and either return or throw, before any transaction
 * opens. A replay costs one avoidable candidate assembly; this class
 * does not optimize that away, mirroring M4's own preference for a
 * simple, obviously-correct ordering over a marginal efficiency gain.
 *
 * **Race handling — identical to M4's, reused verbatim.** See
 * {@see PostingCommandTransactionalExecutor}'s own docblock for the
 * full reasoning; nothing about it changes here beyond which
 * candidate-assembly closure produced the Journal being persisted.
 */
final class JournalCorrectionTransactionalExecutor
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly JournalCorrectionIdempotencyResolver $idempotencyResolver,
        private readonly JournalCorrectionCandidateAssembler $assembler,
        private readonly JournalRepository $journalRepository,
        private readonly PostingIdempotencyRepository $idempotencyRepository,
    ) {}

    /**
     * @throws UnresolvedCorrectionTargetException
     * @throws RejectedConflictingIdempotencyReuseException
     * @throws CorruptPostingIdempotencyMappingException
     */
    public function executeReversal(ReverseJournalCommand $command): PostingCommandExecutionResult
    {
        return $this->execute(
            $command->tenantId(),
            $command->idempotencyKey(),
            fn (): Journal => $this->assembler->assembleReversal($command),
        );
    }

    /**
     * @throws UnresolvedCorrectionTargetException
     * @throws RejectedConflictingIdempotencyReuseException
     * @throws CorruptPostingIdempotencyMappingException
     */
    public function executeReplacement(ReplaceJournalCommand $command): PostingCommandExecutionResult
    {
        return $this->execute(
            $command->tenantId(),
            $command->idempotencyKey(),
            fn (): Journal => $this->assembler->assembleReplacement($command),
        );
    }

    /**
     * @param  \Closure(): Journal  $assembleCandidate
     */
    private function execute(TenantId $tenantId, IdempotencyKey $idempotencyKey, \Closure $assembleCandidate): PostingCommandExecutionResult
    {
        $candidate = $assembleCandidate();

        $decision = $this->idempotencyResolver->resolve($tenantId, $idempotencyKey, $candidate);

        if ($decision->isReplay()) {
            /** @var Journal $journal */
            $journal = $decision->replayedJournal();

            return PostingCommandExecutionResult::replayed($journal);
        }

        try {
            $journal = $this->connection->transaction(function () use ($candidate, $tenantId, $idempotencyKey) {
                $posted = $candidate->post();

                $this->journalRepository->save($posted);
                $this->idempotencyRepository->record($tenantId, $idempotencyKey, $posted->id());

                return $posted;
            });
        } catch (DuplicatePostingIdempotencyKeyException|DuplicateJournalIdentityException) {
            return $this->resolveAfterLostRace($tenantId, $idempotencyKey, $assembleCandidate);
        }

        return PostingCommandExecutionResult::newlyPosted($journal);
    }

    /**
     * @param  \Closure(): Journal  $assembleCandidate
     */
    private function resolveAfterLostRace(TenantId $tenantId, IdempotencyKey $idempotencyKey, \Closure $assembleCandidate): PostingCommandExecutionResult
    {
        $decision = $this->idempotencyResolver->resolve($tenantId, $idempotencyKey, $assembleCandidate());

        if ($decision->isReplay()) {
            /** @var Journal $journal */
            $journal = $decision->replayedJournal();

            return PostingCommandExecutionResult::replayed($journal);
        }

        throw new \RuntimeException(sprintf(
            'A Journal Correction idempotency race was detected for Tenant "%s" and Idempotency Key "%s", '
            .'but re-resolving immediately afterward unexpectedly reported no existing mapping. '
            .'Refusing to retry automatically.',
            $tenantId->toString(),
            $idempotencyKey->toString(),
        ));
    }
}
