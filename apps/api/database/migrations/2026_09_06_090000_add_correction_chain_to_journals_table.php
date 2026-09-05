<?php

declare(strict_types=1);

use App\Domain\Accounting\Journal\CorrectionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the production `journals` table (M3-T9) with the
 * correction-chain a Reversal or Replacement Journal carries (M5,
 * AETS-004 §6, §16, §17): `correction_type` and `corrected_journal_id`.
 *
 * **Why on `journals` directly, not a separate table.** Unlike
 * `posting_idempotency_keys`/`posting_source_fingerprints`
 * (independent mapping concerns, M4), correction-chain is a property
 * of the Journal aggregate itself (AETS-004 §6: "A Journal that is a
 * Reversal or a Replacement additionally carries the correction-chain
 * reference(s)... an ordinary Journal carries none") — reading a
 * Journal by `JournalRepository::findById()` must reconstitute this
 * fact directly, without an extra join, exactly as it already does
 * for `state`.
 *
 * **Self-referencing composite foreign key.** `(tenant_id,
 * corrected_journal_id) REFERENCES journals (tenant_id, journal_id)`
 * reuses `journals`' own existing `UNIQUE (tenant_id, journal_id)`
 * index (M3-T9) as its target — the identical composite-FK convention
 * already established by `journal_lines`, `posting_idempotency_keys`,
 * and `posting_source_fingerprints`. This alone guarantees a
 * correction can never reference a nonexistent Journal, or a real
 * Journal belonging to a different Tenant (M5 architecture decision
 * §9: tenant isolation via composite FK).
 *
 * **Consistency, enforced at the database boundary too.** A `CHECK`
 * constraint requires `correction_type` and `corrected_journal_id` to
 * be both `NULL` (an ordinary Journal) or both present (a correction)
 * — never one without the other — mirroring
 * {@see App\Domain\Accounting\Journal\Journal}'s own domain-level
 * `InconsistentCorrectionMetadataException` guard as defence in depth,
 * the same relationship already established between this migration's
 * own canonical-value `CHECK` on `correction_type` and the Journal
 * domain's own enum.
 *
 * **Deliberately no `UNIQUE` constraint on `corrected_journal_id`.**
 * AETS-004 §16 explicitly defers "whether, or how many times, a given
 * Journal may be reversed... to the Posting Rules specification
 * (AETS-006)" — this migration does not invent that restriction. A
 * single Original may have more than one Reversal, and a single
 * Reversal may have more than one Replacement, at the database level;
 * any workflow restriction remains future, out-of-scope work.
 *
 * **Deliberately not attempted here:** any check that a referenced
 * Journal is Posted, or that a Reversal only references an ordinary
 * Journal (never another correction), or that a Replacement only
 * references a Reversal (never an ordinary Journal or another
 * Replacement). None of these can be expressed as a single-row `CHECK`
 * constraint without a trigger, which is not this schema's existing
 * convention — they remain domain/service-level guarantees
 * ({@see Journal::reverse()}, {@see Journal::createReplacement()}).
 */
return new class extends Migration
{
    private const JOURNAL_TABLE = 'journals';

    private const CORRECTION_TYPE_CHECK_CONSTRAINT = 'journals_correction_type_canonical';

    private const CORRECTION_CONSISTENCY_CHECK_CONSTRAINT = 'journals_correction_consistency';

    public function up(): void
    {
        Schema::table(self::JOURNAL_TABLE, function (Blueprint $table): void {
            $table->string('correction_type', 32)->nullable();
            $table->string('corrected_journal_id', 64)->nullable();

            $table->foreign(['tenant_id', 'corrected_journal_id'])
                ->references(['tenant_id', 'journal_id'])
                ->on(self::JOURNAL_TABLE);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (correction_type in (%s))',
                self::JOURNAL_TABLE,
                self::CORRECTION_TYPE_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (CorrectionType $type): string => $type->name,
                    CorrectionType::cases(),
                )),
            ));

            DB::statement(sprintf(
                'alter table %s add constraint %s check ((correction_type is null and corrected_journal_id is null) or (correction_type is not null and corrected_journal_id is not null))',
                self::JOURNAL_TABLE,
                self::CORRECTION_CONSISTENCY_CHECK_CONSTRAINT,
            ));
        }
    }

    public function down(): void
    {
        Schema::table(self::JOURNAL_TABLE, function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'corrected_journal_id']);
            $table->dropColumn(['correction_type', 'corrected_journal_id']);
        });
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
