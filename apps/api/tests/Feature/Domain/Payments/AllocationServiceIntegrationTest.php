<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Payments;

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
use App\Domain\Invoicing\Exception\InvoiceNotFoundException;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceIssuingService;
use App\Domain\Invoicing\InvoiceLine;
use App\Domain\Invoicing\InvoiceToPostingCommandTranslator;
use App\Domain\Payments\AllocationService;
use App\Domain\Payments\Exception\AllocationExceedsInvoiceBalanceException;
use App\Domain\Payments\Exception\AllocationExceedsPaymentAmountException;
use App\Domain\Payments\Exception\InvoiceNotIssuedException;
use App\Domain\Payments\Exception\PaymentAllocationNotFoundException;
use App\Domain\Payments\Exception\PaymentNotFoundException;
use App\Domain\Payments\Payment;
use App\Domain\Payments\PaymentAccountTypeValidator;
use App\Domain\Payments\PaymentAllocationId;
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
use App\Infrastructure\Payments\PaymentAllocationRepository;
use App\Infrastructure\Payments\PaymentRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CleansSharedAccountingTables;
use Tests\TestCase;

/**
 * Integration-level proof for {@see AllocationService} (M21) —
 * exercised against a real PostgreSQL instance, wiring both the
 * Payment and Invoice posting pipelines so allocation can be tested
 * against genuinely-Issued Invoices and genuinely-recorded Payments.
 *
 * Enforces the two named invariants Master Context §10 locks: an
 * Invoice's own allocations must never exceed its `total_amount`, and
 * a Payment's own allocations must never exceed its `amount`.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class AllocationServiceIntegrationTest extends TestCase
{
    use CleansSharedAccountingTables;

    private const ACCOUNT_TABLE = 'accounts';

    private const CUSTOMER_TABLE = 'customers';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

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

    public function test_allocates_a_payment_fully_to_a_single_invoice(): void
    {
        $invoice = $this->issuedInvoice('300.00');
        $payment = $this->recordedPayment('300.00');

        $allocation = $this->allocationService->allocate($this->tenant, $payment->id(), $invoice->id(), Money::fromDecimalString('300.00', $this->myr));

        $this->assertSame('300.00', $allocation->amount()->toDecimalString());
    }

    public function test_partial_allocation_leaves_an_outstanding_balance(): void
    {
        $invoice = $this->issuedInvoice('300.00');
        $payment = $this->recordedPayment('300.00');

        $this->allocationService->allocate($this->tenant, $payment->id(), $invoice->id(), Money::fromDecimalString('100.00', $this->myr));

        $outstanding = $this->allocationService->outstandingBalanceFor($this->tenant, $invoice->id(), $invoice->totalAmount());

        $this->assertSame('200.00', $outstanding->toDecimalString());
    }

    public function test_a_single_payment_can_be_split_across_two_invoices(): void
    {
        $invoiceA = $this->issuedInvoice('100.00', 'invoice-a');
        $invoiceB = $this->issuedInvoice('150.00', 'invoice-b');
        $payment = $this->recordedPayment('250.00');

        $this->allocationService->allocate($this->tenant, $payment->id(), $invoiceA->id(), Money::fromDecimalString('100.00', $this->myr));
        $this->allocationService->allocate($this->tenant, $payment->id(), $invoiceB->id(), Money::fromDecimalString('150.00', $this->myr));

        $this->assertSame('0.00', $this->allocationService->outstandingBalanceFor($this->tenant, $invoiceA->id(), $invoiceA->totalAmount())->toDecimalString());
        $this->assertSame('0.00', $this->allocationService->outstandingBalanceFor($this->tenant, $invoiceB->id(), $invoiceB->totalAmount())->toDecimalString());
    }

    public function test_allocating_more_than_the_invoice_balance_is_rejected(): void
    {
        $invoice = $this->issuedInvoice('100.00');
        $payment = $this->recordedPayment('500.00');

        $this->allocationService->allocate($this->tenant, $payment->id(), $invoice->id(), Money::fromDecimalString('60.00', $this->myr));

        $this->expectException(AllocationExceedsInvoiceBalanceException::class);

        $this->allocationService->allocate($this->tenant, $payment->id(), $invoice->id(), Money::fromDecimalString('50.00', $this->myr));
    }

    public function test_allocating_more_than_the_payment_amount_is_rejected(): void
    {
        $invoiceA = $this->issuedInvoice('500.00', 'invoice-a');
        $invoiceB = $this->issuedInvoice('500.00', 'invoice-b');
        $payment = $this->recordedPayment('100.00');

        $this->allocationService->allocate($this->tenant, $payment->id(), $invoiceA->id(), Money::fromDecimalString('60.00', $this->myr));

        $this->expectException(AllocationExceedsPaymentAmountException::class);

        $this->allocationService->allocate($this->tenant, $payment->id(), $invoiceB->id(), Money::fromDecimalString('50.00', $this->myr));
    }

    public function test_allocating_against_a_draft_invoice_is_rejected(): void
    {
        $draft = $this->draftInvoiceOnly('100.00');
        $payment = $this->recordedPayment('100.00');

        $this->expectException(InvoiceNotIssuedException::class);

        $this->allocationService->allocate($this->tenant, $payment->id(), $draft->id(), Money::fromDecimalString('50.00', $this->myr));
    }

    public function test_allocating_against_a_nonexistent_payment_is_rejected(): void
    {
        $invoice = $this->issuedInvoice('100.00');

        $this->expectException(PaymentNotFoundException::class);

        $this->allocationService->allocate($this->tenant, PaymentId::of('does-not-exist'), $invoice->id(), Money::fromDecimalString('50.00', $this->myr));
    }

    public function test_allocating_against_a_nonexistent_invoice_is_rejected(): void
    {
        $payment = $this->recordedPayment('100.00');

        $this->expectException(InvoiceNotFoundException::class);

        $this->allocationService->allocate($this->tenant, $payment->id(), InvoiceId::of('does-not-exist'), Money::fromDecimalString('50.00', $this->myr));
    }

    public function test_deallocate_removes_the_allocation_and_frees_the_balance(): void
    {
        $invoice = $this->issuedInvoice('300.00');
        $payment = $this->recordedPayment('300.00');
        $allocation = $this->allocationService->allocate($this->tenant, $payment->id(), $invoice->id(), Money::fromDecimalString('300.00', $this->myr));

        $this->allocationService->deallocate($this->tenant, $allocation->id(), ActorReference::of('user-0001'));

        $outstanding = $this->allocationService->outstandingBalanceFor($this->tenant, $invoice->id(), $invoice->totalAmount());
        $this->assertSame('300.00', $outstanding->toDecimalString());
    }

    public function test_deallocate_soft_deletes_leaving_an_audit_trail(): void
    {
        $invoice = $this->issuedInvoice('300.00');
        $payment = $this->recordedPayment('300.00');
        $allocation = $this->allocationService->allocate($this->tenant, $payment->id(), $invoice->id(), Money::fromDecimalString('300.00', $this->myr));

        $this->allocationService->deallocate($this->tenant, $allocation->id(), ActorReference::of('user-0001'));

        $row = DB::connection('pgsql')->table('payment_allocations')
            ->where('tenant_id', $this->tenant->toString())
            ->where('id', $allocation->id()->toString())
            ->first();

        $this->assertNotNull($row, 'Deallocating must not physically delete the row (P1-3) — the row must survive as its own audit trail.');
        $this->assertNotNull($row->deleted_at);
        $this->assertSame('user-0001', $row->deleted_by_actor);
    }

    public function test_deallocating_a_nonexistent_allocation_is_rejected(): void
    {
        $this->expectException(PaymentAllocationNotFoundException::class);

        $this->allocationService->deallocate($this->tenant, PaymentAllocationId::of('does-not-exist'), ActorReference::of('user-0001'));
    }

    public function test_deallocating_an_already_deallocated_allocation_is_rejected(): void
    {
        $invoice = $this->issuedInvoice('300.00');
        $payment = $this->recordedPayment('300.00');
        $allocation = $this->allocationService->allocate($this->tenant, $payment->id(), $invoice->id(), Money::fromDecimalString('300.00', $this->myr));
        $this->allocationService->deallocate($this->tenant, $allocation->id(), ActorReference::of('user-0001'));

        $this->expectException(PaymentAllocationNotFoundException::class);

        $this->allocationService->deallocate($this->tenant, $allocation->id(), ActorReference::of('user-0002'));
    }

    private function issuedInvoice(string $totalAmount, string $invoiceId = 'invoice-0001'): Invoice
    {
        $draft = Invoice::draft(
            InvoiceId::of($invoiceId),
            $this->tenant,
            CustomerId::of('customer-0001'),
            new \DateTimeImmutable('2026-12-31'),
            AccountId::of('account-receivable'),
            AccountId::of('account-revenue'),
            [InvoiceLine::of('Item', 1, Money::fromDecimalString($totalAmount, $this->myr))],
            $this->myr,
        );
        $this->invoiceRepository->save($draft);

        $idempotencyKey = IdempotencyKey::of('idem-key-issue-'.$invoiceId);
        $journalId = JournalId::of(DeterministicIdempotentId::derive($this->tenant, $idempotencyKey, 'journal'));

        $result = $this->invoiceIssuingService->issue($this->tenant, $draft->id(), $journalId, $idempotencyKey, ActorReference::of('user-0001'), new \DateTimeImmutable('2026-09-08'));

        return $result->invoice();
    }

    private function draftInvoiceOnly(string $totalAmount): Invoice
    {
        $draft = Invoice::draft(
            InvoiceId::of('invoice-draft'),
            $this->tenant,
            CustomerId::of('customer-0001'),
            new \DateTimeImmutable('2026-12-31'),
            AccountId::of('account-receivable'),
            AccountId::of('account-revenue'),
            [InvoiceLine::of('Item', 1, Money::fromDecimalString($totalAmount, $this->myr))],
            $this->myr,
        );
        $this->invoiceRepository->save($draft);

        return $draft;
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
            new \DateTimeImmutable('2026-09-08'),
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
