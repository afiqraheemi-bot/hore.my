<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Quotations;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Real-PostgreSQL proof for the Quotation table migrations (AETS-016).
 * Each test uses a dedicated schema so forward and reverse migration
 * behavior cannot disturb the shared application tables or
 * development data — mirrors
 * `Tests\Feature\Infrastructure\Workspace\WorkspaceTablesMigrationTest`
 * exactly.
 */
final class QuotationTablesMigrationTest extends TestCase
{
    private const SCHEMA = 'quotation_migration_test';

    /** @var list<\Closure(): void> */
    private array $rollbacks = [];

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
            'create table customers (tenant_id varchar(64) not null, id varchar(64) not null, primary key (id), unique (tenant_id, id))',
        );
        DB::connection('pgsql')->unprepared(
            'create table invoices (tenant_id varchar(64) not null, id varchar(64) not null, primary key (id), unique (tenant_id, id))',
        );

        foreach ([
            'database/migrations/2026_09_16_060000_create_quotation_number_sequences_table.php',
            'database/migrations/2026_09_16_070000_create_quotations_table.php',
            'database/migrations/2026_09_16_080000_create_quotation_lines_table.php',
        ] as $path) {
            $migration = require base_path($path);
            if (! is_callable([$migration, 'up']) || ! is_callable([$migration, 'down'])) {
                throw new \LogicException("Migration {$path} must expose up() and down().");
            }

            $up = \Closure::fromCallable([$migration, 'up']);
            $up();
            $this->rollbacks[] = \Closure::fromCallable([$migration, 'down']);
        }
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            DB::connection('pgsql')->unprepared('set search_path to public');
            DB::connection('pgsql')->unprepared('drop schema if exists '.self::SCHEMA.' cascade');
        }

        parent::tearDown();
    }

    public function test_quotation_migrations_apply_and_reverse_cleanly(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasColumns('quotations', [
            'tenant_id', 'id', 'customer_id', 'quotation_number', 'status', 'issue_date', 'valid_until', 'converted_invoice_id', 'total_amount',
        ]));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns('quotation_lines', [
            'tenant_id', 'quotation_id', 'line_number', 'description', 'quantity', 'unit_price', 'line_amount',
        ]));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns('quotation_number_sequences', ['tenant_id', 'next_number']));

        foreach (array_reverse($this->rollbacks) as $rollback) {
            $rollback();
        }

        $this->assertFalse(Schema::connection('pgsql')->hasTable('quotation_lines'));
        $this->assertFalse(Schema::connection('pgsql')->hasTable('quotations'));
        $this->assertFalse(Schema::connection('pgsql')->hasTable('quotation_number_sequences'));
    }

    public function test_quotation_rejects_a_noncanonical_status(): void
    {
        $this->seedCustomer();

        $this->expectException(QueryException::class);
        $this->insertQuotation('tenant-a', 'quotation-invalid', 'NotAStatus');
    }

    public function test_quotation_cannot_reference_another_tenants_customer(): void
    {
        $this->seedCustomer('tenant-b');

        $this->expectException(QueryException::class);
        $this->insertQuotation('tenant-a', 'quotation-invalid', 'Draft', customerId: 'customer-b');
    }

    public function test_quotation_cannot_reference_another_tenants_converted_invoice(): void
    {
        $this->seedCustomer('tenant-a');
        DB::connection('pgsql')->table('invoices')->insert(['tenant_id' => 'tenant-b', 'id' => 'invoice-b']);

        $this->expectException(QueryException::class);
        $this->insertQuotation('tenant-a', 'quotation-invalid', 'Converted', convertedInvoiceId: 'invoice-b');
    }

    public function test_quotation_line_cannot_reference_another_tenants_quotation(): void
    {
        $this->seedCustomer('tenant-a');
        $this->insertQuotation('tenant-a', 'quotation-a', 'Draft');

        $this->expectException(QueryException::class);
        DB::connection('pgsql')->table('quotation_lines')->insert([
            'id' => 'line-invalid',
            'tenant_id' => 'tenant-b',
            'quotation_id' => 'quotation-a',
            'line_number' => 1,
            'description' => 'Migration constraint proof',
            'quantity' => 1,
            'unit_price' => 100,
            'line_amount' => 100,
        ]);
    }

    private function seedCustomer(string $tenantId = 'tenant-a'): void
    {
        DB::connection('pgsql')->table('customers')->insert([
            'tenant_id' => $tenantId,
            'id' => $tenantId === 'tenant-a' ? 'customer-a' : 'customer-b',
        ]);
    }

    private function insertQuotation(
        string $tenantId,
        string $quotationId,
        string $status,
        string $customerId = 'customer-a',
        ?string $convertedInvoiceId = null,
    ): void {
        DB::connection('pgsql')->table('quotations')->insert([
            'id' => $quotationId,
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'status' => $status,
            'valid_until' => '2026-12-31',
            'converted_invoice_id' => $convertedInvoiceId,
            'total_amount' => 0,
            'currency' => 'MYR',
        ]);
    }
}
