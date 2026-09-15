<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Banking;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Isolated real-PostgreSQL proof that every Banking relationship is
 * tenant-coherent at the database boundary, even if a caller bypasses
 * tenant-scoped repositories entirely.
 */
final class BankingTenantIsolationMigrationTest extends TestCase
{
    private const SCHEMA = 'banking_tenant_isolation_migration_test';

    private const MIGRATION = 'database/migrations/2026_09_15_000000_harden_banking_tenant_isolation.php';

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
        $this->createBaselineTables();

        $migration = require base_path(self::MIGRATION);
        if (! is_callable([$migration, 'up']) || ! is_callable([$migration, 'down'])) {
            throw new \LogicException('The Banking tenant-isolation migration must expose up() and down().');
        }

        $this->migration = $migration;
        $migration->up();
        $this->seedParents();
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            DB::connection('pgsql')->unprepared('set search_path to public');
            DB::connection('pgsql')->unprepared('drop schema if exists '.self::SCHEMA.' cascade');
        }

        parent::tearDown();
    }

    public function test_a_valid_same_tenant_relationship_chain_is_accepted(): void
    {
        $this->insertTransaction('transaction-a', 'tenant-a', 'bank-a', 'batch-a');
        $this->insertMatch('match-a', 'tenant-a', 'transaction-a', 'journal-a');
        $this->insertReconciliation('reconciliation-a', 'tenant-a', 'bank-a');
        $this->insertReopening('reopening-a', 'tenant-a', 'reconciliation-a');

        $this->assertSame(1, DB::connection('pgsql')->table('matches')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('reconciliation_reopenings')->count());
    }

    public function test_import_batch_cannot_reference_another_tenants_bank_account(): void
    {
        $this->expectException(QueryException::class);
        $this->insertBatch('batch-cross-tenant', 'tenant-a', 'bank-b');
    }

    public function test_bank_transaction_cannot_reference_another_tenants_bank_account(): void
    {
        $this->expectException(QueryException::class);
        $this->insertTransaction('transaction-cross-account', 'tenant-a', 'bank-b', 'batch-a');
    }

    public function test_bank_transaction_cannot_reference_an_import_batch_for_another_bank_account(): void
    {
        $this->expectException(QueryException::class);
        $this->insertTransaction('transaction-cross-batch', 'tenant-a', 'bank-a', 'batch-a-2');
    }

    public function test_match_cannot_reference_another_tenants_bank_transaction(): void
    {
        $this->insertTransaction('transaction-b', 'tenant-b', 'bank-b', 'batch-b');

        $this->expectException(QueryException::class);
        $this->insertMatch('match-cross-tenant', 'tenant-a', 'transaction-b', 'journal-a');
    }

    public function test_reconciliation_cannot_reference_another_tenants_bank_account(): void
    {
        $this->expectException(QueryException::class);
        $this->insertReconciliation('reconciliation-cross-tenant', 'tenant-a', 'bank-b');
    }

    public function test_reopening_cannot_reference_another_tenants_reconciliation(): void
    {
        $this->insertReconciliation('reconciliation-b', 'tenant-b', 'bank-b');

        $this->expectException(QueryException::class);
        $this->insertReopening('reopening-cross-tenant', 'tenant-a', 'reconciliation-b');
    }

    public function test_migration_reverses_and_reapplies_cleanly(): void
    {
        $this->migration?->down();
        $this->insertBatch('batch-cross-tenant', 'tenant-a', 'bank-b');
        $this->assertSame(1, DB::connection('pgsql')->table('bank_statement_import_batches')->where('id', 'batch-cross-tenant')->count());

        DB::connection('pgsql')->table('bank_statement_import_batches')->where('id', 'batch-cross-tenant')->delete();
        $this->migration?->up();

        $this->expectException(QueryException::class);
        $this->insertBatch('batch-cross-tenant-again', 'tenant-a', 'bank-b');
    }

    private function createBaselineTables(): void
    {
        DB::connection('pgsql')->unprepared(<<<'SQL'
            create table bank_accounts (
                id varchar(64) primary key,
                tenant_id varchar(64) not null
            );
            create table bank_statement_import_batches (
                id varchar(64) primary key,
                tenant_id varchar(64) not null,
                bank_account_id varchar(64) not null
            );
            create table bank_transactions (
                id varchar(64) primary key,
                tenant_id varchar(64) not null,
                bank_account_id varchar(64) not null,
                import_batch_id varchar(64) not null
            );
            create table journals (
                journal_id varchar(64) primary key,
                tenant_id varchar(64) not null,
                unique (tenant_id, journal_id)
            );
            create table matches (
                id varchar(64) primary key,
                tenant_id varchar(64) not null,
                bank_transaction_id varchar(64) not null,
                journal_id varchar(64) not null,
                foreign key (tenant_id, journal_id) references journals (tenant_id, journal_id)
            );
            create table reconciliations (
                id varchar(64) primary key,
                tenant_id varchar(64) not null,
                bank_account_id varchar(64) not null
            );
            create table reconciliation_reopenings (
                id varchar(64) primary key,
                tenant_id varchar(64) not null,
                reconciliation_id varchar(64) not null
            );
            SQL);
    }

    private function seedParents(): void
    {
        $connection = DB::connection('pgsql');
        $connection->table('bank_accounts')->insert([
            ['id' => 'bank-a', 'tenant_id' => 'tenant-a'],
            ['id' => 'bank-a-2', 'tenant_id' => 'tenant-a'],
            ['id' => 'bank-b', 'tenant_id' => 'tenant-b'],
        ]);
        $this->insertBatch('batch-a', 'tenant-a', 'bank-a');
        $this->insertBatch('batch-a-2', 'tenant-a', 'bank-a-2');
        $this->insertBatch('batch-b', 'tenant-b', 'bank-b');
        $connection->table('journals')->insert([
            ['journal_id' => 'journal-a', 'tenant_id' => 'tenant-a'],
            ['journal_id' => 'journal-b', 'tenant_id' => 'tenant-b'],
        ]);
    }

    private function insertBatch(string $id, string $tenantId, string $bankAccountId): void
    {
        DB::connection('pgsql')->table('bank_statement_import_batches')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'bank_account_id' => $bankAccountId,
        ]);
    }

    private function insertTransaction(string $id, string $tenantId, string $bankAccountId, string $batchId): void
    {
        DB::connection('pgsql')->table('bank_transactions')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'bank_account_id' => $bankAccountId,
            'import_batch_id' => $batchId,
        ]);
    }

    private function insertMatch(string $id, string $tenantId, string $bankTransactionId, string $journalId): void
    {
        DB::connection('pgsql')->table('matches')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'bank_transaction_id' => $bankTransactionId,
            'journal_id' => $journalId,
        ]);
    }

    private function insertReconciliation(string $id, string $tenantId, string $bankAccountId): void
    {
        DB::connection('pgsql')->table('reconciliations')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'bank_account_id' => $bankAccountId,
        ]);
    }

    private function insertReopening(string $id, string $tenantId, string $reconciliationId): void
    {
        DB::connection('pgsql')->table('reconciliation_reopenings')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'reconciliation_id' => $reconciliationId,
        ]);
    }
}
