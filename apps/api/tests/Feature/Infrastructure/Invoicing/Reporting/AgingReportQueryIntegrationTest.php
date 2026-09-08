<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Invoicing\Reporting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\DraftJournalAssembler;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;
use App\Domain\Accounting\Posting\PostingCommandExistingDraftLineValidator;
use App\Domain\Accounting\Posting\PostingCommandIdempotencyResolver;
use App\Domain\Accounting\Posting\PostingCommandJournalExecutor;
use App\Domain\Accounting\Posting\PostingCommandJournalStateResolver;
use App\Domain\Accounting\Posting\PostingCommandLogicalEquivalence;
use App\Domain\Accounting\Posting\PostingCommandPeriodLockValidator;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Customers\Customer;
use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceIssuingService;
use App\Domain\Invoicing\InvoiceLine;
use App\Domain\Invoicing\InvoiceToPostingCommandTranslator;
use App\Domain\Invoicing\Reporting\AgingBucket;
use App\Domain\Payments\AllocationService;
use App\Domain\Payments\Payment;
use App\Domain\Payments\PaymentAccountTypeValidator;
use App\Domain\Payments\PaymentId;
use App\Domain\Payments\PaymentRecordingService;
use App\Domain\Payments\PaymentToPostingCommandTranslator;
use App\Domain\Payments\RecordPaymentCommand;
use App\Domain\Shared\Tenancy\TenantId;
use App\Http\Support\DeterministicIdempotentId;
use App\Infrastructure\Accounting\Audit\AuditEventRepository;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use App\Infrastructure\Accounting\Period\PeriodClosureRepository;
use App\Infrastructure\Accounting\Posting\JournalEvidenceLinkRepository;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;
use App\Infrastructure\Customers\CustomerRepository;
use App\Infrastructure\Invoicing\InvoiceNumberGenerator;
use App\Infrastructure\Invoicing\InvoiceRepository;
use App\Infrastructure\Invoicing\Reporting\AgingReportQuery;
use App\Infrastructure\Payments\PaymentAllocationRepository;
use App\Infrastructure\Payments\PaymentRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CleansSharedAccountingTables;
use Tests\TestCase;

/**
 * Integration-level proof for {@see AgingReportQuery} (M22) —
 * exercised against a real PostgreSQL instance with genuinely-Issued
 * Invoices, genuinely-recorded Payments, and genuine allocations.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class AgingReportQueryIntegrationTest extends TestCase
{
    use CleansSharedAccountingTables;

    private const ACCOUNT_TABLE = 'accounts';

    private const CUSTOMER_TABLE = 'customers';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private AgingReportQuery $query;

    private AllocationService $allocationService;

    private PaymentRecordingService $paymentService;

    private InvoiceIssuingService $invoiceIssuingService;

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
        DB::connection('pgsql')->table('invoice_number_sequences')->delete();

        $connection = DB::connection('pgsql');
        $this->tenant = TenantId::of('tenant-0001');
        $this->myr = Currency::of('MYR');
        $this->invoiceRepository = new InvoiceRepository($connection);
        $this->query = new AgingReportQuery($connection);

        $accountRepository = new AccountRepository($connection);
        $postingExecutor = $this->buildPostingExecutor($connection);

        $this->paymentService = new PaymentRecordingService(
            $connection,
            new PaymentAccountTypeValidator($accountRepository),
            new PaymentToPostingCommandTranslator,
            $postingExecutor,
            new PaymentRepository($connection),
        );

        $this->invoiceIssuingService = new InvoiceIssuingService(
            $connection,
            $this->invoiceRepository,
            new InvoiceNumberGenerator($connection),
            new InvoiceToPostingCommandTranslator,
            $postingExecutor,
        );

        $this->allocationService = new AllocationService(
            $connection,
            new PaymentRepository($connection),
            $this->invoiceRepository,
            new PaymentAllocationRepository($connection),
        );

        $this->insertAccount('account-bank', 'Asset');
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

    public function test_a_fully_paid_invoice_does_not_appear(): void
    {
        $invoice = $this->issuedInvoice('100.00', '2026-08-01', 'invoice-paid');
        $payment = $this->recordedPayment('100.00');
        $this->allocationService->allocate($this->tenant, $payment->id(), $invoice->id(), Money::fromDecimalString('100.00', $this->myr));

        $report = $this->query->asOf($this->tenant, new \DateTimeImmutable('2026-09-08'));

        $this->assertSame([], $report->lines());
    }

    /**
     * Regression proof for P1-3's `whereNull('deleted_at')` addition
     * to {@see AgingReportQuery}: a *deallocated* allocation must
     * still stop counting toward the paid-down amount — mirrors
     * {@see test_a_fully_paid_invoice_does_not_appear()} exactly, then
     * deallocates and asserts the Invoice reappears as fully
     * outstanding, exactly as it would have under the old hard-delete
     * (this proves the soft-delete migration did not silently change
     * this query's observable behavior).
     */
    public function test_a_deallocated_invoice_reappears_as_fully_outstanding(): void
    {
        $invoice = $this->issuedInvoice('100.00', '2026-08-01', 'invoice-deallocated');
        $payment = $this->recordedPayment('100.00');
        $allocation = $this->allocationService->allocate($this->tenant, $payment->id(), $invoice->id(), Money::fromDecimalString('100.00', $this->myr));

        $reportWhileAllocated = $this->query->asOf($this->tenant, new \DateTimeImmutable('2026-09-08'));
        $this->assertSame([], $reportWhileAllocated->lines());

        $this->allocationService->deallocate($this->tenant, $allocation->id(), ActorReference::of('user-0001'));

        $reportAfterDeallocation = $this->query->asOf($this->tenant, new \DateTimeImmutable('2026-09-08'));
        $this->assertCount(1, $reportAfterDeallocation->lines());
        $this->assertSame('100.00', $reportAfterDeallocation->lines()[0]->outstandingBalance()->toDecimalString());
    }

    public function test_an_unpaid_invoice_not_yet_due_is_current(): void
    {
        $this->issuedInvoice('100.00', '2026-12-31', 'invoice-future');

        $report = $this->query->asOf($this->tenant, new \DateTimeImmutable('2026-09-08'));

        $this->assertCount(1, $report->lines());
        $this->assertSame(AgingBucket::Current, $report->lines()[0]->bucket());
        $this->assertSame('100.00', $report->lines()[0]->outstandingBalance()->toDecimalString());
    }

    public function test_an_invoice_overdue_by_15_days_falls_in_the_first_bucket(): void
    {
        // asOfDate 2026-09-08, dueDate 2026-08-24 => 15 days overdue
        $this->issuedInvoice('200.00', '2026-08-24', 'invoice-15-days');

        $report = $this->query->asOf($this->tenant, new \DateTimeImmutable('2026-09-08'));

        $this->assertCount(1, $report->lines());
        $this->assertSame(AgingBucket::Overdue1To30, $report->lines()[0]->bucket());
    }

    public function test_an_invoice_overdue_by_100_days_falls_in_the_final_bucket(): void
    {
        // asOfDate 2026-09-08, dueDate 2026-05-31 => 100 days overdue
        $this->issuedInvoice('300.00', '2026-05-31', 'invoice-100-days');

        $report = $this->query->asOf($this->tenant, new \DateTimeImmutable('2026-09-08'));

        $this->assertCount(1, $report->lines());
        $this->assertSame(AgingBucket::Overdue91Plus, $report->lines()[0]->bucket());
    }

    public function test_a_partially_allocated_invoice_shows_the_remaining_outstanding_balance(): void
    {
        $invoice = $this->issuedInvoice('300.00', '2026-08-01', 'invoice-partial');
        $payment = $this->recordedPayment('100.00');
        $this->allocationService->allocate($this->tenant, $payment->id(), $invoice->id(), Money::fromDecimalString('100.00', $this->myr));

        $report = $this->query->asOf($this->tenant, new \DateTimeImmutable('2026-09-08'));

        $this->assertCount(1, $report->lines());
        $this->assertSame('200.00', $report->lines()[0]->outstandingBalance()->toDecimalString());
    }

    public function test_a_draft_invoice_never_appears(): void
    {
        $draft = Invoice::draft(
            InvoiceId::of('invoice-draft'),
            $this->tenant,
            CustomerId::of('customer-0001'),
            new \DateTimeImmutable('2026-08-01'),
            AccountId::of('account-receivable'),
            AccountId::of('account-revenue'),
            [InvoiceLine::of('Item', 1, Money::fromDecimalString('100.00', $this->myr))],
            $this->myr,
        );
        $this->invoiceRepository->save($draft);

        $report = $this->query->asOf($this->tenant, new \DateTimeImmutable('2026-09-08'));

        $this->assertSame([], $report->lines());
    }

    public function test_grand_total_sums_all_outstanding_invoices(): void
    {
        $this->issuedInvoice('100.00', '2026-12-31', 'invoice-a');
        $this->issuedInvoice('50.00', '2026-08-01', 'invoice-b');

        $report = $this->query->asOf($this->tenant, new \DateTimeImmutable('2026-09-08'));

        $this->assertSame('150.00', $report->grandTotal()->toDecimalString());
    }

    private function issuedInvoice(string $totalAmount, string $dueDate, string $invoiceId): Invoice
    {
        $draft = Invoice::draft(
            InvoiceId::of($invoiceId),
            $this->tenant,
            CustomerId::of('customer-0001'),
            new \DateTimeImmutable($dueDate),
            AccountId::of('account-receivable'),
            AccountId::of('account-revenue'),
            [InvoiceLine::of('Item', 1, Money::fromDecimalString($totalAmount, $this->myr))],
            $this->myr,
        );
        $this->invoiceRepository->save($draft);

        $idempotencyKey = IdempotencyKey::of('idem-key-issue-'.$invoiceId);
        $journalId = JournalId::of(DeterministicIdempotentId::derive($this->tenant, $idempotencyKey, 'journal'));

        // Issued well before every due date used across this test
        // class's own scenarios (some deliberately far in the past, to
        // exercise the 91+ day bucket) — Invoice::issue() itself
        // rejects a due date before the issue date (M20).
        $result = $this->invoiceIssuingService->issue($this->tenant, $draft->id(), $journalId, $idempotencyKey, ActorReference::of('user-0001'), new \DateTimeImmutable('2026-01-01'));

        return $result->invoice();
    }

    private function recordedPayment(string $amount): Payment
    {
        $idempotencyKey = IdempotencyKey::of('idem-key-payment-'.bin2hex(random_bytes(4)));

        $command = new RecordPaymentCommand(
            PaymentId::of(DeterministicIdempotentId::derive($this->tenant, $idempotencyKey, 'payment')),
            JournalId::of(DeterministicIdempotentId::derive($this->tenant, $idempotencyKey, 'journal')),
            $idempotencyKey,
            $this->tenant,
            ActorReference::of('user-0001'),
            CustomerId::of('customer-0001'),
            Money::fromDecimalString($amount, $this->myr),
            new \DateTimeImmutable('2026-09-01'),
            AccountId::of('account-bank'),
            AccountId::of('account-receivable'),
            null,
        );

        return $this->paymentService->record($command)->payment();
    }

    private function buildPostingExecutor(ConnectionInterface $connection): PostingCommandTransactionalExecutor
    {
        $journalRepository = new JournalRepository($connection);
        $accountRepository = new AccountRepository($connection);
        $idempotencyRepository = new PostingIdempotencyRepository($connection);

        $journalExecutor = new PostingCommandJournalExecutor(
            new PostingCommandJournalStateResolver($journalRepository),
            new PostingCommandAccountValidator($accountRepository),
            new PostingCommandPeriodLockValidator(new PeriodClosureRepository($connection)),
            new PostingCommandExistingDraftLineValidator,
            new DraftJournalAssembler,
            $journalRepository,
        );

        $idempotencyResolver = new PostingCommandIdempotencyResolver(
            $idempotencyRepository,
            $journalRepository,
            new PostingCommandLogicalEquivalence,
        );

        return new PostingCommandTransactionalExecutor(
            $connection,
            $idempotencyResolver,
            $journalExecutor,
            $idempotencyRepository,
            new AuditEventRepository($connection),
            new JournalEvidenceLinkRepository($connection),
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

        $requiredTables = [
            self::ACCOUNT_TABLE, self::CUSTOMER_TABLE, 'journals', 'journal_lines',
            'posting_idempotency_keys', 'audit_events', 'journal_evidence_links',
            'invoices', 'invoice_lines', 'invoice_number_sequences',
            'payments', 'payment_allocations',
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
