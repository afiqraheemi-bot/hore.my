<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for the settled Source Fingerprint duplicate-
 * source association (AETS-007 §6.2): `(TenantId, SourceFingerprint)
 * -> JournalId`. This is schema only — no lookup, enforcement,
 * requirement-detection, or Posting orchestration exists yet.
 *
 * **What this table is, and what it is not.** A Source Fingerprint
 * identifies duplicate *source data* (e.g. the same imported bank
 * line), never a duplicate *command* — that is the Idempotency Key's
 * own, entirely separate guarantee (§6.1, M4-T15's
 * `posting_idempotency_keys`). AETS-007 §6.2 is explicit that the two
 * mechanisms "operate together, never as alternatives": this table
 * exists purely to give Source Fingerprint its own independent
 * uniqueness constraint, never to duplicate or substitute for the
 * Idempotency Key mapping.
 *
 * **Column types mirror `posting_idempotency_keys` exactly**, for
 * exactly the same reasons that migration's own docblock (M4-T15)
 * already states: `tenant_id` is `string(64)` (no `tenants` table
 * exists in this schema); `source_fingerprint` is `string(64)`,
 * matching `SourceFingerprint::MAX_LENGTH` exactly; `journal_id` is
 * `string(64)`, the exact physical type of `journals.journal_id`,
 * since it is a direct foreign-key reference to that column.
 *
 * **Uniqueness scope.** `PRIMARY KEY (tenant_id, source_fingerprint)`
 * — AETS-007 §6.2's own guarantee is scoped "for the same Tenant"
 * (mirrored by ATS-007's `POST-T031`: "the same Source Fingerprint,
 * for the same Tenant, do not both give rise to a Posted Journal").
 * This migration establishes that database-level primitive only; it
 * does not implement the recording, lookup, or requirement-detection
 * logic that would use it — including *whether* a given command
 * requires a Source Fingerprint at all, which AETS-007 §6.2/§26
 * explicitly defers to a future calling module, not decided here.
 *
 * **Same-Tenant Journal integrity, by composite foreign key** — the
 * identical technique already established for
 * `posting_idempotency_keys` and `journal_lines`: `(tenant_id,
 * journal_id) REFERENCES journals (tenant_id, journal_id)`, reusing
 * `journals`' own `UNIQUE (tenant_id, journal_id)` index (M3-T9). A
 * mapping row can therefore never reference a nonexistent Journal, or
 * a real Journal belonging to a different Tenant than the mapping's
 * own `tenant_id`.
 *
 * **Deletion policy.** No `ON DELETE`/`ON UPDATE` action is specified,
 * so PostgreSQL's default (`NO ACTION`) applies — identical to
 * `posting_idempotency_keys`: a `journals` row referenced by a
 * mapping row cannot be deleted. No cascade behavior is introduced.
 *
 * **Immutable by design.** No `updated_at`, no mutable status, no
 * attempt counter, no payload, no command snapshot, and no
 * Actor/Source/Evidence column — identical rationale to
 * `posting_idempotency_keys`. `created_at` is observational metadata
 * only and plays no role in duplicate-source semantics.
 *
 * **Deliberately not attempted here:** any lookup query, any
 * requirement-detection policy (`POST-026`, `POST-027`), any
 * comparison against a `PostingCommand`, any Idempotency Key
 * interaction, and any concurrency proof beyond the bare existence of
 * this constraint. All of that is later work, not this migration's
 * concern.
 */
return new class extends Migration
{
    private const TABLE = 'posting_source_fingerprints';

    private const JOURNAL_TABLE = 'journals';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('tenant_id', 64);
            $table->string('source_fingerprint', 64);
            $table->string('journal_id', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['tenant_id', 'source_fingerprint']);

            $table->foreign(['tenant_id', 'journal_id'])
                ->references(['tenant_id', 'journal_id'])
                ->on(self::JOURNAL_TABLE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
