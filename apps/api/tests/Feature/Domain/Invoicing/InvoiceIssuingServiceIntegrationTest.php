<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Invoicing;

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
use App\Domain\Invoicing\Exception\EmptyInvoiceCannotBeIssuedException;
use App\Domain\Invoicing\Exception\InvalidInvoiceStatusTransitionException;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceIssuingResult;
use App\Domain\Invoicing\InvoiceIssuingService;
use App\Domain\Invoicing\InvoiceLine;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\InvoiceToPostingCommandTranslator;
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
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CleansSharedAccountingTables;
use Tests\Feature\Domain\Transactions\Income\IncomeRecordingServiceIntegrationTest;
use Tests\TestCase;

/**
 * Integration-level proof for {@see InvoiceIssuingService} (M20) —
 * exercised against a real PostgreSQL instance, wiring the real
 * Posting Command pipeline exactly as
 * {@see IncomeRecordingServiceIntegrationTest}
 * already does for Income.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class InvoiceIssuingServiceIntegrationTest extends TestCase
{
    use CleansSharedAccountingTables;

    private const ACCOUNT_TABLE = 'accounts';

    private const CUSTOMER_TABLE = 'customers';

    private const SEQUENCE_TABLE = 'invoice_number_sequences';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private InvoiceIssuingService $issuingService;

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
        DB::connection('pgsql')->table(self::SEQUENCE_TABLE)->delete();

        $connection = DB::connection('pgsql');
        $this->tenant = TenantId::of('tenant-0001');
        $this->myr = Currency::of('MYR');
        $this->invoiceRepository = new InvoiceRepository($connection);
        $this->issuingService = $this->buildService($connection);

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

    public function test_issuing_a_draft_invoice_assigns_a_number_and_posts_a_balanced_journal(): void
    {
        $invoice = $this->draftInvoice([
            InvoiceLine::of('Consulting', 2, Money::fromDecimalString('150.00', $this->myr)),
        ]);
        $this->invoiceRepository->save($invoice);

        $result = $this->issue($invoice->id());

        $this->assertTrue($result->isNewlyIssued());
        $this->assertSame(InvoiceStatus::Issued, $result->invoice()->status());
        $this->assertSame('INV-000001', $result->invoice()->invoiceNumber());
        $this->assertNotNull($result->invoice()->journalId());

        $lines = DB::connection('pgsql')->table('journal_lines')
            ->where('tenant_id', $this->tenant->toString())
            ->where('journal_id', $result->invoice()->journalId()->toString())
            ->get();

        $this->assertCount(2, $lines);
        $debit = $lines->firstWhere('direction', 'Debit');
        $credit = $lines->firstWhere('direction', 'Credit');
        $this->assertSame('account-receivable', $debit->account_id);
        $this->assertSame('account-revenue', $credit->account_id);
        $this->assertSame((string) $debit->amount, (string) $credit->amount);
    }

    public function test_invoice_numbers_are_sequential_per_tenant(): void
    {
        $first = $this->draftInvoice([InvoiceLine::of('Item', 1, Money::fromDecimalString('10.00', $this->myr))], 'invoice-0001');
        $second = $this->draftInvoice([InvoiceLine::of('Item', 1, Money::fromDecimalString('10.00', $this->myr))], 'invoice-0002');
        $this->invoiceRepository->save($first);
        $this->invoiceRepository->save($second);

        $firstResult = $this->issue($first->id(), 'idem-key-1');
        $secondResult = $this->issue($second->id(), 'idem-key-2');

        $this->assertSame('INV-000001', $firstResult->invoice()->invoiceNumber());
        $this->assertSame('INV-000002', $secondResult->invoice()->invoiceNumber());
    }

    public function test_reissuing_with_the_same_idempotency_key_replays_instead_of_duplicating(): void
    {
        $invoice = $this->draftInvoice([InvoiceLine::of('Item', 1, Money::fromDecimalString('10.00', $this->myr))]);
        $this->invoiceRepository->save($invoice);

        $first = $this->issue($invoice->id(), 'idem-key-replay');
        $second = $this->issue($invoice->id(), 'idem-key-replay');

        $this->assertTrue($first->isNewlyIssued());
        $this->assertTrue($second->isReplay());
        $this->assertSame($first->invoice()->invoiceNumber(), $second->invoice()->invoiceNumber());
        $this->assertSame(1, DB::connection('pgsql')->table('journals')->where('tenant_id', $this->tenant->toString())->count());
    }

    public function test_issuing_an_empty_invoice_is_rejected(): void
    {
        $invoice = $this->draftInvoice([]);
        $this->invoiceRepository->save($invoice);

        $this->expectException(EmptyInvoiceCannotBeIssuedException::class);

        $this->issue($invoice->id());
    }

    public function test_issuing_an_already_issued_invoice_with_a_different_idempotency_key_is_rejected(): void
    {
        $invoice = $this->draftInvoice([InvoiceLine::of('Item', 1, Money::fromDecimalString('10.00', $this->myr))]);
        $this->invoiceRepository->save($invoice);

        $this->issue($invoice->id(), 'idem-key-first');

        $this->expectException(InvalidInvoiceStatusTransitionException::class);

        $this->issue($invoice->id(), 'idem-key-second');
    }

    /**
     * The definitive proof for P0-1: two genuinely concurrent OS
     * processes, each independently bootstrapping Laravel and calling
     * the real `InvoiceIssuingService::issue()` against the *same*
     * Draft Invoice with *different* Idempotency Keys — the exact
     * double-click/racing-retry scenario the audit described. PHPUnit
     * is single-threaded and this codebase's own `Tests\TestCase`
     * performs no per-test rollback, so a single-process test (however
     * cleverly it holds a lock) cannot reproduce a genuine race between
     * two overlapping transactions the way spawning two real processes
     * can. See `tests/bin/concurrent_issue_worker.php`'s own docblock
     * for the full synchronization mechanism.
     *
     * Before the fix, this reliably produced two Posted Journals for
     * one Invoice — confirmed directly, by temporarily restoring the
     * pre-fix version of `InvoiceIssuingService` and running this exact
     * test three times (three failures, each with two distinct
     * `journal_id` values and two consumed Invoice numbers for the one
     * Invoice), then restoring the fix and confirming five clean runs.
     */
    public function test_two_concurrent_issue_attempts_never_post_two_journals(): void
    {
        $invoice = $this->draftInvoice([InvoiceLine::of('Item', 1, Money::fromDecimalString('10.00', $this->myr))], 'invoice-concurrency');
        $this->invoiceRepository->save($invoice);

        $tmp = sys_get_temp_dir();
        $readyA = tempnam($tmp, 'ready_a_');
        $readyB = tempnam($tmp, 'ready_b_');
        $goFile = tempnam($tmp, 'go_');
        $resultA = tempnam($tmp, 'result_a_');
        $resultB = tempnam($tmp, 'result_b_');
        // tempnam() already creates each file — the workers below poll
        // for existence of the *ready*/*go* files specifically to
        // signal state, so start clean.
        unlink($readyA);
        unlink($readyB);
        unlink($goFile);

        $workerScript = base_path('tests/bin/concurrent_issue_worker.php');

        $processA = proc_open(
            ['php', $workerScript, $this->tenant->toString(), $invoice->id()->toString(), 'idem-key-concurrent-a', $readyA, $goFile, $resultA],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesA,
        );
        $processB = proc_open(
            ['php', $workerScript, $this->tenant->toString(), $invoice->id()->toString(), 'idem-key-concurrent-b', $readyB, $goFile, $resultB],
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

        $outcomes = [$resultAData, $resultBData];
        $newlyIssuedCount = count(array_filter($outcomes, static fn (array $r): bool => ($r['newly_issued'] ?? false) === true));
        $rejectedCount = count(array_filter($outcomes, static fn (array $r): bool => ($r['exception'] ?? null) === InvalidInvoiceStatusTransitionException::class));

        $this->assertSame(
            1,
            $newlyIssuedCount,
            'Exactly one of the two concurrent Issue attempts must newly issue the Invoice. Got: '.json_encode($outcomes),
        );
        $this->assertSame(
            1,
            $rejectedCount,
            'The other concurrent attempt must be rejected as a non-Draft transition (different Idempotency Keys, so it cannot be a replay). Got: '.json_encode($outcomes),
        );

        $journalCount = DB::connection('pgsql')->table('journals')
            ->where('tenant_id', $this->tenant->toString())
            ->count();
        $this->assertSame(1, $journalCount, 'Exactly one Journal must exist for this Tenant — a second concurrent Issue attempt must never post its own.');
    }

    private function issue(InvoiceId $invoiceId, string $idempotencyKeyValue = 'idem-key-default'): InvoiceIssuingResult
    {
        $idempotencyKey = IdempotencyKey::of($idempotencyKeyValue);
        $journalId = JournalId::of(DeterministicIdempotentId::derive($this->tenant, $idempotencyKey, 'journal'));

        return $this->issuingService->issue(
            $this->tenant,
            $invoiceId,
            $journalId,
            $idempotencyKey,
            ActorReference::of('user-0001'),
            new \DateTimeImmutable('2026-09-08'),
        );
    }

    /**
     * @param  list<InvoiceLine>  $lines
     */
    private function draftInvoice(array $lines, string $invoiceId = 'invoice-0001'): Invoice
    {
        return Invoice::draft(
            InvoiceId::of($invoiceId),
            $this->tenant,
            CustomerId::of('customer-0001'),
            new \DateTimeImmutable('2026-12-31'),
            AccountId::of('account-receivable'),
            AccountId::of('account-revenue'),
            $lines,
            $this->myr,
        );
    }

    private function buildService(ConnectionInterface $connection): InvoiceIssuingService
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

        $postingExecutor = new PostingCommandTransactionalExecutor(
            $connection,
            $idempotencyResolver,
            $journalExecutor,
            $idempotencyRepository,
            new AuditEventRepository($connection),
            new JournalEvidenceLinkRepository($connection),
        );

        return new InvoiceIssuingService(
            $connection,
            new InvoiceRepository($connection),
            new InvoiceNumberGenerator($connection),
            new InvoiceToPostingCommandTranslator,
            $postingExecutor,
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
            'invoices', 'invoice_lines', self::SEQUENCE_TABLE,
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
