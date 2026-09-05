<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\Journal;

/**
 * The deterministic terminal result of executing a `PostingCommand`
 * through {@see PostingCommandTransactionalExecutor} (AETS-007 §19;
 * `POST-T019`, `POST-T020`): either this specific invocation newly
 * posted the Journal, or it matched an already-processed command for
 * the same (TenantId, Idempotency Key) pair and is returning that
 * original Journal instead.
 *
 * **The minimum §19 requires, nothing more.** This type identifies the
 * Posted Journal (by carrying it directly — its own `id()` and
 * `state()` already satisfy "identify the Journal by its stable
 * identifier" and "its resulting state is Posted") and the
 * newly-posted-vs-replay indicator §19 requires callers be able to
 * determine. It does not design any API/UI presentation shape, and it
 * carries nothing about Evidence, Audit, or Outbox — those remain
 * entirely outside this type's, and this milestone's, scope.
 */
final class PostingCommandExecutionResult
{
    private function __construct(
        private readonly bool $isNewlyPosted,
        private readonly Journal $journal,
    ) {}

    /**
     * This invocation performed the atomic write itself: a new Journal
     * was posted and a new idempotency mapping was recorded together,
     * in one transaction.
     */
    public static function newlyPosted(Journal $journal): self
    {
        return new self(true, $journal);
    }

    /**
     * This invocation performed zero writes — it matched an
     * already-settled (TenantId, Idempotency Key) mapping and is
     * returning that original Journal, unmodified.
     */
    public static function replayed(Journal $journal): self
    {
        return new self(false, $journal);
    }

    public function isNewlyPosted(): bool
    {
        return $this->isNewlyPosted;
    }

    public function isReplay(): bool
    {
        return ! $this->isNewlyPosted;
    }

    public function journal(): Journal
    {
        return $this->journal;
    }
}
