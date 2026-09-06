<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for the Transfer record (M14) — the Transactions
 * domain's authoritative business context for a manually-recorded
 * transfer of funds between two of the Tenant's own Accounts, kept
 * traceable to the Posted Journal it produced.
 *
 * Mirrors `incomes`/`expenses`' own schema shape exactly, substituting
 * `source_account_id`/`destination_account_id` for
 * `income_account_id`/`deposit_account_id` — see
 * `2026_09_06_235000_create_incomes_table.php`'s own docblock for the
 * full column-shape and composite-FK reasoning, which applies here
 * unchanged.
 */
return new class extends Migration
{
    private const TABLE = 'transfers';

    private const JOURNAL_TABLE = 'journals';

    private const ACCOUNT_TABLE = 'accounts';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('journal_id', 64);
            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->date('transaction_date');
            $table->string('source_account_id', 64);
            $table->string('destination_account_id', 64);
            $table->string('description', 1000);
            $table->string('evidence_reference', 64)->nullable();
            $table->timestamp('recorded_at')->useCurrent();

            $table->primary('id');

            $table->foreign(['tenant_id', 'journal_id'])
                ->references(['tenant_id', 'journal_id'])
                ->on(self::JOURNAL_TABLE);

            $table->foreign(['tenant_id', 'source_account_id'])
                ->references(['tenant_id', 'account_id'])
                ->on(self::ACCOUNT_TABLE);

            $table->foreign(['tenant_id', 'destination_account_id'])
                ->references(['tenant_id', 'account_id'])
                ->on(self::ACCOUNT_TABLE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
