<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Period;

use App\Domain\Transactions\Expense\ExpenseRecordingResult;

/**
 * The deterministic terminal result of executing a `PeriodClosingCommand`
 * through {@see PeriodClosingService} (AETS-014) — mirrors
 * {@see ExpenseRecordingResult}'s own
 * role exactly: either this invocation newly closed the Period (and
 * posted the closing Journal), or it matched an already-processed
 * command for the same (Tenant, Idempotency Key) pair and is returning
 * that original closure instead.
 */
final class PeriodClosingResult
{
    private function __construct(
        private readonly bool $isNewlyClosed,
        private readonly PeriodClosure $closure,
    ) {}

    public static function newlyClosed(PeriodClosure $closure): self
    {
        return new self(true, $closure);
    }

    public static function replayed(PeriodClosure $closure): self
    {
        return new self(false, $closure);
    }

    public function isNewlyClosed(): bool
    {
        return $this->isNewlyClosed;
    }

    public function isReplay(): bool
    {
        return ! $this->isNewlyClosed;
    }

    public function closure(): PeriodClosure
    {
        return $this->closure;
    }
}
