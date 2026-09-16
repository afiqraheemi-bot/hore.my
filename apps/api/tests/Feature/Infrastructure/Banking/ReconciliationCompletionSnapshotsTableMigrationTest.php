<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Banking;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Isolated real-PostgreSQL proof for the `reconciliation_completion_snapshots`
 * table (AETS-008 §12.3, `BNK-017`): schema shape, foreign-key
 * integrity, and migration reversibility.
 */
final class ReconciliationCompletionSnapshotsTableMigrationTest extends TestCase
{
    private const SCHEMA = 'recon_completion_snapshots_migration_test';

    private const MIGRATION = 'database/migrations/2026_09_16_020000_create_reconciliation_completion_snapshots_table.php';

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
            create table reconciliations (
                id varchar(64) primary key,
                tenant_id varchar(64) not null,
                unique (tenant_id, id)
            );
            create table bank_transactions (
                id varchar(64) primary key
            );
            SQL);

        $connection->table('reconciliations')->insert(['id' => 'reconciliation-a', 'tenant_id' => 'tenant-a']);
        $connection->table('reconciliations')->insert(['id' => 'reconciliation-b', 'tenant_id' => 'tenant-b']);
        $connection->table('bank_transactions')->insert(['id' => 'transaction-1']);

        $migration = require base_path(self::MIGRATION);
        if (! is_callable([$migration, 'up']) || ! is_callable([$migration, 'down'])) {
            throw new \LogicException('The reconciliation-completion-snapshots migration must expose up() and down().');
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

    public function test_a_valid_snapshot_row_is_accepted(): void
    {
        $this->insertSnapshotRow('snapshot-1', 'tenant-a', 'reconciliation-a', 'transaction-1');

        $this->assertSame(1, DB::connection('pgsql')->table('reconciliation_completion_snapshots')->count());
    }

    public function test_a_snapshot_row_cannot_reference_a_reconciliation_for_another_tenant(): void
    {
        $this->expectException(QueryException::class);

        $this->insertSnapshotRow('snapshot-bad', 'tenant-a', 'reconciliation-b', 'transaction-1');
    }

    public function test_a_snapshot_row_cannot_reference_a_nonexistent_bank_transaction(): void
    {
        $this->expectException(QueryException::class);

        $this->insertSnapshotRow('snapshot-bad', 'tenant-a', 'reconciliation-a', 'transaction-does-not-exist');
    }

    public function test_the_same_reconciliation_and_transaction_cannot_be_snapshotted_twice(): void
    {
        $this->insertSnapshotRow('snapshot-1', 'tenant-a', 'reconciliation-a', 'transaction-1');

        $this->expectException(QueryException::class);

        $this->insertSnapshotRow('snapshot-2', 'tenant-a', 'reconciliation-a', 'transaction-1');
    }

    public function test_migration_reverses_and_reapplies_cleanly(): void
    {
        $this->migration->down();
        $this->assertFalse(DB::connection('pgsql')->getSchemaBuilder()->hasTable('reconciliation_completion_snapshots'));

        $this->migration->up();
        $this->assertTrue(DB::connection('pgsql')->getSchemaBuilder()->hasTable('reconciliation_completion_snapshots'));

        $this->insertSnapshotRow('snapshot-1', 'tenant-a', 'reconciliation-a', 'transaction-1');
        $this->assertSame(1, DB::connection('pgsql')->table('reconciliation_completion_snapshots')->count());
    }

    private function insertSnapshotRow(string $id, string $tenantId, string $reconciliationId, string $bankTransactionId): void
    {
        DB::connection('pgsql')->table('reconciliation_completion_snapshots')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'reconciliation_id' => $reconciliationId,
            'bank_transaction_id' => $bankTransactionId,
            'completed_at' => now(),
        ]);
    }
}
