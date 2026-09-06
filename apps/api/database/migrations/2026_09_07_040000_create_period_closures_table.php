<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for Period closures (AETS-014) — an append-only
 * history of every Period a Tenant has closed. The Tenant's current
 * closed-period watermark is simply the row with the greatest
 * `closed_through_date` for that Tenant; no separate "current period"
 * row is maintained, since it is always derivable from this history
 * (mirroring this codebase's own "rebuildable, not stored" convention
 * already established for Reporting, AETS-009 §5).
 *
 * **`(tenant_id, closed_through_date)` is the primary key**, not a
 * surrogate `id` — this makes "the same date closed twice" a database-
 * enforced impossibility, not merely an application-level check, the
 * same defense-in-depth precedent `posting_idempotency_keys` already
 * establishes for its own uniqueness guarantee.
 *
 * **`(tenant_id, closing_journal_id)` is a composite foreign key onto
 * `journals`**, the same tenant-safe convention every other table on
 * the Posting transaction path already uses.
 *
 * **No `updated_at`, no delete path.** A closure, once recorded, is a
 * permanent fact — reopening a Period (AETS-014 §2.2, deferred) would
 * be its own explicit, audited event, never a mutation or removal of
 * this row.
 */
return new class extends Migration
{
    private const TABLE = 'period_closures';

    private const JOURNAL_TABLE = 'journals';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('tenant_id', 64);
            $table->date('closed_through_date');
            $table->string('closing_journal_id', 64);
            $table->timestamp('closed_at')->useCurrent();

            $table->primary(['tenant_id', 'closed_through_date']);

            $table->foreign(['tenant_id', 'closing_journal_id'])
                ->references(['tenant_id', 'journal_id'])
                ->on(self::JOURNAL_TABLE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
