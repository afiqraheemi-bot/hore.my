<?php

declare(strict_types=1);

use App\Domain\Workspace\TaskState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for TaskTransition (ADR-0009, WTS-001 §6, `TSK-001`)
 * — the Task-lifecycle audit trail. Deliberately not `audit_events`
 * (AETS-010) — see ADR-0009 §Decision "Module boundary rules" for why
 * a Task's own transitions are never written to Accounting Core's
 * Journal-scoped audit table. This is the direct schema equivalent of
 * `reconciliation_reopenings`, generalized: where that table records
 * one specific action for Reconciliation, this one records **every**
 * Task state transition, per TSK-001's explicit requirement.
 *
 * **`from_state` is nullable.** A Task's very first transition (its
 * creation, `null -> Received`) has no prior state — recording it here
 * too, rather than only recording transitions after creation, is what
 * makes a Task's full lifecycle reconstructable from this table alone
 * (WTS-001 §1).
 *
 * **No separate `correlation_id` column.** `task_id` already is the
 * stable identifier WTS-001 §6 requires for reconstructing one Task's
 * full lifecycle — introducing a second, redundant identifier would be
 * exactly the invented complexity WTS-000 §6 warns against.
 *
 * **Append-only, no `updated_at`.** A transition record, once written,
 * is never edited — mirroring every other audit-shaped table in this
 * schema (`audit_events`, `reconciliation_reopenings`).
 */
return new class extends Migration
{
    private const TABLE = 'task_transitions';

    private const TASK_TABLE = 'tasks';

    private const FROM_STATE_CHECK_CONSTRAINT = 'task_transitions_from_state_canonical';

    private const TO_STATE_CHECK_CONSTRAINT = 'task_transitions_to_state_canonical';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('transition_id', 64);
            $table->string('tenant_id', 64);
            $table->string('task_id', 64);
            $table->string('actor', 64);
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->string('reason', 500)->nullable();
            $table->string('evidence_reference', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->primary('transition_id');

            $table->foreign(['tenant_id', 'task_id'])
                ->references(['tenant_id', 'task_id'])
                ->on(self::TASK_TABLE);

            $table->index(['tenant_id', 'task_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (from_state is null or from_state in (%s))',
                self::TABLE,
                self::FROM_STATE_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (TaskState $state): string => $state->name,
                    TaskState::cases(),
                )),
            ));

            DB::statement(sprintf(
                'alter table %s add constraint %s check (to_state in (%s))',
                self::TABLE,
                self::TO_STATE_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (TaskState $state): string => $state->name,
                    TaskState::cases(),
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
