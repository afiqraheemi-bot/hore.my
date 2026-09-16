<?php

declare(strict_types=1);

use App\Domain\Banking\BankTransactionMatchSuggester;
use App\Domain\Banking\MatchConfidence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `matches.confidence` (AETS-008 §12.7, `BNK-019`) — the discrete
 * value {@see MatchConfidence} a Match was confirmed under, not a
 * calibrated numeric score. Every Match this migration's own supported
 * matcher ({@see BankTransactionMatchSuggester})
 * can produce is exact-criterion, so `NOT NULL DEFAULT 'Exact'`
 * backfills every pre-existing row truthfully rather than leaving it
 * ambiguous, mirroring `source_type`'s own CHECK-constraint pattern
 * from the owning `matches` migration.
 */
return new class extends Migration
{
    private const TABLE = 'matches';

    private const CONFIDENCE_CHECK_CONSTRAINT = 'matches_confidence_canonical';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('confidence', 32)->default(MatchConfidence::Exact->name);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (confidence in (%s))',
                self::TABLE,
                self::CONFIDENCE_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (MatchConfidence $confidence): string => $confidence->name,
                    MatchConfidence::cases(),
                )),
            ));
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf('alter table %s drop constraint if exists %s', self::TABLE, self::CONFIDENCE_CHECK_CONSTRAINT));
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('confidence');
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
