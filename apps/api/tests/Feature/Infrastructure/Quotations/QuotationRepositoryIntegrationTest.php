<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Quotations;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Customers\Customer;
use App\Domain\Customers\CustomerId;
use App\Domain\Quotations\Quotation;
use App\Domain\Quotations\QuotationId;
use App\Domain\Quotations\QuotationLine;
use App\Domain\Quotations\QuotationStatus;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Customers\CustomerRepository;
use App\Infrastructure\Quotations\QuotationRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CleansSharedAccountingTables;
use Tests\TestCase;

/**
 * Real-PostgreSQL round-trip coverage for {@see QuotationRepository}
 * (AETS-016) — save/update/markSent/updateStatus/delete/findById/
 * findAllByTenant, and the composite foreign key onto `customers` a
 * Draft Quotation must respect. Mirrors
 * `Tests\Feature\Infrastructure\Invoicing\InvoiceRepositoryIntegrationTest`
 * exactly wherever the two concepts are structurally parallel.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class QuotationRepositoryIntegrationTest extends TestCase
{
    use CleansSharedAccountingTables;

    private const CUSTOMER_TABLE = 'customers';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private QuotationRepository $repository;

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
        $this->repository = new QuotationRepository($connection);

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

    public function test_saves_and_finds_a_draft_quotation_with_its_lines(): void
    {
        $quotation = $this->draftQuotation([
            QuotationLine::of('Consulting', 2, Money::fromDecimalString('100.00', $this->myr)),
        ]);

        $this->repository->save($quotation);

        $found = $this->repository->findById($this->tenant, $quotation->id());

        $this->assertNotNull($found);
        $this->assertSame(QuotationStatus::Draft, $found->status());
        $this->assertSame('200.00', $found->totalAmount()->toDecimalString());
        $this->assertCount(1, $found->lines());
        $this->assertSame('Consulting', $found->lines()[0]->description());
    }

    public function test_update_replaces_lines_wholesale(): void
    {
        $quotation = $this->draftQuotation([
            QuotationLine::of('Item A', 1, Money::fromDecimalString('10.00', $this->myr)),
        ]);
        $this->repository->save($quotation);

        $updated = $quotation->update(
            $quotation->customerId(),
            $quotation->validUntil(),
            [QuotationLine::of('Item B', 3, Money::fromDecimalString('20.00', $this->myr))],
        );
        $this->repository->update($updated);

        $found = $this->repository->findById($this->tenant, $quotation->id());

        $this->assertNotNull($found);
        $this->assertCount(1, $found->lines());
        $this->assertSame('Item B', $found->lines()[0]->description());
        $this->assertSame('60.00', $found->totalAmount()->toDecimalString());
    }

    public function test_mark_sent_assigns_the_number_and_status(): void
    {
        $quotation = $this->draftQuotation([
            QuotationLine::of('Item A', 1, Money::fromDecimalString('10.00', $this->myr)),
        ]);
        $this->repository->save($quotation);

        $sent = $quotation->send('QUO-000001', new \DateTimeImmutable('2026-09-16'));
        $this->repository->markSent($sent);

        $found = $this->repository->findById($this->tenant, $quotation->id());
        $this->assertNotNull($found);
        $this->assertSame(QuotationStatus::Sent, $found->status());
        $this->assertSame('QUO-000001', $found->quotationNumber());
    }

    public function test_update_status_persists_accept_reject_and_convert(): void
    {
        $quotation = $this->draftQuotation([
            QuotationLine::of('Item A', 1, Money::fromDecimalString('10.00', $this->myr)),
        ]);
        $this->repository->save($quotation);
        $sent = $quotation->send('QUO-000001', new \DateTimeImmutable('2026-09-16'));
        $this->repository->markSent($sent);

        $accepted = $sent->accept();
        $this->repository->updateStatus($accepted);

        $found = $this->repository->findById($this->tenant, $quotation->id());
        $this->assertNotNull($found);
        $this->assertSame(QuotationStatus::Accepted, $found->status());
    }

    public function test_delete_removes_the_quotation_and_its_lines(): void
    {
        $quotation = $this->draftQuotation([
            QuotationLine::of('Item A', 1, Money::fromDecimalString('10.00', $this->myr)),
        ]);
        $this->repository->save($quotation);

        $this->repository->delete($this->tenant, $quotation->id());

        $this->assertNull($this->repository->findById($this->tenant, $quotation->id()));
        $this->assertSame(0, DB::connection('pgsql')->table('quotation_lines')->count());
    }

    public function test_find_all_by_tenant_respects_tenant_isolation(): void
    {
        $this->repository->save($this->draftQuotation([]));

        $found = $this->repository->findAllByTenant(TenantId::of('tenant-9999'));

        $this->assertSame([], $found);
    }

    public function test_cannot_reference_a_nonexistent_customer(): void
    {
        $quotation = Quotation::draft(
            QuotationId::of('quotation-0001'),
            $this->tenant,
            CustomerId::of('customer-does-not-exist'),
            new \DateTimeImmutable('2026-12-31'),
            [],
            $this->myr,
        );

        $this->expectException(QueryException::class);

        $this->repository->save($quotation);
    }

    /**
     * @param  list<QuotationLine>  $lines
     */
    private function draftQuotation(array $lines): Quotation
    {
        return Quotation::draft(
            QuotationId::of('quotation-0001'),
            $this->tenant,
            CustomerId::of('customer-0001'),
            new \DateTimeImmutable('2026-12-31'),
            $lines,
            $this->myr,
        );
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

        $requiredTables = [self::CUSTOMER_TABLE, 'quotations', 'quotation_lines', 'quotation_number_sequences'];
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
