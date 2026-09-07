<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Banking\BankTransactionRepository;
use App\Infrastructure\Banking\ImportBatchRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * The application service that makes bank statement import (M17) an
 * atomic, idempotent operation over the already-parsed
 * {@see CsvBankStatementParser} output — the only class in the Banking
 * domain that opens a database transaction, mirroring every
 * Transactions-domain Recording Service's own "one orchestrator, every
 * other collaborator storage-free" shape.
 *
 * **BNK-004's two-layer idempotency, both enforced here.**
 *
 * 1. **File-level.** {@see ImportBatchRepository::findByBankAccountAndFileHash()}
 *    is checked *before* parsing even runs — re-uploading the
 *    byte-identical file for the same BankAccount is a pure replay,
 *    returning the original {@see ImportBatch} untouched. Nothing is
 *    parsed or written twice.
 * 2. **Row-level.** For a file that is *not* byte-identical (an
 *    overlapping date-range re-export, for example), each parsed row
 *    is checked individually against
 *    {@see BankTransactionRepository::existsByNaturalKey()} — a
 *    matching row is silently skipped (counted, not erroneous), never
 *    inserted twice.
 *
 * **Atomicity.** The new `ImportBatch` row and every newly-inserted
 * `BankTransaction` row for it commit or roll back together — a
 * partially-imported statement is never observable.
 *
 * **No Journal, no Posting Command.** Unlike every Transactions-domain
 * service, this class never touches
 * {@see PostingCommandTransactionalExecutor} —
 * importing a statement records normalized bank data only; it neither
 * proposes nor posts anything to the ledger (SRS TRX-003's "AI/
 * peraturan hanya mencipta proposal" spirit, extended here to state
 * plainly that *import itself* creates no accounting effect at all —
 * only a future, explicit Match/confirm action, M18, ever could).
 *
 * **Every identifier here is freshly random, never deterministically
 * derived.** Unlike a Posting Command (AETS-007 §11, whose
 * retry-safety depends on a stable `JournalId` across retries), this
 * service's own retry-safety comes entirely from BNK-004's natural-key
 * checks above — a random {@see BankTransactionId}/{@see ImportBatchId}
 * per call is exactly as safe, and simpler.
 */
final class BankStatementImportService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly CsvBankStatementParser $parser,
        private readonly ImportBatchRepository $importBatchRepository,
        private readonly BankTransactionRepository $bankTransactionRepository,
    ) {}

    public function import(
        TenantId $tenantId,
        BankAccountId $bankAccountId,
        string $originalFilename,
        string $fileContent,
        Currency $currency,
    ): BankStatementImportResult {
        $fileHash = hash('sha256', $fileContent);

        $existingBatch = $this->importBatchRepository->findByBankAccountAndFileHash($tenantId, $bankAccountId, $fileHash);

        if ($existingBatch !== null) {
            return BankStatementImportResult::replayed($existingBatch);
        }

        $rows = $this->parser->parse($fileContent, $currency);

        return $this->connection->transaction(function () use ($tenantId, $bankAccountId, $originalFilename, $fileHash, $rows): BankStatementImportResult {
            $duplicateCount = 0;
            $importBatchId = ImportBatchId::of((string) Str::uuid());

            // Two passes, deliberately: `bank_transactions.import_batch_id`
            // carries a foreign key onto `bank_statement_import_batches`,
            // so every BankTransaction row must be inserted *after* its
            // own ImportBatch row exists — but `ImportBatch::record()`
            // itself needs the final inserted/duplicate counts. This
            // pass only decides which rows are new versus row-level
            // duplicates (BNK-004) and builds the BankTransaction
            // aggregates; nothing is persisted yet.
            $bankTransactions = [];

            foreach ($rows as $row) {
                $alreadyExists = $this->bankTransactionRepository->existsByNaturalKey(
                    $tenantId,
                    $bankAccountId,
                    $row->transactionDate(),
                    $row->description(),
                    $row->amount(),
                    $row->direction(),
                    $row->reference(),
                );

                if ($alreadyExists) {
                    $duplicateCount++;

                    continue;
                }

                $bankTransactions[] = BankTransaction::record(
                    BankTransactionId::of((string) Str::uuid()),
                    $tenantId,
                    $bankAccountId,
                    $importBatchId,
                    $row->transactionDate(),
                    $row->description(),
                    $row->amount(),
                    $row->direction(),
                    $row->balance(),
                    $row->reference(),
                );
            }

            $importBatch = ImportBatch::record(
                $importBatchId,
                $tenantId,
                $bankAccountId,
                $fileHash,
                $originalFilename,
                count($rows),
                count($bankTransactions),
                $duplicateCount,
                new \DateTimeImmutable,
            );

            $this->importBatchRepository->record($importBatch);

            foreach ($bankTransactions as $bankTransaction) {
                $this->bankTransactionRepository->record($bankTransaction);
            }

            return BankStatementImportResult::newlyImported($importBatch);
        });
    }
}
