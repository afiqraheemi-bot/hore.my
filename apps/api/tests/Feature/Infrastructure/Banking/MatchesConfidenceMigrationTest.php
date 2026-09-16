<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Banking;

use App\Domain\Banking\MatchConfidence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Isolated real-PostgreSQL proof for `matches.confidence` (AETS-008
 * §12.7, `BNK-019`) — the migration that adds it, backfills every
 * pre-existing row with `Exact`, and constrains it to canonical
 * {@see MatchConfidence} values.
 */
final class MatchesConfidenceMigrationTest extends TestCase
{
    private const SCHEMA = 'matches_confidence_migration_test';

    private const MIGRATION = 'database/migrations/2026_09_16_010000_add_confidence_to_matches_table.php';

    private ?object $migration = null;

    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection('pgsql')->select('select 1');
            $this->databaseReady = true;
        } catch (\Throwable $exception) {
            $this->markTestSkipped('A real PostgreSQL instance is required: '.$exception->getMessage());
        }

        $connection = DB::connection('pgsql');
        $connection->unprepared('drop schema if exists '.self::SCHEMA.' cascade');
        $connection->unprepared('create schema '.self::SCHEMA);
        $connection->unprepared('set search_path to '.self::SCHEMA);
        $connection->unprepared(<<<'SQL'
            create table matches (
                id varchar(64) primary key
            );
            SQL);

        DB::connection('pgsql')->table('matches')->insert(['id' => 'match-pre-existing']);

        $migration = require base_path(self::MIGRATION);
        if (! is_callable([$migration, 'up']) || ! is_callable([$migration, 'down'])) {
            throw new \LogicException('The matches-confidence migration must expose up() and down().');
        }

        $this->migration = $migration;
        $migration->up();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            DB::connection('pgsql')->unprepared('set search_path to public');
            DB::connection('pgsql')->unprepared('drop schema if exists '.self::SCHEMA.' cascade');
        }

        parent::tearDown();
    }

    public function test_a_pre_existing_row_is_backfilled_as_exact(): void
    {
        $this->assertSame('Exact', DB::connection('pgsql')->table('matches')->value('confidence'));
    }

    public function test_a_new_row_defaults_to_exact(): void
    {
        DB::connection('pgsql')->table('matches')->insert(['id' => 'match-new']);

        $this->assertSame('Exact', DB::connection('pgsql')->table('matches')->where('id', 'match-new')->value('confidence'));
    }

    public function test_a_noncanonical_confidence_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::connection('pgsql')->table('matches')->insert(['id' => 'match-bad', 'confidence' => 'Probably']);
    }

    public function test_migration_reverses_and_reapplies_cleanly(): void
    {
        $this->migration->down();
        $this->assertFalse(DB::connection('pgsql')->getSchemaBuilder()->hasColumn('matches', 'confidence'));

        $this->migration->up();
        $this->assertTrue(DB::connection('pgsql')->getSchemaBuilder()->hasColumn('matches', 'confidence'));
        $this->assertSame('Exact', DB::connection('pgsql')->table('matches')->value('confidence'));
    }
}
