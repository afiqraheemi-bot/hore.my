<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the database-owned ordering key required by WTS-003 TAC-001.
 * A timestamp cannot order multiple transitions recorded within its
 * own precision, while an identity value preserves insertion order
 * without allowing a caller to choose or reuse the value.
 */
return new class extends Migration
{
    private const TABLE = 'task_transitions';

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('The task transition sequence requires PostgreSQL identity-column semantics.');
        }

        DB::statement('ALTER TABLE task_transitions ADD COLUMN transition_sequence BIGINT GENERATED ALWAYS AS IDENTITY');
        DB::statement('CREATE UNIQUE INDEX task_transitions_transition_sequence_unique ON task_transitions (transition_sequence)');
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('transition_sequence');
        });
    }
};
