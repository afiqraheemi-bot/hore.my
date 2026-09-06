<?php

declare(strict_types=1);

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Posting\PostingCommandLogicalEquivalence;
use App\Infrastructure\Accounting\Journal\JournalPersistenceAdapter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the production `journals` table (M3-T9) with the two
 * time concepts SRS §5.1 requires kept separate from each other and
 * from `created_at` (M8, AETS-004 §9.1): `financial_date` (the
 * ledger-authoritative accounting date) and `posted_at` (the moment a
 * Journal durably became Posted).
 *
 * **`financial_date` — `DATE`, `NOT NULL`, no default.** Every Journal
 * has one, from the moment it is first created as a Draft — it is
 * never optional and never silently defaulted to "today" or to
 * `created_at`; every caller (`DraftJournalAssembler`, the M5
 * correction path) already supplies it explicitly as of this
 * migration's companion domain changes. Stored as `DATE` (not
 * `TIMESTAMP`) because it identifies an accounting *day*, not a
 * moment — matching how {@see Journal}'s
 * own `financialDate()` is compared by
 * {@see PostingCommandLogicalEquivalence}
 * (`Y-m-d` only).
 *
 * **`posted_at` — `TIMESTAMP`, nullable.** `NULL` for a Draft, and set
 * exactly once — at the durable moment `Journal::post()` transitions
 * it to Posted — never before, and never changed afterward
 * (`JournalRepository::save()`'s own immutability checks already
 * reject any other Journal mutation once Posted). It is never the
 * same column as `created_at`: a Journal can be created as a Draft
 * and posted later, or created and posted in the same call, and only
 * `posted_at` reflects the latter.
 *
 * **Deliberately no `CHECK` constraint tying `posted_at` to `state`.**
 * {@see Journal}'s own
 * `InconsistentPostedAtException` guard already enforces "Draft implies
 * null, Posted implies present" at the domain boundary on every read
 * and write path through {@see JournalPersistenceAdapter}.
 * A database-level mirror of that specific two-column relationship
 * would need to reference `state`, a plain `string` column with its
 * own separate canonical-value `CHECK` — expressible, but not
 * something any existing migration in this schema does for a
 * cross-column relationship; kept as a domain-only guarantee to match
 * that existing convention.
 *
 * **Adds `financial_date` nullable first, then enforces `NOT NULL`.**
 * No production data exists yet for this greenfield system, so there
 * is nothing to backfill in a real deployment — but this test suite's
 * shared PostgreSQL instance accumulates `journals` rows across many
 * independent test classes within one run, some of which pre-date this
 * migration and legitimately still exist when it applies. Adding
 * `financial_date` directly as `NOT NULL` would fail outright the
 * moment any such row exists (`23502`). This migration therefore adds
 * the column nullable, backfills any existing row with a fixed,
 * clearly-synthetic placeholder date, then tightens the column to
 * `NOT NULL` — the standard, safe shape for introducing a required
 * column onto a populated table. This backfill is a one-time migration
 * mechanic only, confined entirely to this file; it does not weaken,
 * bypass, or contradict the domain's own "never self-generate a
 * Financial Date" rule (§9.1), which governs how the *application*
 * constructs a Journal, not how a historical schema migration reconciles
 * pre-existing rows that predate the column's own existence.
 */
return new class extends Migration
{
    private const JOURNAL_TABLE = 'journals';

    /**
     * A fixed, clearly-synthetic placeholder — never `CURRENT_DATE`,
     * never derived from any other column — used solely to backfill a
     * pre-existing row that has no real Financial Date to report,
     * before the column is tightened to `NOT NULL` below.
     */
    private const BACKFILL_PLACEHOLDER_DATE = '1970-01-01';

    public function up(): void
    {
        Schema::table(self::JOURNAL_TABLE, function (Blueprint $table): void {
            $table->date('financial_date')->nullable();
            $table->timestamp('posted_at')->nullable();
        });

        DB::table(self::JOURNAL_TABLE)
            ->whereNull('financial_date')
            ->update(['financial_date' => self::BACKFILL_PLACEHOLDER_DATE]);

        Schema::table(self::JOURNAL_TABLE, function (Blueprint $table): void {
            $table->date('financial_date')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table(self::JOURNAL_TABLE, function (Blueprint $table): void {
            $table->dropColumn(['financial_date', 'posted_at']);
        });
    }
};
