<?php

declare(strict_types=1);

use App\Domain\Workspace\TaskState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for Task (ADR-0009, WTS-001) — the Workspace and
 * Task module's own aggregate, structurally separate from every
 * Accounting Core table. See WTS-001 §3 for the full state list and
 * §4 for the allowed transition table this row's `state` column moves
 * through.
 *
 * **`task_id` is the primary key column name, not `id`** — mirroring
 * the `journal_id`/`account_id` convention already established
 * throughout the Accounting Core schema, so a future composite
 * `(tenant_id, task_id)` foreign key from `proposals` and
 * `task_transitions` reads the same way those tables' existing
 * composite FKs already do.
 *
 * **`result_journal_id` is a nullable composite FK onto `journals`.**
 * It is set only once a Task reaches `Completed` (WTS-001 §3) and is
 * the sole link between a Task's own audit trail and Accounting Core's
 * Journal-scoped one (ADR-0009 Decision, "two audit trails, one
 * story"). It is never a duplicated copy of Journal data.
 *
 * **No `updated_at`-driven mutation of anything but `state`,
 * `completed_at`, `result_journal_id`, and `failure_reason`.** Every
 * other column is immutable for a Task's lifetime, exactly mirroring
 * the `reconciliations` table's own "only state and completed_at ever
 * change" convention.
 */
return new class extends Migration
{
    private const TABLE = 'tasks';

    private const JOURNAL_TABLE = 'journals';

    private const STATE_CHECK_CONSTRAINT = 'tasks_state_canonical';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('tenant_id', 64);
            $table->string('task_id', 64);
            $table->string('state', 32);
            $table->string('result_journal_id', 64)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->primary('task_id');
            $table->unique(['tenant_id', 'task_id']);

            $table->foreign(['tenant_id', 'result_journal_id'])
                ->references(['tenant_id', 'journal_id'])
                ->on(self::JOURNAL_TABLE);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (state in (%s))',
                self::TABLE,
                self::STATE_CHECK_CONSTRAINT,
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
