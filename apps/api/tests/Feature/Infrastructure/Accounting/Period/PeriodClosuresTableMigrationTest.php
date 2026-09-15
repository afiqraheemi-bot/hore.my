<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Accounting\Period;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Isolated real-PostgreSQL proof for the production AETS-014
 * `period_closures` migration. A dedicated schema prevents migration
 * rollback tests from disturbing the shared application tables.
 */
final class PeriodClosuresTableMigrationTest extends TestCase
{
    private const SCHEMA = 'period_closures_migration_test';

    private const MIGRATION = 'database/migrations/2026_09_07_040000_create_period_closures_table.php';

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

        DB::connection('pgsql')->unprepared('drop schema if exists '.self::SCHEMA.' cascade');
        DB::connection('pgsql')->unprepared('create schema '.self::SCHEMA);
        DB::connection('pgsql')->unprepared('set search_path to '.self::SCHEMA);
        DB::connection('pgsql')->unprepared(
            'create table journals (tenant_id varchar(64) not null, journal_id varchar(64) not null, primary key (journal_id), unique (tenant_id, journal_id))',
        );

        $migration = require base_path(self::MIGRATION);
        if (! is_callable([$migration, 'up']) || ! is_callable([$migration, 'down'])) {
            throw new \LogicException('The Period closure migration must expose up() and down().');
        }

        $this->migration = $migration;
        $migration->up();

        DB::connection('pgsql')->table('journals')->insert([
            ['tenant_id' => 'tenant-a', 'journal_id' => 'journal-a'],
            ['tenant_id' => 'tenant-b', 'journal_id' => 'journal-b'],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            DB::connection('pgsql')->unprepared('set search_path to public');
            DB::connection('pgsql')->unprepared('drop schema if exists '.self::SCHEMA.' cascade');
        }

        parent::tearDown();
    }

    public function test_migration_applies_and_reverses_cleanly(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasColumns('period_closures', [
            'tenant_id', 'closed_through_date', 'closing_journal_id', 'closed_at',
        ]));

        $this->migration?->down();
        $this->assertFalse(Schema::connection('pgsql')->hasTable('period_closures'));

        $this->migration?->up();
        $this->assertTrue(Schema::connection('pgsql')->hasTable('period_closures'));
    }

    public function test_same_tenant_cannot_close_the_same_date_twice(): void
    {
        $this->insertClosure('tenant-a', '2026-08-31', 'journal-a');

        $this->expectException(QueryException::class);
        $this->insertClosure('tenant-a', '2026-08-31', 'journal-a');
    }

    public function test_same_closed_date_is_independent_between_tenants(): void
    {
        $this->insertClosure('tenant-a', '2026-08-31', 'journal-a');
        $this->insertClosure('tenant-b', '2026-08-31', 'journal-b');

        $this->assertSame(2, DB::connection('pgsql')->table('period_closures')->count());
    }

    public function test_closure_cannot_reference_a_nonexistent_journal(): void
    {
        $this->expectException(QueryException::class);
        $this->insertClosure('tenant-a', '2026-08-31', 'journal-missing');
    }

    public function test_closure_cannot_reference_another_tenants_journal(): void
    {
        $this->expectException(QueryException::class);
        $this->insertClosure('tenant-a', '2026-08-31', 'journal-b');
    }

    public function test_a_referenced_closing_journal_cannot_be_deleted(): void
    {
        $this->insertClosure('tenant-a', '2026-08-31', 'journal-a');

        $this->expectException(QueryException::class);
        DB::connection('pgsql')->table('journals')->where('journal_id', 'journal-a')->delete();
    }

    public function test_table_has_no_mutable_or_extraneous_columns(): void
    {
        $columns = Schema::connection('pgsql')->getColumnListing('period_closures');
        sort($columns);

        $this->assertSame(
            ['closed_at', 'closed_through_date', 'closing_journal_id', 'tenant_id'],
            $columns,
        );
    }

    private function insertClosure(string $tenantId, string $date, string $journalId): void
    {
        DB::connection('pgsql')->table('period_closures')->insert([
            'tenant_id' => $tenantId,
            'closed_through_date' => $date,
            'closing_journal_id' => $journalId,
        ]);
    }
}
