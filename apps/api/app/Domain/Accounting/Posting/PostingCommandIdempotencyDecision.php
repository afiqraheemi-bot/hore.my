<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\Journal;

/**
 * The outcome of resolving a `PostingCommand`'s (TenantId, Idempotency
 * Key) against any settled mapping already recorded for it (AETS-007
 * §6.1, §15; `POST-004`, `POST-T021`–`POST-T023`): either no mapping
 * exists yet, or one does and the incoming command is a safe replay of
 * it.
 *
 * **A conflicting reuse is never a third variant of this type.** When
 * a mapping exists but the incoming command is a materially different
 * logical request, that is a rejection
 * (`RejectedConflictingIdempotencyReuseException`), thrown by the
 * resolver that produces this type — never a value this type
 * represents. This type deliberately carries only the two outcomes a
 * caller may legitimately proceed from.
 *
 * This type decides nothing about what happens next for either
 * outcome — posting a new Journal, recording a new idempotency
 * mapping, and any outer transaction boundary all remain separate,
 * later concerns this type has no part in.
 */
final class PostingCommandIdempotencyDecision
{
    private function __construct(
        private readonly bool $isReplay,
        private readonly ?Journal $journal,
    ) {}

    /**
     * No mapping is currently recorded for the command's (TenantId,
     * Idempotency Key) — this is the first submission of that pair.
     */
    public static function firstSubmission(): self
    {
        return new self(false, null);
    }

    /**
     * A mapping already exists for the command's (TenantId, Idempotency
     * Key), and the incoming command is a safe, logically equivalent
     * replay of it — carrying exactly the Journal the original mapping
     * already resolved to, unmodified.
     */
    public static function replay(Journal $journal): self
    {
        return new self(true, $journal);
    }

    public function isReplay(): bool
    {
        return $this->isReplay;
    }

    public function isFirstSubmission(): bool
    {
        return ! $this->isReplay;
    }

    /**
     * The replayed Journal, when {@see isReplay()} is `true`; `null`
     * on a first submission.
     */
    public function replayedJournal(): ?Journal
    {
        return $this->journal;
    }
}
