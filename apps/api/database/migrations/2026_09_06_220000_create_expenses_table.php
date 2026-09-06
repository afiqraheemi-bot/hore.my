<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for the Expense record (M7) — the Transactions
 * domain's authoritative business context for a manually-recorded
 * expense, kept traceable to the Posted Journal it produced.
 *
 * **Not a Journal, not a duplicate of one.** This table carries the
 * business-facing fields SRS TRX-004 requires be editable before
 * posting (amount, transaction date, Expense Account, Payment Account,
 * description, optional Evidence reference) — none of which the
 * `journals`/`journal_lines` schema itself carries (a Journal Line is
 * only {Account, Money, Direction}, per AETS-004 §7). `journal_id` is
 * the sole link between the two, never a duplicated copy of Journal
 * data.
 *
 * **Column types mirror the existing production schema exactly.**
 * `tenant_id`, `journal_id`, `expense_account_id`, `payment_account_id`
 * are all `string(64)` — the same defensive opaque-identifier bound
 * already established throughout this schema. `id` (the Expense's own
 * identity) is likewise `string(64)`, mirroring `journal_id`'s own
 * physical shape without being the same identity space (M7's own
 * `ExpenseId`, distinct from `JournalId`).
 *
 * **Three composite foreign keys, all tenant-safe** — `(tenant_id,
 * journal_id)` onto `journals`, `(tenant_id, expense_account_id)` and
 * `(tenant_id, payment_account_id)` onto `accounts` — the identical
 * composite-FK convention already established throughout this schema
 * (`journal_lines`, `posting_idempotency_keys`, `audit_events`,
 * `journal_evidence_links`).
 *
 * **`transaction_date` is a plain `DATE` column, not a timestamp.** An
 * accounting transaction date is a calendar day, not a moment in time
 * — this is deliberately distinct from `recorded_at` (when the system
 * durably recorded the Expense, database-assigned, purely
 * observational, mirroring `audit_events.occurred_at`).
 *
 * **`evidence_reference` is nullable** — an Expense with no independent
 * evidence is not required to fabricate one (AETS-010 §9), exactly as
 * `journal_evidence_links` already establishes for the underlying
 * linkage row this same reference, when present, also produces there.
 *
 * **No `updated_at`.** An Expense is immutable once recorded — a
 * mistake is corrected via M5 Reversal/Replacement of its Journal, never
 * by editing this row in place.
 */
return new class extends Migration
{
    private const TABLE = 'expenses';

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
            $table->string('expense_account_id', 64);
            $table->string('payment_account_id', 64);
            $table->string('description', 1000);
            $table->string('evidence_reference', 64)->nullable();
            $table->timestamp('recorded_at')->useCurrent();

            $table->primary('id');

            $table->foreign(['tenant_id', 'journal_id'])
                ->references(['tenant_id', 'journal_id'])
                ->on(self::JOURNAL_TABLE);

            $table->foreign(['tenant_id', 'expense_account_id'])
                ->references(['tenant_id', 'account_id'])
                ->on(self::ACCOUNT_TABLE);

            $table->foreign(['tenant_id', 'payment_account_id'])
                ->references(['tenant_id', 'account_id'])
                ->on(self::ACCOUNT_TABLE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
