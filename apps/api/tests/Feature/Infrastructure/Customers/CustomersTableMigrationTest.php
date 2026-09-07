<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Customers;

use App\Infrastructure\Customers\CustomerRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration-level proof for the production `customers` table
 * migration (M19), exercised against a real PostgreSQL instance —
 * schema-only coverage proving the primary key
 * {@see CustomerRepository} relies on.
 *
 * Unlike `bank_accounts`, `customers` carries no foreign key onto any
 * other table — it is fully independent reference data, so this test
 * needs no cross-table setup/teardown dance.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class CustomersTableMigrationTest extends TestCase
{
    private const TABLE = 'customers';

    private const MIGRATION_PATH = 'database/migrations/2026_09_08_000000_create_customers_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        if (Schema::connection('pgsql')->hasTable('payments')) {
            DB::connection('pgsql')->table('payment_allocations')->delete();
            DB::connection('pgsql')->table('payments')->delete();
        }
        if (Schema::connection('pgsql')->hasTable('invoices')) {
            DB::connection('pgsql')->table('invoice_lines')->delete();
            DB::connection('pgsql')->table('invoices')->delete();
        }
        DB::connection('pgsql')->table(self::TABLE)->delete();
    }

    public function test_table_creates_successfully(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns(self::TABLE, [
            'id', 'tenant_id', 'name', 'email', 'phone', 'address',
            'tax_identification_number', 'notes', 'active', 'created_at', 'updated_at',
        ]));
    }

    public function test_valid_customer_inserts(): void
    {
        $this->insertCustomer('customer-0001', 'tenant-0001');

        $this->assertSame(1, DB::connection('pgsql')->table(self::TABLE)->count());
    }

    public function test_id_is_the_primary_key(): void
    {
        $this->insertCustomer('customer-0001', 'tenant-0001');

        $this->expectException(QueryException::class);

        $this->insertCustomer('customer-0001', 'tenant-0001');
    }

    public function test_optional_fields_may_be_null(): void
    {
        DB::connection('pgsql')->table(self::TABLE)->insert([
            'id' => 'customer-0001',
            'tenant_id' => 'tenant-0001',
            'name' => 'Kedai Runcit Aminah',
            'email' => null,
            'phone' => null,
            'address' => null,
            'tax_identification_number' => null,
            'notes' => null,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::connection('pgsql')->table(self::TABLE)->count());
    }

    public function test_migration_rollback_succeeds_cleanly(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));

        Artisan::call('migrate:rollback', ['--database' => 'pgsql', '--path' => self::MIGRATION_PATH, '--realpath' => false, '--force' => true]);
        $this->assertFalse(Schema::connection('pgsql')->hasTable(self::TABLE));

        Artisan::call('migrate', ['--database' => 'pgsql', '--path' => self::MIGRATION_PATH, '--realpath' => false, '--force' => true]);
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));
    }

    private function insertCustomer(string $id, string $tenantId): void
    {
        DB::connection('pgsql')->table(self::TABLE)->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'name' => 'Kedai Runcit Aminah',
            'email' => 'aminah@example.com',
            'phone' => '0123456789',
            'address' => 'No. 1, Jalan Contoh',
            'tax_identification_number' => 'C1234567890',
            'notes' => 'Pelanggan tetap',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureMigrated(): void
    {
        if (self::$skipReason !== null || self::$migrated) {
            return;
        }

        try {
            DB::connection('pgsql')->select('select 1');
        } catch (\Throwable $e) {
            self::$skipReason = sprintf(
                'A real PostgreSQL instance is not reachable via the "pgsql" connection (%s). '
                .'Run `docker compose up -d postgres` (see docker-compose.yml) to enable this integration test.',
                $e->getMessage(),
            );

            return;
        }

        Schema::connection('pgsql')->dropIfExists('payment_allocations');
        Schema::connection('pgsql')->dropIfExists('payments');
        Schema::connection('pgsql')->dropIfExists('invoice_lines');
        Schema::connection('pgsql')->dropIfExists('invoices');
        Schema::connection('pgsql')->dropIfExists(self::TABLE);

        if (Schema::connection('pgsql')->hasTable('migrations')) {
            DB::connection('pgsql')->table('migrations')
                ->where('migration', pathinfo(self::MIGRATION_PATH, PATHINFO_FILENAME))
                ->delete();
        }

        Artisan::call('migrate', ['--database' => 'pgsql', '--path' => self::MIGRATION_PATH, '--realpath' => false, '--force' => true]);

        self::$migrated = true;
    }
}
