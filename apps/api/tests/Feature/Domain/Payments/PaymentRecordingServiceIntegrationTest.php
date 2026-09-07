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
use App\Domain\Payments\Exception\InvalidPaymentDepositAccountTypeException;
use App\Domain\Payments\PaymentAccountTypeValidator;
use App\Domain\Payments\PaymentId;
use App\Domain\Payments\PaymentRecordingResult;
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
use App\Infrastructure\Payments\PaymentRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CleansSharedAccountingTables;
use Tests\Feature\Domain\Transactions\Income\IncomeRecordingServiceIntegrationTest;
use Tests\TestCase;

/**
 * Integration-level proof for {@see PaymentRecordingService} (M21) —
 * exercised against a real PostgreSQL instance, wiring the real
 * Posting Command pipeline exactly as
 * {@see IncomeRecordingServiceIntegrationTest}
 * already does for Income.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class PaymentRecordingServiceIntegrationTest extends TestCase
{
    use CleansSharedAccountingTables;

    private const ACCOUNT_TABLE = 'accounts';

    private const CUSTOMER_TABLE = 'customers';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private PaymentRecordingService $paymentService;

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
        $this->paymentService = $this->buildService($connection);

        $this->insertAccount('account-bank', 'Asset');
        $this->insertAccount('account-receivable', 'Asset');
        $this->insertAccount('account-liability', 'Liability');
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

    public function test_recording_a_payment_posts_a_balanced_journal(): void
    {
        $result = $this->record();

        $this->assertTrue($result->isNewlyRecorded());
        $this->assertSame('500.00', $result->payment()->amount()->toDecimalString());

        $lines = DB::connection('pgsql')->table('journal_lines')
            ->where('tenant_id', $this->tenant->toString())
            ->where('journal_id', $result->payment()->journalId()->toString())
            ->get();

        $this->assertCount(2, $lines);
        $debit = $lines->firstWhere('direction', 'Debit');
        $credit = $lines->firstWhere('direction', 'Credit');
        $this->assertSame('account-bank', $debit->account_id);
        $this->assertSame('account-receivable', $credit->account_id);
    }

    public function test_retrying_with_the_same_idempotency_key_replays_instead_of_duplicating(): void
    {
        $first = $this->record('idem-key-replay');
        $second = $this->record('idem-key-replay');

        $this->assertTrue($first->isNewlyRecorded());
        $this->assertTrue($second->isReplay());
        $this->assertSame($first->payment()->id()->toString(), $second->payment()->id()->toString());
        $this->assertSame(1, DB::connection('pgsql')->table('journals')->where('tenant_id', $this->tenant->toString())->count());
    }

    public function test_a_non_asset_deposit_account_is_rejected(): void
    {
        $this->expectException(InvalidPaymentDepositAccountTypeException::class);

        $this->record(depositAccountId: 'account-liability');
    }

    private function record(string $idempotencyKeyValue = 'idem-key-default', string $depositAccountId = 'account-bank'): PaymentRecordingResult
    {
        $idempotencyKey = IdempotencyKey::of($idempotencyKeyValue);

        $command = new RecordPaymentCommand(
            PaymentId::of(DeterministicIdempotentId::derive($this->tenant, $idempotencyKey, 'payment')),
            JournalId::of(DeterministicIdempotentId::derive($this->tenant, $idempotencyKey, 'journal')),
            $idempotencyKey,
            $this->tenant,
            ActorReference::of('user-0001'),
            CustomerId::of('customer-0001'),
            Money::fromDecimalString('500.00', $this->myr),
            new \DateTimeImmutable('2026-09-08'),
            AccountId::of($depositAccountId),
            AccountId::of('account-receivable'),
            'REF-001',
        );

        return $this->paymentService->record($command);
    }

    private function buildService(ConnectionInterface $connection): PaymentRecordingService
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

        return new PaymentRecordingService(
            $connection,
            new PaymentAccountTypeValidator($accountRepository),
            new PaymentToPostingCommandTranslator,
            $postingExecutor,
            new PaymentRepository($connection),
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
            'posting_idempotency_keys', 'audit_events', 'journal_evidence_links', 'payments',
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
