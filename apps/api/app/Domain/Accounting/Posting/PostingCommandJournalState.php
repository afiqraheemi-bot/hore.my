<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\Journal;

/**
 * The outcome of resolving a `PostingCommand`'s proposed Journal
 * identity against the existing Journal repository (AETS-007 §11):
 * either the identity is fresh (no persisted Journal exists under it
 * yet), or it references an existing Draft Journal.
 *
 * A Posted Journal is never represented by this type — referencing
 * one is a rejection (`RejectedJournalStateException`), not a third
 * result variant. This type deliberately carries only
 * these two outcomes; it is not a general Posting Engine state
 * machine, and it decides nothing about what happens next for either
 * outcome (assembly, comparison against an existing Draft's lines, or
 * the Draft -> Posted transition all remain separate, later
 * concerns).
 */
final class PostingCommandJournalState
{
    private function __construct(
        private readonly bool $isFresh,
        private readonly ?Journal $existingDraftJournal,
    ) {}

    /**
     * No Journal is currently persisted under the command's proposed
     * identity (for this Tenant) — the identity is fresh.
     */
    public static function fresh(): self
    {
        return new self(true, null);
    }

    /**
     * An existing Draft Journal is persisted under the command's
     * proposed identity — carried exactly, unmodified, as returned by
     * the repository.
     */
    public static function existingDraft(Journal $journal): self
    {
        return new self(false, $journal);
    }

    public function isFresh(): bool
    {
        return $this->isFresh;
    }

    /**
     * The existing Draft Journal, when {@see isFresh()} is `false`;
     * `null` when the identity is fresh.
     */
    public function existingDraftJournal(): ?Journal
    {
        return $this->existingDraftJournal;
    }
}
