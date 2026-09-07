<?php

declare(strict_types=1);

use App\Domain\Banking\BankStatementImportService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for ImportBatch (M17, SRS BNK-004: "Import fail/
 * baris sama tidak boleh mencipta transaksi berganda" — importing the
 * same file/row must not create duplicate transactions).
 *
 * **`(bank_account_id, file_hash)` is unique.** This is the file-level
 * half of BNK-004's idempotency guarantee — a SHA-256 hash of the
 * exact uploaded file content. Re-uploading the byte-identical file for
 * the same BankAccount hits this constraint, letting
 * {@see BankStatementImportService} detect and
 * replay the original result instead of reprocessing (mirroring the
 * unique-constraint-then-lookup idempotency pattern
 * `PostingCommandIdempotencyResolver` already establishes for Posting
 * Commands). The row-level half of BNK-004 (an overlapping,
 * non-identical re-export containing some already-imported rows) is
 * `bank_transactions`' own composite-uniqueness concern, not this
 * table's.
 *
 * **No FK onto `journals`.** An import produces no Journal.
 */
return new class extends Migration
{
    private const TABLE = 'bank_statement_import_batches';

    private const BANK_ACCOUNT_TABLE = 'bank_accounts';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('bank_account_id', 64);
            $table->string('file_hash', 64);
            $table->string('original_filename', 255);
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('inserted_count');
            $table->unsignedInteger('duplicate_count');
            $table->timestamp('imported_at')->useCurrent();

            $table->primary('id');

            $table->unique(['bank_account_id', 'file_hash']);

            $table->foreign('bank_account_id')
                ->references('id')->on(self::BANK_ACCOUNT_TABLE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
