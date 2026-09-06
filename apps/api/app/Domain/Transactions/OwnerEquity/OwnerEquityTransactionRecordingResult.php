<?php

declare(strict_types=1);

namespace App\Domain\Transactions\OwnerEquity;

use App\Domain\Accounting\Posting\PostingCommandExecutionResult;

/**
 * The deterministic terminal result of executing a
 * `RecordOwnerEquityTransactionCommand` through
 * {@see OwnerEquityTransactionRecordingService} (M15) — mirroring
 * {@see PostingCommandExecutionResult}'s own role for the underlying
 * `PostingCommand`: either this specific invocation newly recorded the
 * transaction (and posted its Journal), or it matched an
 * already-processed command for the same (Tenant, Idempotency Key)
 * pair and is returning that original record instead.
 */
final class OwnerEquityTransactionRecordingResult
{
    private function __construct(
        private readonly bool $isNewlyRecorded,
        private readonly OwnerEquityTransaction $transaction,
    ) {}

    public static function newlyRecorded(OwnerEquityTransaction $transaction): self
    {
        return new self(true, $transaction);
    }

    public static function replayed(OwnerEquityTransaction $transaction): self
    {
        return new self(false, $transaction);
    }

    public function isNewlyRecorded(): bool
    {
        return $this->isNewlyRecorded;
    }

    public function isReplay(): bool
    {
        return ! $this->isNewlyRecorded;
    }

    public function transaction(): OwnerEquityTransaction
    {
        return $this->transaction;
    }
}
