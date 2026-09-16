<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Quotations;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Customers\Customer;
use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\Exception\InvalidReceivableAccountTypeException;
use App\Domain\Invoicing\Exception\InvalidRevenueAccountTypeException;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Quotations\Exception\InvalidQuotationStatusTransitionException;
use App\Domain\Quotations\Exception\QuotationNotFoundException;
use App\Domain\Quotations\Quotation;
use App\Domain\Quotations\QuotationConversionService;
use App\Domain\Quotations\QuotationId;
use App\Domain\Quotations\QuotationLine;
use App\Domain\Quotations\QuotationStatus;
use App\Domain\Shared\Tenancy\TenantId;
use App\Http\Controllers\Api\QuotationController;
use App\Infrastructure\Customers\CustomerRepository;
use App\Infrastructure\Invoicing\InvoiceRepository;
use App\Infrastructure\Quotations\QuotationRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CleansSharedAccountingTables;
use Tests\TestCase;

/**
 * Integration-level proof for {@see QuotationConversionService}
 * (AETS-016 §5) — exercised against a real PostgreSQL instance, with
 * the real {@see QuotationRepository}/{@see InvoiceRepository}
 * resolved through the application container exactly as
 * {@see QuotationController} resolves them
 * in production.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class QuotationConversionServiceIntegrationTest extends TestCase
{
    use CleansSharedAccountingTables;

    private const ACCOUNT_TABLE = 'accounts';

    private const CUSTOMER_TABLE = 'customers';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private QuotationConversionService $conversionService;

    private QuotationRepository $quotationRepository;

    private InvoiceRepository $invoiceRepository;

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
        $this->quotationRepository = $this->app->make(QuotationRepository::class);
        $this->invoiceRepository = $this->app->make(InvoiceRepository::class);
        $this->conversionService = $this->app->make(QuotationConversionService::class);

        $this->insertAccount('account-receivable', 'Asset');
        $this->insertAccount('account-revenue', 'Revenue');
        $this->insertAccount('account-wrong-type', 'Expense');
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

    public function test_converts_an_accepted_quotation_into_a_draft_invoice_with_matching_lines(): void
    {
        $accepted = $this->acceptedQuotation([
            QuotationLine::of('Consulting', 2, Money::fromDecimalString('150.00', $this->myr)),
        ]);

        $invoice = $this->convert($accepted);

        $this->assertSame(InvoiceStatus::Draft, $invoice->status());
        $this->assertSame('customer-0001', $invoice->customerId()->toString());
        $this->assertCount(1, $invoice->lines());
        $this->assertSame('Consulting', $invoice->lines()[0]->description());
        $this->assertSame('300.00', $invoice->totalAmount()->toDecimalString());
        $this->assertNull($invoice->journalId(), 'conversion must never touch the ledger');

        $reloadedQuotation = $this->quotationRepository->findById($this->tenant, $accepted->id());
        $this->assertNotNull($reloadedQuotation);
        $this->assertSame(QuotationStatus::Converted, $reloadedQuotation->status());
        $this->assertNotNull($reloadedQuotation->convertedInvoiceId());
        $this->assertTrue($reloadedQuotation->convertedInvoiceId()->equals($invoice->id()));
    }

    public function test_converting_a_nonexistent_quotation_is_rejected(): void
    {
        $this->expectException(QuotationNotFoundException::class);

        $this->conversionService->convert(
            $this->tenant,
            QuotationId::of('quotation-does-not-exist'),
            InvoiceId::of((string) Str::uuid()),
            AccountId::of('account-receivable'),
            AccountId::of('account-revenue'),
            new \DateTimeImmutable('2026-12-31'),
        );
    }

    public function test_converting_a_sent_but_not_yet_accepted_quotation_is_rejected(): void
    {
        $sent = $this->sentQuotation([
            QuotationLine::of('Item', 1, Money::fromDecimalString('10.00', $this->myr)),
        ]);

        $this->expectException(InvalidQuotationStatusTransitionException::class);

        $this->convert($sent);
    }

    public function test_converting_with_a_non_asset_receivable_account_is_rejected(): void
    {
        $accepted = $this->acceptedQuotation([
            QuotationLine::of('Item', 1, Money::fromDecimalString('10.00', $this->myr)),
        ]);

        $this->expectException(InvalidReceivableAccountTypeException::class);

        $this->conversionService->convert(
            $this->tenant,
            $accepted->id(),
            InvoiceId::of((string) Str::uuid()),
            AccountId::of('account-wrong-type'),
            AccountId::of('account-revenue'),
            new \DateTimeImmutable('2026-12-31'),
        );
    }

    public function test_converting_with_a_non_revenue_account_is_rejected(): void
    {
        $accepted = $this->acceptedQuotation([
            QuotationLine::of('Item', 1, Money::fromDecimalString('10.00', $this->myr)),
        ]);

        $this->expectException(InvalidRevenueAccountTypeException::class);

        $this->conversionService->convert(
            $this->tenant,
            $accepted->id(),
            InvoiceId::of((string) Str::uuid()),
            AccountId::of('account-receivable'),
            AccountId::of('account-wrong-type'),
            new \DateTimeImmutable('2026-12-31'),
        );
    }

    public function test_converting_with_an_unresolved_account_reference_leaves_the_quotation_accepted(): void
    {
        $accepted = $this->acceptedQuotation([
            QuotationLine::of('Item', 1, Money::fromDecimalString('10.00', $this->myr)),
        ]);

        try {
            $this->conversionService->convert(
                $this->tenant,
                $accepted->id(),
                InvoiceId::of((string) Str::uuid()),
                AccountId::of('account-does-not-exist'),
                AccountId::of('account-revenue'),
                new \DateTimeImmutable('2026-12-31'),
            );
            $this->fail('Expected an unresolved Account reference to be rejected.');
        } catch (RejectedAccountReferenceException) {
            // Expected.
        }

        $reloaded = $this->quotationRepository->findById($this->tenant, $accepted->id());
        $this->assertNotNull($reloaded);
        $this->assertSame(QuotationStatus::Accepted, $reloaded->status());
        $this->assertSame(0, DB::connection('pgsql')->table('invoices')->count());
    }

    /**
     * QUO-008: two concurrent conversion attempts against the same
     * `Accepted` Quotation must never both succeed — mirrors
     * `InvoiceIssuingServiceIntegrationTest::test_two_concurrent_issue_attempts_never_post_two_journals()`'s
     * own synchronization technique exactly.
     */
    public function test_two_concurrent_conversion_attempts_never_both_succeed(): void
    {
        $accepted = $this->acceptedQuotation([
            QuotationLine::of('Item', 1, Money::fromDecimalString('10.00', $this->myr)),
        ]);

        $tmp = sys_get_temp_dir();
        $readyA = tempnam($tmp, 'ready_a_');
        $readyB = tempnam($tmp, 'ready_b_');
        $goFile = tempnam($tmp, 'go_');
        $resultA = tempnam($tmp, 'result_a_');
        $resultB = tempnam($tmp, 'result_b_');
        unlink($readyA);
        unlink($readyB);
        unlink($goFile);

        $workerScript = base_path('tests/bin/concurrent_quotation_convert_worker.php');
        $invoiceIdA = (string) Str::uuid();
        $invoiceIdB = (string) Str::uuid();

        $processA = proc_open(
            ['php', $workerScript, $this->tenant->toString(), $accepted->id()->toString(), 'account-receivable', 'account-revenue', $invoiceIdA, $readyA, $goFile, $resultA],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesA,
        );
        $processB = proc_open(
            ['php', $workerScript, $this->tenant->toString(), $accepted->id()->toString(), 'account-receivable', 'account-revenue', $invoiceIdB, $readyB, $goFile, $resultB],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesB,
        );

        $this->assertIsResource($processA);
        $this->assertIsResource($processB);

        $deadline = microtime(true) + 5.0;
        while (! (file_exists($readyA) && file_exists($readyB))) {
            if (microtime(true) > $deadline) {
                $this->fail('Timed out waiting for both worker processes to signal ready.');
            }
            usleep(2000);
        }

        touch($goFile);

        foreach ([$processA, $processB] as $process) {
            proc_close($process);
        }

        $deadline = microtime(true) + 5.0;
        while (! (file_exists($resultA) && file_exists($resultB))) {
            if (microtime(true) > $deadline) {
                $this->fail('Timed out waiting for both worker results.');
            }
            usleep(2000);
        }

        $resultAData = json_decode((string) file_get_contents($resultA), true);
        $resultBData = json_decode((string) file_get_contents($resultB), true);

        foreach ([$readyA, $readyB, $goFile, $resultA, $resultB] as $file) {
            @unlink($file);
        }

        $outcomes = ['A' => $resultAData, 'B' => $resultBData];
        $succeeded = array_keys(array_filter($outcomes, static fn (array $r): bool => array_key_exists('invoice_id', $r)));
        $rejected = array_keys(array_filter($outcomes, static fn (array $r): bool => ($r['exception'] ?? null) === InvalidQuotationStatusTransitionException::class));

        $this->assertCount(1, $succeeded, 'Exactly one of the two concurrent conversion attempts must succeed. Got: '.json_encode($outcomes));
        $this->assertCount(1, $rejected, 'The other concurrent attempt must be safely rejected. Got: '.json_encode($outcomes));
        $this->assertSame(1, DB::connection('pgsql')->table('invoices')->where('tenant_id', $this->tenant->toString())->count());
    }

    private function convert(Quotation $quotation): Invoice
    {
        return $this->conversionService->convert(
            $this->tenant,
            $quotation->id(),
            InvoiceId::of((string) Str::uuid()),
            AccountId::of('account-receivable'),
            AccountId::of('account-revenue'),
            new \DateTimeImmutable('2026-12-31'),
        );
    }

    /**
     * @param  list<QuotationLine>  $lines
     */
    private function acceptedQuotation(array $lines): Quotation
    {
        $accepted = $this->sentQuotation($lines)->accept();
        $this->quotationRepository->updateStatus($accepted);

        return $accepted;
    }

    /**
     * @param  list<QuotationLine>  $lines
     */
    private function sentQuotation(array $lines): Quotation
    {
        $draft = Quotation::draft(
            QuotationId::of((string) Str::uuid()),
            $this->tenant,
            CustomerId::of('customer-0001'),
            new \DateTimeImmutable('2026-12-31'),
            $lines,
            $this->myr,
        );
        $this->quotationRepository->save($draft);

        $sent = $draft->send('QUO-'.substr($draft->id()->toString(), 0, 6), new \DateTimeImmutable('2026-09-16'));
        $this->quotationRepository->markSent($sent);

        return $sent;
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

        $requiredTables = [
            self::ACCOUNT_TABLE, self::CUSTOMER_TABLE, 'quotations', 'quotation_lines',
            'quotation_number_sequences', 'invoices', 'invoice_lines',
        ];
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
