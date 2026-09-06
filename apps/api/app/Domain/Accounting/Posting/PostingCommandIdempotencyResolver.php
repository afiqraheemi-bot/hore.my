<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Posting\Exception\CorruptPostingIdempotencyMappingException;
use App\Domain\Accounting\Posting\Exception\RejectedConflictingIdempotencyReuseException;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;

/**
 * Decides whether a `PostingCommand`'s (TenantId, Idempotency Key) is
 * unused, a safe replay of an already-settled Journal, or a
 * conflicting reuse (AETS-007 §6.1, §15; `POST-004`,
 * `POST-T021`–`POST-T023`).
 *
 * **Decision/read-side only.** This class performs no write of any
 * kind: it does not post a new Journal, does not record a new
 * idempotency mapping, and owns no transaction. A caller that receives
 * {@see PostingCommandIdempotencyDecision::firstSubmission()} still has
 * the entire rest of the Posting pipeline ahead of it; a caller that
 * receives a replay decision has nothing left to do but hand back the
 * carried Journal, unmodified.
 *
 * **How a replay is judged.** When a mapping already exists for the
 * command's (TenantId, Idempotency Key), the Journal it names is
 * loaded and a comparison-only `PostingCommand` is reconstructed from
 * that Journal's own TenantId, JournalId, and Journal Lines — the
 * persisted Journal is authoritative for the original economic
 * payload, never a separately stored copy of the original command.
 * {@see PostingCommandLogicalEquivalence} then judges the two
 * commands exactly as it already does for any two commands sharing an
 * idempotency scope: TenantId, JournalId, and Journal Lines (Account,
 * Money, Currency, Direction, in order) must all agree. Idempotency
 * Key, Actor, Source, Source Fingerprint, and Evidence are never part
 * of that comparison (see that comparator's own docblock) — an
 * Actor-only, Source-only, Source-Fingerprint-only, or Evidence-only
 * difference from the original command still resolves as a safe
 * replay. The reconstructed comparison command's own Idempotency Key,
 * Actor, and Source fields are filled with a fixed placeholder value
 * purely to satisfy `PostingCommand`'s constructor — the comparator
 * never reads them.
 *
 * **Journal identity cannot be redirected.** Because the reconstructed
 * comparison command's JournalId is the persisted Journal's own
 * identity, an incoming command naming any other JournalId for the
 * same (TenantId, Idempotency Key) fails the JournalId equality
 * {@see PostingCommandLogicalEquivalence} already checks first — it is
 * rejected as a conflicting reuse, never silently redirected to a
 * different Journal, and never mistaken for a first submission.
 *
 * **Corrupt state fails loudly.** A mapping whose named Journal cannot
 * be loaded for the same Tenant is never treated as "first
 * submission" — see {@see CorruptPostingIdempotencyMappingException}'s
 * own docblock for why this is an impossible state under normal
 * operation, not a legitimate case to paper over.
 */
final class PostingCommandIdempotencyResolver
{
    /**
     * Never read by {@see PostingCommandLogicalEquivalence} — present
     * only to satisfy `PostingCommand`'s constructor when reconstructing
     * a comparison-only command from a persisted Journal.
     */
    private const COMPARISON_PLACEHOLDER = 'idempotency-resolver-comparison-placeholder';

    public function __construct(
        private readonly PostingIdempotencyRepository $idempotencyRepository,
        private readonly JournalRepository $journalRepository,
        private readonly PostingCommandLogicalEquivalence $logicalEquivalence,
    ) {}

    /**
     * @throws RejectedConflictingIdempotencyReuseException if a mapping
     *                                                      already exists for `$command`'s (TenantId, Idempotency Key)
     *                                                      and `$command` is a materially different logical request
     *                                                      from the Journal it names.
     * @throws CorruptPostingIdempotencyMappingException if a mapping
     *                                                   exists but the Journal it names cannot be loaded for
     *                                                   `$command`'s Tenant.
     */
    public function resolve(PostingCommand $command): PostingCommandIdempotencyDecision
    {
        $existingJournalId = $this->idempotencyRepository->find($command->tenantId(), $command->idempotencyKey());

        if ($existingJournalId === null) {
            return PostingCommandIdempotencyDecision::firstSubmission();
        }

        $journal = $this->journalRepository->findById($command->tenantId(), $existingJournalId);

        if ($journal === null) {
            throw CorruptPostingIdempotencyMappingException::forUnresolvableJournal(
                $command->tenantId(),
                $command->idempotencyKey(),
                $existingJournalId,
            );
        }

        if (! $this->logicalEquivalence->equivalent($this->comparisonCommandFrom($journal), $command)) {
            throw RejectedConflictingIdempotencyReuseException::forKey($command->tenantId(), $command->idempotencyKey());
        }

        return PostingCommandIdempotencyDecision::replay($journal);
    }

    private function comparisonCommandFrom(Journal $journal): PostingCommand
    {
        return new PostingCommand(
            IdempotencyKey::of(self::COMPARISON_PLACEHOLDER),
            $journal->tenantId(),
            ActorReference::of(self::COMPARISON_PLACEHOLDER),
            SourceReference::of(self::COMPARISON_PLACEHOLDER),
            $journal->id(),
            $journal->lines(),
            $journal->financialDate(),
        );
    }
}
