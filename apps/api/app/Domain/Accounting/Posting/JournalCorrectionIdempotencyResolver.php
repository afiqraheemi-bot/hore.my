<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Posting\Exception\CorruptPostingIdempotencyMappingException;
use App\Domain\Accounting\Posting\Exception\RejectedConflictingIdempotencyReuseException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;

/**
 * Decides whether a Journal Correction candidate's (TenantId,
 * Idempotency Key) is unused, a safe replay of an already-settled
 * correction Journal, or a conflicting reuse (M5 architecture decision
 * — gap A), mirroring {@see PostingCommandIdempotencyResolver}'s own
 * role for ordinary `PostingCommand`s exactly.
 *
 * **The same `posting_idempotency_keys` mapping, reused unchanged.**
 * A correction's (TenantId, Idempotency Key) pair is recorded and
 * looked up through the exact same {@see PostingIdempotencyRepository}
 * this Tenant's ordinary Postings already use — Idempotency Key
 * uniqueness is scoped per Tenant globally, not per kind of posting, so
 * no second mapping table or column is introduced.
 *
 * **Takes an already-assembled candidate, not a raw command.** Unlike
 * {@see PostingCommandIdempotencyResolver} (whose `PostingCommand`
 * already carries its own lines directly from the caller), a
 * Reversal's lines only exist once
 * {@see JournalCorrectionCandidateAssembler} has derived them — so this
 * class is handed the finished candidate `Journal` (Draft, not yet
 * persisted) rather than assembling one itself. Reversal and
 * Replacement therefore need no special-casing here at all: both are
 * resolved identically, via {@see JournalCorrectionLogicalEquivalence}.
 *
 * **Corrupt state fails loudly.** A mapping whose named Journal cannot
 * be loaded for the same Tenant is never treated as "first submission"
 * — see {@see CorruptPostingIdempotencyMappingException}'s own
 * docblock for why this is an impossible state under normal operation.
 */
final class JournalCorrectionIdempotencyResolver
{
    public function __construct(
        private readonly PostingIdempotencyRepository $idempotencyRepository,
        private readonly JournalRepository $journalRepository,
        private readonly JournalCorrectionLogicalEquivalence $logicalEquivalence,
    ) {}

    /**
     * @throws RejectedConflictingIdempotencyReuseException if a mapping
     *                                                      already exists for `$tenantId`/`$idempotencyKey` and
     *                                                      `$candidate` is a materially different correction request
     *                                                      from the Journal it names.
     * @throws CorruptPostingIdempotencyMappingException if a mapping
     *                                                   exists but the Journal it names cannot be loaded for
     *                                                   `$tenantId`.
     */
    public function resolve(TenantId $tenantId, IdempotencyKey $idempotencyKey, Journal $candidate): PostingCommandIdempotencyDecision
    {
        $existingJournalId = $this->idempotencyRepository->find($tenantId, $idempotencyKey);

        if ($existingJournalId === null) {
            return PostingCommandIdempotencyDecision::firstSubmission();
        }

        $existingJournal = $this->journalRepository->findById($tenantId, $existingJournalId);

        if ($existingJournal === null) {
            throw CorruptPostingIdempotencyMappingException::forUnresolvableJournal($tenantId, $idempotencyKey, $existingJournalId);
        }

        if (! $this->logicalEquivalence->equivalent($existingJournal, $candidate)) {
            throw RejectedConflictingIdempotencyReuseException::forKey($tenantId, $idempotencyKey);
        }

        return PostingCommandIdempotencyDecision::replay($existingJournal);
    }
}
