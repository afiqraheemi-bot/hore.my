<?php

declare(strict_types=1);

namespace App\Infrastructure\Banking;

use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\BankStatementImportService;
use App\Domain\Banking\ImportBatch;
use App\Domain\Banking\ImportBatchId;
use App\Domain\Shared\Tenancy\TenantId;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the ImportBatch aggregate (M17),
 * through the production `bank_statement_import_batches` table.
 *
 * **Transaction participation.** {@see record()} opens no transaction
 * of its own — it runs directly on `$this->connection`'s current
 * state, so when {@see BankStatementImportService}
 * has already started a transaction on that same connection (to insert
 * this batch's `BankTransaction` rows atomically alongside it), this
 * insert becomes part of it — the identical participation pattern
 * every Transactions-domain repository already establishes.
 */
final class ImportBatchRepository
{
    private const TABLE = 'bank_statement_import_batches';

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function record(ImportBatch $importBatch): void
    {
        $this->connection->table(self::TABLE)->insert([
            'id' => $importBatch->id()->toString(),
            'tenant_id' => $importBatch->tenantId()->toString(),
            'bank_account_id' => $importBatch->bankAccountId()->toString(),
            'file_hash' => $importBatch->fileHash(),
            'original_filename' => $importBatch->originalFilename(),
            'row_count' => $importBatch->rowCount(),
            'inserted_count' => $importBatch->insertedCount(),
            'duplicate_count' => $importBatch->duplicateCount(),
            'imported_at' => $importBatch->importedAt()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Resolves BNK-004's file-level idempotency check: the ImportBatch
     * already recorded for this exact `(bankAccountId, fileHash)` pair,
     * if any.
     */
    public function findByBankAccountAndFileHash(TenantId $tenantId, BankAccountId $bankAccountId, string $fileHash): ?ImportBatch
    {
        /** @var object{id: string, tenant_id: string, bank_account_id: string, file_hash: string, original_filename: string, row_count: int, inserted_count: int, duplicate_count: int, imported_at: string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('bank_account_id', $bankAccountId->toString())
            ->where('file_hash', $fileHash)
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    /**
     * @param  object{id: string, tenant_id: string, bank_account_id: string, file_hash: string, original_filename: string, row_count: int, inserted_count: int, duplicate_count: int, imported_at: string}  $row
     */
    private function fromPersisted(object $row): ImportBatch
    {
        return ImportBatch::reconstitute(
            ImportBatchId::of($row->id),
            TenantId::of($row->tenant_id),
            BankAccountId::of($row->bank_account_id),
            $row->file_hash,
            $row->original_filename,
            $row->row_count,
            $row->inserted_count,
            $row->duplicate_count,
            new \DateTimeImmutable($row->imported_at),
        );
    }
}
