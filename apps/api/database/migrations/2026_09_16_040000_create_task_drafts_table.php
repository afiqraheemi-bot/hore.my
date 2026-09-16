<?php

declare(strict_types=1);

use App\Domain\Workspace\CommandType;
use App\Domain\Workspace\Proposal;
use App\Domain\Workspace\TaskService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for Task Draft (WTS-001 v3.0.0, TSK-013) — the
 * durable holding place for a Task's submitted payload while it sits
 * in `NeedsInformation`, before an Account decision has been supplied
 * and a real {@see Proposal} can exist.
 *
 * **One row per Task, never more.** `task_id` is the primary key —
 * there is exactly one Draft per Task, written once by
 * {@see TaskService::saveForLaterCompletion()}
 * and never updated afterward; completing the deferred decision
 * ({@see TaskService::provideInformation()})
 * reads it but leaves it in place, mirroring a Proposal's own
 * immutable, append-only convention.
 *
 * **No Account columns.** The deferred decision this table exists to
 * hold open *is* the Account choice (TSK-013) — carrying a nullable
 * Account column here would blur exactly the distinction this table
 * exists to keep sharp between "not yet decided" and "decided."
 *
 * **`evidence_reference` has no foreign key**, for the identical
 * reason `proposals.evidence_reference` has none (see that
 * migration's own docblock).
 */
return new class extends Migration
{
    private const TABLE = 'task_drafts';

    private const TASK_TABLE = 'tasks';

    private const COMMAND_TYPE_CHECK_CONSTRAINT = 'task_drafts_command_type_canonical';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('tenant_id', 64);
            $table->string('task_id', 64);
            $table->string('command_type', 32);
            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->date('transaction_date');
            $table->string('description', 1000);
            $table->string('evidence_reference', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->primary('task_id');

            $table->foreign(['tenant_id', 'task_id'])
                ->references(['tenant_id', 'task_id'])
                ->on(self::TASK_TABLE);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (command_type in (%s))',
                self::TABLE,
                self::COMMAND_TYPE_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (CommandType $type): string => $type->name,
                    CommandType::cases(),
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
