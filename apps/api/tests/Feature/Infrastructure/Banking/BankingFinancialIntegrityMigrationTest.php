<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Banking;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Isolated real-PostgreSQL proof for Banking's exact-value and
 * Reconciliation-state persistence constraints.
 */
final class BankingFinancialIntegrityMigrationTest extends TestCase
{
    private const SCHEMA = 'banking_financial_integrity_migration_test';

    private const MIGRATION = 'database/migrations/2026_09_15_010000_harden_banking_financial_integrity.php';

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
            create table bank_statement_import_batches (
                id varchar(64) primary key,
                row_count integer not null,
                inserted_count integer not null,
                duplicate_count integer not null
            );
            create table bank_transactions (
                id varchar(64) primary key,
                amount bigint not null,
                currency varchar(8) not null
            );
            create table reconciliations (
                id varchar(64) primary key,
                currency varchar(8) not null,
                state varchar(16) not null,
                completed_at timestamp null
            );
            create table reconciliation_reopenings (
                id varchar(64) primary key,
                reason varchar(500) not null
            );
            SQL);

        $migration = require base_path(self::MIGRATION);
        if (! is_callable([$migration, 'up']) || ! is_callable([$migration, 'down'])) {
            throw new \LogicException('The Banking financial-integrity migration must expose up() and down().');
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

    public function test_valid_exact_banking_facts_are_accepted(): void
    {
        $this->insertBatch('batch-valid', 2, 1, 1);
        $this->insertTransaction('transaction-valid', 0, 'MYR');
        $this->insertReconciliation('reconciliation-draft', 'MYR', 'Draft', null);
        $this->insertReconciliation('reconciliation-completed', 'MYR', 'Completed', '2026-09-15 12:00:00');
        $this->insertReopening('reopening-valid', 'Required correction');

        $this->assertSame(2, DB::connection('pgsql')->table('reconciliations')->count());
    }

    public function test_import_counts_cannot_be_negative(): void
    {
        $this->expectException(QueryException::class);
        $this->insertBatch('batch-negative', -1, 0, -1);
    }

    public function test_import_counts_must_reconcile_exactly(): void
    {
        $this->expectException(QueryException::class);
        $this->insertBatch('batch-inconsistent', 3, 1, 1);
    }

    public function test_bank_transaction_amount_cannot_be_negative(): void
    {
        $this->expectException(QueryException::class);
        $this->insertTransaction('transaction-negative', -1, 'MYR');
    }

    public function test_bank_transaction_currency_must_be_myr(): void
    {
        $this->expectException(QueryException::class);
        $this->insertTransaction('transaction-wrong-currency', 100, 'USD');
    }

    public function test_reconciliation_currency_must_be_myr(): void
    {
        $this->expectException(QueryException::class);
        $this->insertReconciliation('reconciliation-wrong-currency', 'USD', 'Draft', null);
    }

    public function test_completed_reconciliation_requires_completed_at(): void
    {
        $this->expectException(QueryException::class);
        $this->insertReconciliation('reconciliation-missing-time', 'MYR', 'Completed', null);
    }

    public function test_noncompleted_reconciliation_cannot_carry_completed_at(): void
    {
        $this->expectException(QueryException::class);
        $this->insertReconciliation('reconciliation-stale-time', 'MYR', 'Balanced', '2026-09-15 12:00:00');
    }

    public function test_reopening_reason_cannot_be_blank(): void
    {
        $this->expectException(QueryException::class);
        $this->insertReopening('reopening-blank', '   ');
    }

    public function test_migration_reverses_and_reapplies_cleanly(): void
    {
        $this->migration?->down();
        $this->insertTransaction('transaction-invalid-before-reapply', -1, 'USD');
        $this->assertSame(1, DB::connection('pgsql')->table('bank_transactions')->count());

        DB::connection('pgsql')->table('bank_transactions')->delete();
        $this->migration?->up();

        $this->expectException(QueryException::class);
        $this->insertTransaction('transaction-invalid-after-reapply', -1, 'USD');
    }

    private function insertBatch(string $id, int $rowCount, int $insertedCount, int $duplicateCount): void
    {
        DB::connection('pgsql')->table('bank_statement_import_batches')->insert([
            'id' => $id,
            'row_count' => $rowCount,
            'inserted_count' => $insertedCount,
            'duplicate_count' => $duplicateCount,
        ]);
    }

    private function insertTransaction(string $id, int $amount, string $currency): void
    {
        DB::connection('pgsql')->table('bank_transactions')->insert([
            'id' => $id,
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    private function insertReconciliation(string $id, string $currency, string $state, ?string $completedAt): void
    {
        DB::connection('pgsql')->table('reconciliations')->insert([
            'id' => $id,
            'currency' => $currency,
            'state' => $state,
            'completed_at' => $completedAt,
        ]);
    }

    private function insertReopening(string $id, string $reason): void
    {
        DB::connection('pgsql')->table('reconciliation_reopenings')->insert([
            'id' => $id,
            'reason' => $reason,
        ]);
    }
}
