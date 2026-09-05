<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for the settled Posting Command idempotency
 * association (AETS-007 §6.1, §15): `(TenantId, IdempotencyKey) ->
 * JournalId`. This is schema only — no lookup, replay, recording
 * service, or Posting orchestration exists yet (deferred to a later
 * task, M4-T16).
 *
 * **What this table is, precisely.** A minimal mapping — not a
 * payload store. AETS-007 §15 requires only that Accounting Core be
 * able to determine, from a (Tenant, Idempotency Key) pair, whether a
 * Journal already exists for it, and if so, which one. Once a Journal
 * is Posted it is immutable (`JRN-005`, `JRN-006`) — the Journal
 * itself, reachable via the already-existing `journals`/`journal_lines`
 * schema and `JournalRepository`, remains the authoritative record of
 * what the original logical request was. Storing the original
 * `PostingCommand` payload, or a derived fingerprint of it, here as
 * well would duplicate that authority for no benefit; this table
 * stores only the association `journal_id` needed to find it again.
 *
 * **Column types mirror the existing production schema exactly.**
 * `tenant_id` is `string(64)`, matching every existing `tenant_id`
 * column in `accounts`/`journals`/`journal_lines` (no separate
 * `tenants` table exists in this schema — Tenant is a bare
 * `TenantId` Value Object, never its own persisted entity).
 * `idempotency_key` is `string(64)`, matching
 * `IdempotencyKey::MAX_LENGTH` exactly — the same defensive
 * opaque-identifier bound already established for `IdempotencyKey`,
 * `SourceFingerprint`, `JournalId`, and `AccountId` alike.
 * `journal_id` is `string(64)`, the exact same physical type as
 * `journals.journal_id`, since it is a direct foreign-key reference
 * to that column.
 *
 * **Uniqueness scope — the authoritative primitive AETS-007 §15
 * requires.** `PRIMARY KEY (tenant_id, idempotency_key)` is the
 * database-level uniqueness constraint AETS-007 §15 names as "the
 * final, race-safe authority" for duplicate-command prevention — two
 * concurrent attempts to insert the same pair can never both succeed.
 * This migration establishes that primitive only; it does not
 * implement the recording, lookup, or conflict-handling logic that
 * would use it (M4-T16), and it does not itself prove concurrent
 * behavior — that requires real, genuinely concurrent connections
 * exercising actual application code, proven when that later
 * orchestration exists.
 *
 * **Same-Tenant Journal integrity, by composite foreign key — the
 * same technique already established for `journal_lines`.**
 * `(tenant_id, journal_id) REFERENCES journals (tenant_id,
 * journal_id)` reuses `journals`' own existing `UNIQUE (tenant_id,
 * journal_id)` index (M3-T9) as its target, exactly mirroring how
 * `journal_lines`' own composite foreign keys already work. A mapping
 * row can therefore never reference a nonexistent Journal, or a real
 * Journal belonging to a different Tenant than the mapping's own
 * `tenant_id`.
 *
 * **Deletion policy.** No `ON DELETE`/`ON UPDATE` action is specified
 * on the foreign key, so PostgreSQL's default (`NO ACTION`) applies —
 * exactly the same convention `journal_lines`' own foreign keys
 * already establish: a `journals` row referenced by a mapping row
 * cannot be deleted. No cascade behavior is introduced.
 *
 * **Immutable by design — no field exists to make it otherwise.**
 * There is no `updated_at`, no mutable status column, no attempt
 * counter, no payload JSON, no command snapshot, no `SourceFingerprint`
 * column, and no Actor/Source/Evidence column. `created_at` is the one
 * timestamp this table carries, and it is purely observational
 * metadata — it plays no role in replay or conflict semantics, which
 * depend entirely on the row's mere existence and its `journal_id`,
 * never on when the row was written.
 *
 * **Deliberately not attempted here:** any lookup query, any
 * comparison against a `PostingCommand`'s lines, any replay-result
 * construction, any conflicting-reuse rejection, and any concurrency
 * proof beyond the bare existence of this constraint. All of that is
 * M4-T16 and later work, not this migration's concern.
 */
return new class extends Migration
{
    private const TABLE = 'posting_idempotency_keys';

    private const JOURNAL_TABLE = 'journals';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('tenant_id', 64);
            $table->string('idempotency_key', 64);
            $table->string('journal_id', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['tenant_id', 'idempotency_key']);

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
