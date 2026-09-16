<?php

declare(strict_types=1);

use App\Domain\Workspace\TaskService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `supersedes_task_id` to `tasks` (WTS-001 v3.0.0, TSK-014) — an
 * immutable, opaque, nullable self-reference naming the Task a
 * correction replaces, set only at creation
 * ({@see TaskService::supersedeAndSubmitCorrection()})
 * and never updated afterward. Extends TSK-008's "creates a new Task
 * referencing the original" principle from the `Completed` case to
 * the `NeedsReview -> Superseded` case §4 already lists.
 *
 * Opaque per TSK-014 — this column is never read for anything but
 * display; nothing here validates it against the referenced Task's
 * own content.
 */
return new class extends Migration
{
    private const TABLE = 'tasks';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('supersedes_task_id', 64)->nullable()->after('task_id');

            $table->foreign(['tenant_id', 'supersedes_task_id'], 'tasks_supersedes_task_id_foreign')
                ->references(['tenant_id', 'task_id'])
                ->on(self::TABLE);
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropForeign('tasks_supersedes_task_id_foreign');
            $table->dropColumn('supersedes_task_id');
        });
    }
};
