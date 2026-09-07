<?php

declare(strict_types=1);

namespace App\Domain\Banking;

/**
 * The deterministic terminal result of
 * {@see BankStatementImportService::import()} (M17) — either this
 * specific upload was newly processed, or it exactly replays a
 * previous upload of the byte-identical file for the same BankAccount
 * (BNK-004's file-level idempotency), returning that original
 * {@see ImportBatch} instead of reprocessing it.
 */
final class BankStatementImportResult
{
    private function __construct(
        private readonly bool $isNewImport,
        private readonly ImportBatch $importBatch,
    ) {}

    public static function newlyImported(ImportBatch $importBatch): self
    {
        return new self(true, $importBatch);
    }

    public static function replayed(ImportBatch $importBatch): self
    {
        return new self(false, $importBatch);
    }

    public function isNewImport(): bool
    {
        return $this->isNewImport;
    }

    public function isReplay(): bool
    {
        return ! $this->isNewImport;
    }

    public function importBatch(): ImportBatch
    {
        return $this->importBatch;
    }
}
