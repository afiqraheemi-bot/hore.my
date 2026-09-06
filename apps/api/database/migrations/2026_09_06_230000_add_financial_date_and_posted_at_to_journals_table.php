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
 * (`Y-m-d` only, with no timezone conversion: comparison reads the
 * calendar date already carried by the value, it never shifts it
 * across a timezone boundary).
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
 * **M8A: fails loudly instead of fabricating a Financial Date
 * (reviewed and hardened after M8's initial CTO QA pass).** An earlier
 * revision of this migration backfilled any pre-existing `journals` row
 * with a synthetic placeholder date before tightening the column to
 * `NOT NULL`, purely to let the migration succeed against this test
 * suite's shared, long-lived PostgreSQL instance. That is exactly the
 * kind of fabricated financial fact Accounting Core must never produce
 * — a placeholder date has no accounting meaning, and a schema
 * migration is not a legitimate place to invent one merely to satisfy
 * a `NOT NULL` constraint. This revision instead refuses outright: if
 * `journals` already contains any row, {@see up()} throws before
 * touching the schema at all, since every such row necessarily predates
 * this column's existence and therefore has no legitimate Financial
 * Date on record — there is no query this migration could run to
 * "recover" one, and it does not try (no inference from `created_at`,
 * no inference from an Audit Event's `occurred_at`, no `CURRENT_DATE`).
 * For the current greenfield state (no production data yet, and the
 * test suite's own fixtures always apply this migration immediately
 * after freshly creating `journals`, before any row exists), this path
 * is never exercised: `journals` is empty, and the column is added
 * directly as `NOT NULL`. Reconciling a genuinely populated `journals`
 * table in some future deployment is a separate, explicit data-migration
 * decision — reviewed and backfilled with each row's real, historically
 * accurate Financial Date — never an automatic step of this migration.
 */
return new class extends Migration
{
    private const JOURNAL_TABLE = 'journals';

    /**
     * @throws RuntimeException if `journals` already contains any row
     *                          — see this file's own class docblock for why this migration
     *                          refuses to proceed rather than fabricating a Financial Date
     *                          for pre-existing rows.
     */
    public function up(): void
    {
        $existingRowCount = DB::table(self::JOURNAL_TABLE)->count();

        if ($existingRowCount > 0) {
            throw new RuntimeException(sprintf(
                'Refusing to add a NOT NULL "financial_date" column to "journals": '
                .'%d existing row(s) predate this migration and have no legitimate '
                .'Financial Date on record. Accounting Core never fabricates financial '
                .'data to satisfy a schema constraint or infer an accounting date from '
                .'unrelated timestamps. Before re-running '
                .'this migration, reconcile each existing Journal with its own real, '
                .'historically accurate Financial Date via a dedicated, reviewed '
                .'data-migration — this schema migration will not do that for you.',
                $existingRowCount,
            ));
        }

        Schema::table(self::JOURNAL_TABLE, function (Blueprint $table): void {
            $table->date('financial_date');
            $table->timestamp('posted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(self::JOURNAL_TABLE, function (Blueprint $table): void {
            $table->dropColumn(['financial_date', 'posted_at']);
        });
    }
};
