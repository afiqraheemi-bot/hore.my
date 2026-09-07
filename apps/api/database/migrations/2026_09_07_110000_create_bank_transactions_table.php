<?php

declare(strict_types=1);

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Banking\BankTransactionDirection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for BankTransaction (M17, SRS BNK-003) — one
 * normalized row from an imported bank statement: transaction date,
 * description, amount, direction, running balance, and reference.
 *
 * **`direction` is `'MoneyIn'`/`'MoneyOut'`, never `'Debit'`/`'Credit'`.**
 * A bank statement's own "credit" (a deposit) is the *opposite* of what
 * it means on the Bank Account's own ledger side — crediting a
 * Debit-normal Asset Account is how money *leaves* it. Naming this
 * column with bank-statement-native, unambiguous English avoids ever
 * conflating it with {@see JournalDirection}'s
 * completely different, Account-relative Debit/Credit — see
 * {@see BankTransactionDirection}'s own docblock
 * for the full explanation of why this distinction matters.
 *
 * **Composite uniqueness is BNK-004's row-level duplicate guard.** A
 * re-imported file that is *not* byte-identical to a prior import (an
 * overlapping date-range re-export, for example) is not caught by
 * `bank_statement_import_batches`' own file-hash uniqueness — this
 * table's own `(bank_account_id, transaction_date, description, amount,
 * direction, reference)` uniqueness is what prevents that overlap from
 * ever producing a second row for the same real bank line.
 * `reference` defaults to an empty string, never `NULL`, specifically
 * so this composite unique index can include it — PostgreSQL treats
 * every `NULL` as distinct for uniqueness purposes, which would silently
 * defeat deduplication for any statement format that omits references.
 *
 * **No FK onto `journals`.** Importing a statement produces no
 * Journal — matching a row to one (M18) is a separate, later concern.
 */
return new class extends Migration
{
    private const TABLE = 'bank_transactions';

    private const BANK_ACCOUNT_TABLE = 'bank_accounts';

    private const IMPORT_BATCH_TABLE = 'bank_statement_import_batches';

    private const DIRECTION_CHECK_CONSTRAINT = 'bank_transactions_direction_canonical';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('bank_account_id', 64);
            $table->string('import_batch_id', 64);
            $table->date('transaction_date');
            $table->string('description', 500);
            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->string('direction', 16);
            $table->bigInteger('balance')->nullable();
            $table->string('reference', 128)->default('');
            $table->timestamp('created_at')->useCurrent();

            $table->primary('id');

            $table->unique(['bank_account_id', 'transaction_date', 'description', 'amount', 'direction', 'reference'], 'bank_transactions_natural_key_unique');

            $table->foreign('bank_account_id')
                ->references('id')->on(self::BANK_ACCOUNT_TABLE);

            $table->foreign('import_batch_id')
                ->references('id')->on(self::IMPORT_BATCH_TABLE);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                "alter table %s add constraint %s check (direction in ('MoneyIn', 'MoneyOut'))",
                self::TABLE,
                self::DIRECTION_CHECK_CONSTRAINT,
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
