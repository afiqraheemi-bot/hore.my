<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Invoicing;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Customers\Customer;
use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceLine;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Customers\CustomerRepository;
use App\Infrastructure\Invoicing\InvoiceRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CleansSharedAccountingTables;
use Tests\TestCase;

/**
 * Real-PostgreSQL round-trip coverage for {@see InvoiceRepository}
 * (M20) — save/update/markIssued/delete/findById/findAllByTenant, and
 * the composite foreign keys onto `customers`/`accounts` a Draft
 * Invoice must respect.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class InvoiceRepositoryIntegrationTest extends TestCase
{
    use CleansSharedAccountingTables;

    private const ACCOUNT_TABLE = 'accounts';

    private const CUSTOMER_TABLE = 'customers';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private InvoiceRepository $repository;

    private TenantId $tenant;

    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        self::cleanSharedAccountingTables();
        DB::connection('pgsql')->table(self::CUSTOMER_TABLE)->delete();

        $connection = DB::connection('pgsql');
        $this->tenant = TenantId::of('tenant-0001');
        $this->myr = Currency::of('MYR');
        $this->repository = new InvoiceRepository($connection);

        $this->insertAccount('account-receivable', 'Asset');
        $this->insertAccount('account-revenue', 'Revenue');
        (new CustomerRepository($connection))->save(Customer::register(
            CustomerId::of('customer-0001'),
            $this->tenant,
            'Kedai Runcit Aminah',
            null,
            null,
            null,
            null,
            null,
        ));
    }

    public function test_saves_and_finds_a_draft_invoice_with_its_lines(): void
    {
        $invoice = $this->draftInvoice([
            InvoiceLine::of('Consulting', 2, Money::fromDecimalString('100.00', $this->myr)),
        ]);

        $this->repository->save($invoice);

        $found = $this->repository->findById($this->tenant, $invoice->id());

        $this->assertNotNull($found);
        $this->assertSame(InvoiceStatus::Draft, $found->status());
        $this->assertSame('200.00', $found->totalAmount()->toDecimalString());
        $this->assertCount(1, $found->lines());
        $this->assertSame('Consulting', $found->lines()[0]->description());
    }

    public function test_update_replaces_lines_wholesale(): void
    {
        $invoice = $this->draftInvoice([
            InvoiceLine::of('Item A', 1, Money::fromDecimalString('10.00', $this->myr)),
        ]);
        $this->repository->save($invoice);

        $updated = $invoice->update(
            $invoice->dueDate(),
            $invoice->receivableAccountId(),
            $invoice->revenueAccountId(),
            [InvoiceLine::of('Item B', 3, Money::fromDecimalString('20.00', $this->myr))],
        );
        $this->repository->update($updated);

        $found = $this->repository->findById($this->tenant, $invoice->id());

        $this->assertNotNull($found);
        $this->assertCount(1, $found->lines());
        $this->assertSame('Item B', $found->lines()[0]->description());
        $this->assertSame('60.00', $found->totalAmount()->toDecimalString());
    }

    public function test_delete_removes_the_invoice_and_its_lines(): void
    {
        $invoice = $this->draftInvoice([
            InvoiceLine::of('Item A', 1, Money::fromDecimalString('10.00', $this->myr)),
        ]);
        $this->repository->save($invoice);

        $this->repository->delete($this->tenant, $invoice->id());

        $this->assertNull($this->repository->findById($this->tenant, $invoice->id()));
        $this->assertSame(0, DB::connection('pgsql')->table('invoice_lines')->count());
    }

    public function test_find_all_by_tenant_respects_tenant_isolation(): void
    {
        $this->repository->save($this->draftInvoice([]));

        $found = $this->repository->findAllByTenant(TenantId::of('tenant-9999'));

        $this->assertSame([], $found);
    }

    public function test_cannot_reference_a_nonexistent_customer(): void
    {
        $invoice = Invoice::draft(
            InvoiceId::of('invoice-0001'),
            $this->tenant,
            CustomerId::of('customer-does-not-exist'),
            new \DateTimeImmutable('2026-12-31'),
            AccountId::of('account-receivable'),
            AccountId::of('account-revenue'),
            [],
            $this->myr,
        );

        $this->expectException(QueryException::class);

        $this->repository->save($invoice);
    }

    /**
     * @param  list<InvoiceLine>  $lines
     */
    private function draftInvoice(array $lines): Invoice
    {
        return Invoice::draft(
            InvoiceId::of('invoice-0001'),
            $this->tenant,
            CustomerId::of('customer-0001'),
            new \DateTimeImmutable('2026-12-31'),
            AccountId::of('account-receivable'),
            AccountId::of('account-revenue'),
            $lines,
            $this->myr,
        );
    }

    private function insertAccount(string $accountId, string $accountType): void
    {
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->insert([
            'tenant_id' => $this->tenant->toString(),
            'account_id' => $accountId,
            'account_code' => substr(md5($accountId), 0, 10),
            'account_name' => 'Test Account',
            'account_type' => $accountType,
            'account_origin' => 'UserCreated',
            'active' => true,
            'posting_eligible' => true,
            'parent_id' => null,
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

        $requiredTables = [self::ACCOUNT_TABLE, self::CUSTOMER_TABLE, 'invoices', 'invoice_lines', 'invoice_number_sequences'];
        $missingATable = false;

        foreach ($requiredTables as $table) {
            if (! Schema::connection('pgsql')->hasTable($table)) {
                $missingATable = true;

                break;
            }
        }

        if ($missingATable) {
            Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);
        }

        self::$migrated = true;
    }
}
