<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Transfer;

use App\Domain\Accounting\Posting\PostingCommandExecutionResult;

/**
 * The deterministic terminal result of executing a
 * `RecordTransferCommand` through {@see TransferRecordingService}
 * (M14) — mirroring {@see PostingCommandExecutionResult}'s own role for
 * the underlying `PostingCommand`: either this specific invocation
 * newly recorded the Transfer (and posted its Journal), or it matched
 * an already-processed command for the same (Tenant, Idempotency Key)
 * pair and is returning that original Transfer instead.
 */
final class TransferRecordingResult
{
    private function __construct(
        private readonly bool $isNewlyRecorded,
        private readonly Transfer $transfer,
    ) {}

    public static function newlyRecorded(Transfer $transfer): self
    {
        return new self(true, $transfer);
    }

    public static function replayed(Transfer $transfer): self
    {
        return new self(false, $transfer);
    }

    public function isNewlyRecorded(): bool
    {
        return $this->isNewlyRecorded;
    }

    public function isReplay(): bool
    {
        return ! $this->isNewlyRecorded;
    }

    public function transfer(): Transfer
    {
        return $this->transfer;
    }
}
