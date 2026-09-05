<?php

declare(strict_types=1);

use App\Domain\Accounting\Audit\AuditAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for the Audit Event (AETS-001, Audit Event;
 * AETS-010 §7) — the append-only record that a material action (an
 * ordinary Posting, a Reversal, or a Replacement — AETS-010 §10)
 * happened.
 *
 * **Column types mirror the existing production schema exactly.**
 * `tenant_id`, `actor`, `source`, and `journal_id` are all `string(64)`,
 * the same defensive opaque-identifier bound already established for
 * `TenantId`, `ActorReference`, `SourceReference`, and `JournalId`
 * alike (no separate `tenants` table exists in this schema — Tenant
 * remains a bare Value Object, exactly as `posting_idempotency_keys`
 * (M4-T15) and `journals` (M3-T9) already establish).
 *
 * **`action` — a canonical-value `CHECK`, mirroring `journals.state`
 * and `journals.correction_type`.** `action` stores `AuditAction`'s own
 * case name (`AuditAction::JournalPosted->name === 'JournalPosted'`),
 * translated at the persistence boundary exactly as `JournalState` and
 * `CorrectionType` already are — guarded by a `CHECK` constraint
 * against the enum's own case list, the same technique the `journals`
 * table's own migrations already establish.
 *
 * **Same-Tenant Journal integrity, by composite foreign key.**
 * `(tenant_id, journal_id) REFERENCES journals (tenant_id, journal_id)`
 * reuses `journals`' own existing `UNIQUE (tenant_id, journal_id)` index
 * (M3-T9) as its target, the identical composite-FK convention already
 * established by `journal_lines`, `posting_idempotency_keys`,
 * `posting_source_fingerprints`, and the correction-chain columns on
 * `journals` itself (M5). An Audit Event can therefore never reference
 * a nonexistent Journal, or a real Journal belonging to a different
 * Tenant than the event's own `tenant_id`.
 *
 * **`policy_version` is nullable, and currently always `null`.**
 * AETS-010 §7 requires it be present "only where applicable" — no AI
 * Orchestration module exists yet (AETS-010 §2.2), so no current
 * producer has one to supply. The column exists so a future producer
 * does not require a second migration merely to add it.
 *
 * **`occurred_at` is database-assigned, not domain-supplied** —
 * mirroring `posting_idempotency_keys.created_at` (M4-T15) exactly: the
 * moment an Audit Event is durably recorded is the Time AETS-010 §7
 * requires it carry, never a caller-suppliable value that could be
 * backdated or omitted.
 *
 * **Append-only by design — no field exists to make it otherwise.**
 * There is no `updated_at` and no mutable status column. No repository
 * built against this table exposes an update or delete operation
 * (AETS-010 §7, "MUST NOT be updated or deleted through the
 * application").
 */
return new class extends Migration
{
    private const TABLE = 'audit_events';

    private const JOURNAL_TABLE = 'journals';

    private const ACTION_CHECK_CONSTRAINT = 'audit_events_action_canonical';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 64);
            $table->string('actor', 64);
            $table->string('source', 64);
            $table->string('action', 32);
            $table->string('journal_id', 64);
            $table->string('policy_version', 64)->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->foreign(['tenant_id', 'journal_id'])
                ->references(['tenant_id', 'journal_id'])
                ->on(self::JOURNAL_TABLE);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (action in (%s))',
                self::TABLE,
                self::ACTION_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (AuditAction $action): string => $action->name,
                    AuditAction::cases(),
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
