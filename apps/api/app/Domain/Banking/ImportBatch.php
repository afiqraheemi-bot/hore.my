<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Shared\Tenancy\TenantId;

/**
 * One completed bank-statement upload (M17) — the file-level
 * idempotency record BNK-004 requires, and the audit trail of *when*
 * and *what* was imported: original filename, how many rows the file
 * contained, how many were newly inserted, and how many were skipped as
 * row-level duplicates (see {@see BankTransaction::naturalKey()}).
 *
 * **Immutable, append-only** — mirrors every other Posted-record-style
 * aggregate in this codebase (`Journal`, `Income`, `Transfer`, ...):
 * once recorded, an ImportBatch's own fields never change.
 */
final class ImportBatch
{
    private function __construct(
        private readonly ImportBatchId $id,
        private readonly TenantId $tenantId,
        private readonly BankAccountId $bankAccountId,
        private readonly string $fileHash,
        private readonly string $originalFilename,
        private readonly int $rowCount,
        private readonly int $insertedCount,
        private readonly int $duplicateCount,
        private readonly \DateTimeImmutable $importedAt,
    ) {}

    public static function record(
        ImportBatchId $id,
        TenantId $tenantId,
        BankAccountId $bankAccountId,
        string $fileHash,
        string $originalFilename,
        int $rowCount,
        int $insertedCount,
        int $duplicateCount,
        \DateTimeImmutable $importedAt,
    ): self {
        return new self($id, $tenantId, $bankAccountId, $fileHash, $originalFilename, $rowCount, $insertedCount, $duplicateCount, $importedAt);
    }

    public static function reconstitute(
        ImportBatchId $id,
        TenantId $tenantId,
        BankAccountId $bankAccountId,
        string $fileHash,
        string $originalFilename,
        int $rowCount,
        int $insertedCount,
        int $duplicateCount,
        \DateTimeImmutable $importedAt,
    ): self {
        return new self($id, $tenantId, $bankAccountId, $fileHash, $originalFilename, $rowCount, $insertedCount, $duplicateCount, $importedAt);
    }

    public function id(): ImportBatchId
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function bankAccountId(): BankAccountId
    {
        return $this->bankAccountId;
    }

    public function fileHash(): string
    {
        return $this->fileHash;
    }

    public function originalFilename(): string
    {
        return $this->originalFilename;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function insertedCount(): int
    {
        return $this->insertedCount;
    }

    public function duplicateCount(): int
    {
        return $this->duplicateCount;
    }

    public function importedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }
}
