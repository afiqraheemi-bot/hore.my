<?php

declare(strict_types=1);

use App\Domain\Banking\BankTransactionMatchSuggester;
use App\Domain\Banking\MatchSourceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for Match (M18, SRS BNK-005: "Baris bank hendaklah
 * dipadankan kepada invois, belanja, pindahan dan dokumen... Skor dan
 * rasional disimpan" — a bank row shall be matched to invoices,
 * expenses, transfers, and documents; score and rationale saved).
 *
 * **v1 scope: Expense, Income, Transfer, and Owner Equity Transaction
 * only** — invoicing and document matching are not yet buildable
 * (neither module exists yet); {@see MatchSourceType} names exactly
 * the four Transactions-domain record types this milestone can
 * actually match against, not BNK-005's full list. Extending this
 * later is additive (a new `MatchSourceType` case plus a new lookup in
 * {@see BankTransactionMatchSuggester}), never a
 * breaking change to this schema.
 *
 * **Only Confirmed matches are ever persisted.** A "reject this
 * suggestion" action, if ever added, would need its own tracking
 * concept — this table has no `status` column because every row that
 * exists *is* a confirmed match, by construction; there is no
 * "Suggested" or "Rejected" row here to distinguish it from.
 *
 * **`bank_transaction_id` is unique** — SRS §10.5's "Baris sumber bank
 * yang sama tidak boleh dipost dua kali kepada peristiwa sama" (the
 * same bank source row must never be posted twice to the same event):
 * a BankTransaction can be confirmed-matched to at most one Journal,
 * ever.
 *
 * **No `updated_at`,  immutable once recorded** — mirrors every other
 * Posted-record-style row in this schema. Un-matching, if ever needed,
 * is a future explicit feature, not a mutation of this row.
 */
return new class extends Migration
{
    private const TABLE = 'matches';

    private const BANK_TRANSACTION_TABLE = 'bank_transactions';

    private const JOURNAL_TABLE = 'journals';

    private const SOURCE_TYPE_CHECK_CONSTRAINT = 'matches_source_type_canonical';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('bank_transaction_id', 64);
            $table->string('journal_id', 64);
            $table->string('source_type', 32);
            $table->string('rationale', 500);
            $table->string('matched_by', 64);
            $table->timestamp('matched_at')->useCurrent();

            $table->primary('id');

            $table->unique('bank_transaction_id');

            $table->foreign('bank_transaction_id')
                ->references('id')->on(self::BANK_TRANSACTION_TABLE);

            $table->foreign(['tenant_id', 'journal_id'])
                ->references(['tenant_id', 'journal_id'])
                ->on(self::JOURNAL_TABLE);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (source_type in (%s))',
                self::TABLE,
                self::SOURCE_TYPE_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (MatchSourceType $type): string => $type->name,
                    MatchSourceType::cases(),
                )),
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * @param  list<string>  $values
     */
    private static function sqlStringList(array $values): string
    {
        return implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            $values,
        ));
    }
};
