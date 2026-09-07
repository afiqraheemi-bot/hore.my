<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Customers;

use App\Domain\Customers\Customer;
use App\Domain\Customers\CustomerId;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Customers\CustomerRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Real-PostgreSQL round-trip coverage for {@see CustomerRepository}
 * (M19) — save, update, findById, findAllByTenant, and the
 * tenant-isolation each of those must respect.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class CustomerRepositoryIntegrationTest extends TestCase
{
    private const TABLE = 'customers';

    private const MIGRATION_PATH = 'database/migrations/2026_09_08_000000_create_customers_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private CustomerRepository $repository;

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

        $this->repository = new CustomerRepository(DB::connection('pgsql'));
    }

    public function test_saves_and_finds_a_customer_by_id(): void
    {
        $customer = Customer::register(
            CustomerId::of('customer-0001'),
            TenantId::of('tenant-0001'),
            'Kedai Runcit Aminah',
            'aminah@example.com',
            '0123456789',
            'No. 1, Jalan Contoh',
            'C1234567890',
            'Pelanggan tetap',
        );

        $this->repository->save($customer);

        $found = $this->repository->findById(TenantId::of('tenant-0001'), CustomerId::of('customer-0001'));

        $this->assertNotNull($found);
        $this->assertSame('Kedai Runcit Aminah', $found->name());
        $this->assertSame('aminah@example.com', $found->email());
        $this->assertTrue($found->isActive());
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        $found = $this->repository->findById(TenantId::of('tenant-0001'), CustomerId::of('does-not-exist'));

        $this->assertNull($found);
    }

    public function test_find_by_id_respects_tenant_isolation(): void
    {
        $this->repository->save(Customer::register(
            CustomerId::of('customer-0001'),
            TenantId::of('tenant-0001'),
            'Kedai Runcit Aminah',
            null,
            null,
            null,
            null,
            null,
        ));

        $found = $this->repository->findById(TenantId::of('tenant-0002'), CustomerId::of('customer-0001'));

        $this->assertNull($found);
    }

    public function test_update_persists_changed_fields(): void
    {
        $customer = Customer::register(
            CustomerId::of('customer-0001'),
            TenantId::of('tenant-0001'),
            'Kedai Runcit Aminah',
            'aminah@example.com',
            null,
            null,
            null,
            null,
        );
        $this->repository->save($customer);

        $updated = $customer->update('Kedai Runcit Aminah Sdn Bhd', 'new@example.com', null, null, null, null)->deactivate();
        $this->repository->update($updated);

        $found = $this->repository->findById(TenantId::of('tenant-0001'), CustomerId::of('customer-0001'));

        $this->assertNotNull($found);
        $this->assertSame('Kedai Runcit Aminah Sdn Bhd', $found->name());
        $this->assertSame('new@example.com', $found->email());
        $this->assertFalse($found->isActive());
    }

    public function test_find_all_by_tenant_returns_only_that_tenants_customers_ordered_by_name(): void
    {
        $this->repository->save(Customer::register(CustomerId::of('customer-0001'), TenantId::of('tenant-0001'), 'Zeta Enterprise', null, null, null, null, null));
        $this->repository->save(Customer::register(CustomerId::of('customer-0002'), TenantId::of('tenant-0001'), 'Alpha Trading', null, null, null, null, null));
        $this->repository->save(Customer::register(CustomerId::of('customer-0003'), TenantId::of('tenant-0002'), 'Other Tenant Co', null, null, null, null, null));

        $results = $this->repository->findAllByTenant(TenantId::of('tenant-0001'));

        $this->assertCount(2, $results);
        $this->assertSame('Alpha Trading', $results[0]->name());
        $this->assertSame('Zeta Enterprise', $results[1]->name());
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

        if (! Schema::connection('pgsql')->hasTable(self::TABLE)) {
            Artisan::call('migrate', ['--database' => 'pgsql', '--path' => self::MIGRATION_PATH, '--realpath' => false, '--force' => true]);
        }

        self::$migrated = true;
    }
}
