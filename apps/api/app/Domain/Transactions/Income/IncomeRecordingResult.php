<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Income;

use App\Domain\Accounting\Posting\PostingCommandExecutionResult;

/**
 * The deterministic terminal result of executing a `RecordIncomeCommand`
 * through {@see IncomeRecordingService} (M9) — mirroring
 * {@see PostingCommandExecutionResult}'s
 * own role for the underlying `PostingCommand`: either this specific
 * invocation newly recorded the Income (and posted its Journal), or it
 * matched an already-processed command for the same (Tenant,
 * Idempotency Key) pair and is returning that original Income instead.
 */
final class IncomeRecordingResult
{
    private function __construct(
        private readonly bool $isNewlyRecorded,
        private readonly Income $income,
    ) {}

    public static function newlyRecorded(Income $income): self
    {
        return new self(true, $income);
    }

    public static function replayed(Income $income): self
    {
        return new self(false, $income);
    }

    public function isNewlyRecorded(): bool
    {
        return $this->isNewlyRecorded;
    }

    public function isReplay(): bool
    {
        return ! $this->isNewlyRecorded;
    }

    public function income(): Income
    {
        return $this->income;
    }
}
