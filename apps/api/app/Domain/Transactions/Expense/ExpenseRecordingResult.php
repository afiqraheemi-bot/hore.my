<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Expense;

use App\Domain\Accounting\Posting\PostingCommandExecutionResult;

/**
 * The deterministic terminal result of executing a `RecordExpenseCommand`
 * through {@see ExpenseRecordingService} (M7) — mirroring
 * {@see PostingCommandExecutionResult}'s
 * own role for the underlying `PostingCommand`: either this specific
 * invocation newly recorded the Expense (and posted its Journal), or it
 * matched an already-processed command for the same (Tenant,
 * Idempotency Key) pair and is returning that original Expense instead.
 */
final class ExpenseRecordingResult
{
    private function __construct(
        private readonly bool $isNewlyRecorded,
        private readonly Expense $expense,
    ) {}

    public static function newlyRecorded(Expense $expense): self
    {
        return new self(true, $expense);
    }

    public static function replayed(Expense $expense): self
    {
        return new self(false, $expense);
    }

    public function isNewlyRecorded(): bool
    {
        return $this->isNewlyRecorded;
    }

    public function isReplay(): bool
    {
        return ! $this->isNewlyRecorded;
    }

    public function expense(): Expense
    {
        return $this->expense;
    }
}
