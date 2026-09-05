<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\Journal;
use App\Infrastructure\Accounting\Journal\Exception\DuplicateJournalIdentityException;
use App\Infrastructure\Accounting\Posting\Exception\DuplicatePostingIdempotencyKeyException;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The smallest transactional orchestration providing atomic
 * first-submission Posting (M4-T18, AETS-007 §15, §17, §19): a first
 * submission posts a new Journal and records its idempotency mapping
 * together, in one transaction; an exact replay performs zero writes;
 * a conflicting reuse is a deterministic rejection; and a genuinely
 * concurrent race resolves without ever creating a second Journal for
 * the same (TenantId, Idempotency Key).
 *
 * **Still not the full Posting Engine, and not full LED-003.** The
 * transaction this class opens covers exactly three things: the
 * Journal header, its Journal Lines, and the `posting_idempotency_keys`
 * mapping row. Evidence linkage, Audit Event, and Outbox event writes
 * (AETS-007 §17) are entirely absent — this class makes no claim
 * toward `POST-T070`/`POST-T127`'s full atomic-posting requirement, no
 * Source Fingerprint deduplication, and no Actor/Tenant resolution.
 * Every one of those remains later work.
 *
 * **Decision before write, always.** {@see PostingCommandIdempotencyResolver::resolve()}
 * runs first, before any transaction opens (`POST-T066`) — a replay or
 * a conflicting-reuse rejection is decided, and returned or thrown, on
 * that read alone; no write of any kind is attempted for either
 * outcome.
 *
 * **The outer transaction, and why it belongs here.** Neither
 * {@see PostingCommandJournalExecutor} nor
 * {@see PostingIdempotencyRepository} individually has visibility into
 * both writes this atomicity guarantee requires — this class is the
 * only collaborator that does, so it is the only one that opens a
 * transaction spanning both. `JournalRepository::save()` already opens
 * its own transaction internally; this class relies on
 * `Illuminate\Database\Connection::transaction()`'s existing
 * reentrant/savepoint behavior for that nested call to participate in
 * this class's own outer transaction rather than committing
 * independently — no modification to `JournalRepository` or
 * {@see PostingCommandJournalExecutor} is made, or needed, for this.
 * This only works when every collaborator shares the exact same
 * `ConnectionInterface` instance this class itself uses.
 *
 * **Race handling — exactly two known, recoverable races.**
 * {@see PostingIdempotencyRepository::record()}'s own primary-key
 * violation surfaces as {@see DuplicatePostingIdempotencyKeyException};
 * a genuinely concurrent `JournalRepository::save()` call for the same
 * brand-new JournalId surfaces as
 * {@see DuplicateJournalIdentityException} (M4-T18B) — since both
 * proposed identities are part of the same logical request under a
 * genuinely concurrent equivalent submission, either constraint may be
 * the one that actually loses the race first. These are the only two
 * exceptions this class treats specially; no generic
 * `\Illuminate\Database\QueryException` is ever caught here, since an
 * FK violation, a `CHECK` violation, a lock timeout, or a
 * serialization failure carries no such guarantee about what caused
 * it. By the time either known exception reaches this class's `catch`,
 * the outer transaction has already been rolled back automatically by
 * `Connection::transaction()`'s own closure semantics (any `Throwable`
 * escaping the closure triggers a rollback before re-throwing) — this
 * class performs no manual rollback, and issues no further query
 * against that now-finished transaction. It instead re-resolves the
 * command fresh (`POST-T103`, `POST-T104`): a replay of the winner's
 * Journal, or a conflicting-reuse rejection against it, are both
 * legitimate outcomes and are returned/propagated exactly as the
 * resolver reports them. A resolver that reports `firstSubmission()`
 * again immediately after a proven race is an inconsistency this class
 * has no safe way to reconcile — it fails loudly rather than retrying
 * automatically and risking a second economic posting for the same
 * command. Every other exception from the first-write transaction
 * propagates completely unmodified — a validation, repository, or
 * database failure is never reinterpreted as a replay.
 */
final class PostingCommandTransactionalExecutor
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly PostingCommandIdempotencyResolver $idempotencyResolver,
        private readonly PostingCommandJournalExecutor $journalExecutor,
        private readonly PostingIdempotencyRepository $idempotencyRepository,
    ) {}

    public function execute(PostingCommand $command): PostingCommandExecutionResult
    {
        $decision = $this->idempotencyResolver->resolve($command);

        if ($decision->isReplay()) {
            /** @var Journal $journal */
            $journal = $decision->replayedJournal();

            return PostingCommandExecutionResult::replayed($journal);
        }

        try {
            $journal = $this->connection->transaction(function () use ($command) {
                $journal = $this->journalExecutor->execute($command);

                $this->idempotencyRepository->record($command->tenantId(), $command->idempotencyKey(), $journal->id());

                return $journal;
            });
        } catch (DuplicatePostingIdempotencyKeyException|DuplicateJournalIdentityException) {
            return $this->resolveAfterLostRace($command);
        }

        return PostingCommandExecutionResult::newlyPosted($journal);
    }

    private function resolveAfterLostRace(PostingCommand $command): PostingCommandExecutionResult
    {
        $decision = $this->idempotencyResolver->resolve($command);

        if ($decision->isReplay()) {
            /** @var Journal $journal */
            $journal = $decision->replayedJournal();

            return PostingCommandExecutionResult::replayed($journal);
        }

        throw new \RuntimeException(sprintf(
            'A Posting idempotency race was detected for Tenant "%s" and Idempotency Key "%s", '
            .'but re-resolving immediately afterward unexpectedly reported no existing mapping. '
            .'Refusing to retry automatically.',
            $command->tenantId()->toString(),
            $command->idempotencyKey()->toString(),
        ));
    }
}
