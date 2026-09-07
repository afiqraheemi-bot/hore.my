<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Accounting\Posting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\Exception\CorruptPostingIdempotencyMappingException;
use App\Domain\Accounting\Posting\Exception\RejectedConflictingIdempotencyReuseException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\PostingCommandIdempotencyResolver;
use App\Domain\Accounting\Posting\PostingCommandLogicalEquivalence;
use App\Domain\Accounting\Posting\SourceFingerprint;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * Integration-level proof for {@see PostingCommandIdempotencyResolver}
 * (M4-T17), exercised against a real PostgreSQL instance and the real
 * `JournalRepository`/`PostingIdempotencyRepository` — both repositories
 * are genuinely involved, never stubbed, since this resolver's whole
 * job is to compose their real return values.
 *
 * This directly evidences the decision semantics behind `POST-004` and
 * `POST-T021`–`POST-T023`: read/decision-level only. It does not, and
 * cannot, evidence atomic first-write or concurrency completion — no
 * new Journal is posted and no idempotency mapping is recorded by this
 * resolver; that remains later work (M4-T18+).
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class PostingCommandIdempotencyResolverTest extends TestCase
{
    private const IDEMPOTENCY_TABLE = 'posting_idempotency_keys';

    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const IDEMPOTENCY_MIGRATION_PATH = 'database/migrations/2026_09_05_090000_create_posting_idempotency_keys_table.php';

    private const JOURNAL_MIGRATION_PATH = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';

    private const CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php';

    private const FINANCIAL_DATE_MIGRATION_PATH = 'database/migrations/2026_09_06_230000_add_financial_date_and_posted_at_to_journals_table.php';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private PostingCommandIdempotencyResolver $resolver;

    private JournalRepository $journalRepository;

    private PostingIdempotencyRepository $idempotencyRepository;

    private TenantId $tenantA;

    private TenantId $tenantB;

    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->delete();
        DB::connection('pgsql')->table(self::LINE_TABLE)->delete();
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->delete();
        foreach (['reconciliation_reopenings', 'matches', 'bank_transactions', 'reconciliations', 'bank_statement_import_batches', 'bank_accounts'] as $bankingTable) {
            if (Schema::connection('pgsql')->hasTable($bankingTable)) {
                DB::connection('pgsql')->table($bankingTable)->delete();
            }
        }
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->delete();

        $connection = DB::connection('pgsql');
        $this->journalRepository = new JournalRepository($connection);
        $this->idempotencyRepository = new PostingIdempotencyRepository($connection);
        $this->resolver = new PostingCommandIdempotencyResolver(
            $this->idempotencyRepository,
            $this->journalRepository,
            new PostingCommandLogicalEquivalence,
        );

        $this->tenantA = TenantId::of('tenant-0001');
        $this->tenantB = TenantId::of('tenant-0002');
        $this->myr = Currency::of('MYR');

        $this->insertAccount($this->tenantA, 'account-cash');
        $this->insertAccount($this->tenantA, 'account-income');
        $this->insertAccount($this->tenantA, 'account-other');
        $this->insertAccount($this->tenantB, 'account-cash-b');
        $this->insertAccount($this->tenantB, 'account-income-b');
    }

    public function test_unused_key_resolves_as_first_submission(): void
    {
        $command = $this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines());

        $decision = $this->resolver->resolve($command);

        $this->assertTrue($decision->isFirstSubmission());
        $this->assertFalse($decision->isReplay());
        $this->assertNull($decision->replayedJournal());
    }

    public function test_existing_key_with_identical_logical_payload_resolves_as_replay(): void
    {
        $journal = $this->persistJournal($this->tenantA, 'journal-0001', $this->balancedLines());
        $key = IdempotencyKey::of('key-0001');
        $this->idempotencyRepository->record($this->tenantA, $key, $journal->id());

        $command = $this->makeCommand($this->tenantA, $journal->id(), $this->balancedLines(), idempotencyKey: $key);

        $decision = $this->resolver->resolve($command);

        $this->assertTrue($decision->isReplay());
    }

    public function test_replay_returns_the_exact_mapped_journal(): void
    {
        $journal = $this->persistJournal($this->tenantA, 'journal-0001', $this->balancedLines());
        $key = IdempotencyKey::of('key-0001');
        $this->idempotencyRepository->record($this->tenantA, $key, $journal->id());

        $command = $this->makeCommand($this->tenantA, $journal->id(), $this->balancedLines(), idempotencyKey: $key);

        $decision = $this->resolver->resolve($command);

        $this->assertNotNull($decision->replayedJournal());
        $this->assertTrue($decision->replayedJournal()->equals($journal));
    }

    public function test_different_journal_id_is_a_conflict(): void
    {
        $journal = $this->persistJournal($this->tenantA, 'journal-0001', $this->balancedLines());
        $key = IdempotencyKey::of('key-0001');
        $this->idempotencyRepository->record($this->tenantA, $key, $journal->id());

        // Same lines, but the incoming command tries to redirect the
        // existing key to a different proposed Journal identity.
        $command = $this->makeCommand($this->tenantA, JournalId::of('journal-9999'), $this->balancedLines(), idempotencyKey: $key);

        $this->expectException(RejectedConflictingIdempotencyReuseException::class);
        $this->resolver->resolve($command);
    }

    public function test_different_line_count_is_a_conflict(): void
    {
        $journal = $this->persistJournal($this->tenantA, 'journal-0001', $this->balancedLines());
        $key = IdempotencyKey::of('key-0001');
        $this->idempotencyRepository->record($this->tenantA, $key, $journal->id());

        $command = $this->makeCommand($this->tenantA, $journal->id(), [
            $this->debitLine('account-cash', '60.00'),
            $this->debitLine('account-other', '40.00'),
            $this->creditLine('account-income', '100.00'),
        ], idempotencyKey: $key);

        $this->expectException(RejectedConflictingIdempotencyReuseException::class);
        $this->resolver->resolve($command);
    }

    public function test_reordered_lines_are_a_conflict(): void
    {
        $lines = [
            $this->debitLine('account-cash', '10.00'),
            $this->debitLine('account-other', '20.00'),
            $this->creditLine('account-income', '30.00'),
        ];
        $journal = $this->persistJournal($this->tenantA, 'journal-0001', $lines);
        $key = IdempotencyKey::of('key-0001');
        $this->idempotencyRepository->record($this->tenantA, $key, $journal->id());

        $command = $this->makeCommand($this->tenantA, $journal->id(), [
            $this->debitLine('account-other', '20.00'),
            $this->debitLine('account-cash', '10.00'),
            $this->creditLine('account-income', '30.00'),
        ], idempotencyKey: $key);

        $this->expectException(RejectedConflictingIdempotencyReuseException::class);
        $this->resolver->resolve($command);
    }

    public function test_account_id_difference_is_a_conflict(): void
    {
        $journal = $this->persistJournal($this->tenantA, 'journal-0001', $this->balancedLines());
        $key = IdempotencyKey::of('key-0001');
        $this->idempotencyRepository->record($this->tenantA, $key, $journal->id());

        $command = $this->makeCommand($this->tenantA, $journal->id(), [
            $this->debitLine('account-other', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ], idempotencyKey: $key);

        $this->expectException(RejectedConflictingIdempotencyReuseException::class);
        $this->resolver->resolve($command);
    }

    public function test_direction_difference_is_a_conflict(): void
    {
        $journal = $this->persistJournal($this->tenantA, 'journal-0001', $this->balancedLines());
        $key = IdempotencyKey::of('key-0001');
        $this->idempotencyRepository->record($this->tenantA, $key, $journal->id());

        $command = $this->makeCommand($this->tenantA, $journal->id(), [
            $this->creditLine('account-cash', '100.00'),
            $this->debitLine('account-income', '100.00'),
        ], idempotencyKey: $key);

        $this->expectException(RejectedConflictingIdempotencyReuseException::class);
        $this->resolver->resolve($command);
    }

    public function test_amount_difference_is_a_conflict(): void
    {
        $journal = $this->persistJournal($this->tenantA, 'journal-0001', $this->balancedLines());
        $key = IdempotencyKey::of('key-0001');
        $this->idempotencyRepository->record($this->tenantA, $key, $journal->id());

        $command = $this->makeCommand($this->tenantA, $journal->id(), [
            $this->debitLine('account-cash', '150.00'),
            $this->creditLine('account-income', '150.00'),
        ], idempotencyKey: $key);

        $this->expectException(RejectedConflictingIdempotencyReuseException::class);
        $this->resolver->resolve($command);
    }

    /**
     * (M8, POST-028) A same-key reuse whose Journal Lines are
     * identical but whose Financial Date differs from the persisted
     * Journal's own Financial Date is a conflicting reuse, not a safe
     * replay — this is the exact scenario the M8 mandate's idempotency
     * requirement exists to catch.
     */
    public function test_different_financial_date_with_identical_lines_is_a_conflict(): void
    {
        $journal = $this->persistJournal($this->tenantA, 'journal-0001', $this->balancedLines(), new \DateTimeImmutable('2026-01-01'));
        $key = IdempotencyKey::of('key-0001');
        $this->idempotencyRepository->record($this->tenantA, $key, $journal->id());

        $command = $this->makeCommand(
            $this->tenantA,
            $journal->id(),
            $this->balancedLines(),
            idempotencyKey: $key,
            financialDate: new \DateTimeImmutable('2026-02-01'),
        );

        $this->expectException(RejectedConflictingIdempotencyReuseException::class);
        $this->resolver->resolve($command);
    }

    /**
     * A different Currency for an otherwise-matching line is a
     * conflict — proven via the same reflection-based
     * `currencyOtherThanMyr()` technique already established elsewhere
     * in this suite, since `Currency`'s registry currently supports
     * only `MYR`. The persisted Journal itself is real MYR (the only
     * registrable Currency); only the incoming, never-persisted
     * comparison command carries the synthetic Currency.
     */
    public function test_currency_difference_is_a_conflict(): void
    {
        $journal = $this->persistJournal($this->tenantA, 'journal-0001', $this->balancedLines());
        $key = IdempotencyKey::of('key-0001');
        $this->idempotencyRepository->record($this->tenantA, $key, $journal->id());

        $otherCurrency = $this->currencyOtherThanMyr();
        $command = $this->makeCommand($this->tenantA, $journal->id(), [
            JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('100.00', $otherCurrency), JournalDirection::Debit),
            JournalLine::create(AccountId::of('account-income'), Money::fromDecimalString('100.00', $otherCurrency), JournalDirection::Credit),
        ], idempotencyKey: $key);

        $this->expectException(RejectedConflictingIdempotencyReuseException::class);
        $this->resolver->resolve($command);
    }

    public function test_actor_only_difference_still_resolves_as_replay(): void
    {
        $journal = $this->persistJournal($this->tenantA, 'journal-0001', $this->balancedLines());
        $key = IdempotencyKey::of('key-0001');
        $this->idempotencyRepository->record($this->tenantA, $key, $journal->id());

        $command = $this->makeCommand($this->tenantA, $journal->id(), $this->balancedLines(), idempotencyKey: $key, actor: ActorReference::of('actor-0002'));

        $decision = $this->resolver->resolve($command);

        $this->assertTrue($decision->isReplay());
    }

    public function test_source_only_difference_still_resolves_as_replay(): void
    {
        $journal = $this->persistJournal($this->tenantA, 'journal-0001', $this->balancedLines());
        $key = IdempotencyKey::of('key-0001');
        $this->idempotencyRepository->record($this->tenantA, $key, $journal->id());

        $command = $this->makeCommand($this->tenantA, $journal->id(), $this->balancedLines(), idempotencyKey: $key, source: SourceReference::of('source-0002'));

        $decision = $this->resolver->resolve($command);

        $this->assertTrue($decision->isReplay());
    }

    public function test_source_fingerprint_only_difference_still_resolves_as_replay(): void
    {
        $journal = $this->persistJournal($this->tenantA, 'journal-0001', $this->balancedLines());
        $key = IdempotencyKey::of('key-0001');
        $this->idempotencyRepository->record($this->tenantA, $key, $journal->id());

        $command = $this->makeCommand($this->tenantA, $journal->id(), $this->balancedLines(), idempotencyKey: $key, sourceFingerprint: SourceFingerprint::of('fp-0002'));

        $decision = $this->resolver->resolve($command);

        $this->assertTrue($decision->isReplay());
    }

    public function test_evidence_only_difference_still_resolves_as_replay(): void
    {
        $journal = $this->persistJournal($this->tenantA, 'journal-0001', $this->balancedLines());
        $key = IdempotencyKey::of('key-0001');
        $this->idempotencyRepository->record($this->tenantA, $key, $journal->id());

        $command = $this->makeCommand($this->tenantA, $journal->id(), $this->balancedLines(), idempotencyKey: $key, evidenceReferences: ['evidence-0001']);

        $decision = $this->resolver->resolve($command);

        $this->assertTrue($decision->isReplay());
    }

    /**
     * Simulates a corrupt mapping by temporarily dropping this table's
     * own composite foreign key — the only way to construct this state
     * at all, since that constraint (M4-T15) already makes it
     * impossible under normal operation. The constraint is restored
     * immediately afterward regardless of outcome.
     */
    public function test_corrupt_mapping_with_an_unresolvable_journal_fails_loudly(): void
    {
        $connection = DB::connection('pgsql');
        $connection->statement(
            'ALTER TABLE posting_idempotency_keys DROP CONSTRAINT posting_idempotency_keys_tenant_id_journal_id_foreign'
        );

        try {
            $connection->table(self::IDEMPOTENCY_TABLE)->insert([
                'tenant_id' => $this->tenantA->toString(),
                'idempotency_key' => 'key-corrupt',
                'journal_id' => 'journal-does-not-exist',
            ]);

            $command = $this->makeCommand(
                $this->tenantA,
                JournalId::of('journal-does-not-exist'),
                $this->balancedLines(),
                idempotencyKey: IdempotencyKey::of('key-corrupt'),
            );

            $this->expectException(CorruptPostingIdempotencyMappingException::class);
            $this->resolver->resolve($command);
        } finally {
            // The corrupt row itself would violate the constraint being
            // restored, so it must be removed first.
            $connection->table(self::IDEMPOTENCY_TABLE)
                ->where('tenant_id', $this->tenantA->toString())
                ->where('idempotency_key', 'key-corrupt')
                ->delete();

            $connection->statement(
                'ALTER TABLE posting_idempotency_keys '
                .'ADD CONSTRAINT posting_idempotency_keys_tenant_id_journal_id_foreign '
                .'FOREIGN KEY (tenant_id, journal_id) REFERENCES journals (tenant_id, journal_id)'
            );
        }
    }

    public function test_mapping_from_another_tenant_is_never_visible(): void
    {
        $journal = $this->persistJournal($this->tenantB, 'journal-0001', [
            $this->debitLine('account-cash-b', '100.00'),
            $this->creditLine('account-income-b', '100.00'),
        ]);
        $key = IdempotencyKey::of('key-shared');
        $this->idempotencyRepository->record($this->tenantB, $key, $journal->id());

        $command = $this->makeCommand($this->tenantA, JournalId::of('journal-0002'), $this->balancedLines(), idempotencyKey: $key);

        $decision = $this->resolver->resolve($command);

        $this->assertTrue($decision->isFirstSubmission());
    }

    /**
     * `POST-T023`: a different Idempotency Key, for the same Tenant,
     * with an otherwise identical logical request, is treated as an
     * entirely new command — not a conflict, not a replay. K1's own
     * settled mapping to J1 has no bearing at all on K2: K1 is never
     * looked up for K2, so K2 resolves purely on its own (unused)
     * existence.
     */
    public function test_same_tenant_with_a_different_idempotency_key_is_a_first_submission_unaffected_by_the_other_key(): void
    {
        $journalForK1 = $this->persistJournal($this->tenantA, 'journal-0001', $this->balancedLines());
        $keyK1 = IdempotencyKey::of('key-k1');
        $this->idempotencyRepository->record($this->tenantA, $keyK1, $journalForK1->id());

        $keyK2 = IdempotencyKey::of('key-k2');
        $command = $this->makeCommand($this->tenantA, JournalId::of('journal-0002'), $this->balancedLines(), idempotencyKey: $keyK2);

        $decision = $this->resolver->resolve($command);

        $this->assertTrue($decision->isFirstSubmission());
        $this->assertFalse($decision->isReplay());
        $this->assertNull($decision->replayedJournal());
    }

    /**
     * @return list<JournalLine>
     */
    private function balancedLines(): array
    {
        return [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ];
    }

    private function debitLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Debit);
    }

    private function creditLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Credit);
    }

    private function currencyOtherThanMyr(): Currency
    {
        $reflection = new ReflectionClass(Currency::class);
        $other = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('identifier')->setValue($other, 'XXX');
        $reflection->getProperty('scale')->setValue($other, 2);

        return $other;
    }

    /**
     * @param  list<JournalLine>  $lines
     */
    private function persistJournal(TenantId $tenantId, string $journalId, array $lines, ?\DateTimeImmutable $financialDate = null): Journal
    {
        $journal = Journal::create($tenantId, JournalId::of($journalId), $lines, $financialDate ?? $this->financialDate());
        $this->journalRepository->save($journal);

        return $journal;
    }

    /**
     * @param  list<JournalLine>  $lines
     */
    private function makeCommand(
        TenantId $tenantId,
        JournalId $journalId,
        array $lines,
        ?ActorReference $actor = null,
        ?SourceReference $source = null,
        ?SourceFingerprint $sourceFingerprint = null,
        array $evidenceReferences = [],
        ?IdempotencyKey $idempotencyKey = null,
        ?\DateTimeImmutable $financialDate = null,
    ): PostingCommand {
        return new PostingCommand(
            $idempotencyKey ?? IdempotencyKey::of('key-0001'),
            $tenantId,
            $actor ?? ActorReference::of('actor-0001'),
            $source ?? SourceReference::of('source-0001'),
            $journalId,
            $lines,
            $financialDate ?? $this->financialDate(),
            $sourceFingerprint,
            $evidenceReferences,
        );
    }

    private function financialDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-15');
    }

    private function insertAccount(TenantId $tenantId, string $accountId): void
    {
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->insert([
            'tenant_id' => $tenantId->toString(),
            'account_id' => $accountId,
            'account_code' => substr(md5($tenantId->toString().$accountId), 0, 10),
            'account_name' => 'Test Account',
            'account_type' => 'Asset',
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

        // Dependency order matters here: `posting_idempotency_keys`
        // holds a composite foreign key referencing `journals`, so
        // `accounts` and `journals`/`journal_lines` must already exist
        // before it is dropped and recreated.
        if (! Schema::connection('pgsql')->hasTable(self::ACCOUNT_TABLE)) {
            self::forceCleanMigration(self::ACCOUNTS_MIGRATION_PATH, [self::ACCOUNT_TABLE]);
        }

        if (! Schema::connection('pgsql')->hasTable(self::JOURNAL_TABLE)) {
            self::forceCleanMigration(self::JOURNAL_MIGRATION_PATH, [self::LINE_TABLE, self::JOURNAL_TABLE]);
            self::forceCleanMigration(self::CORRECTION_MIGRATION_PATH, []);
            self::forceCleanMigration(self::FINANCIAL_DATE_MIGRATION_PATH, []);
        }

        if (! Schema::connection('pgsql')->hasColumn(self::JOURNAL_TABLE, 'financial_date')) {
            self::forceCleanMigration(self::FINANCIAL_DATE_MIGRATION_PATH, []);
        }

        self::forceCleanMigration(self::IDEMPOTENCY_MIGRATION_PATH, [self::IDEMPOTENCY_TABLE]);

        self::$migrated = true;
    }

    /**
     * @param  list<string>  $tables
     */
    private static function forceCleanMigration(string $migrationPath, array $tables): void
    {
        foreach ($tables as $table) {
            Schema::connection('pgsql')->dropIfExists($table);
        }

        if (Schema::connection('pgsql')->hasTable('migrations')) {
            DB::connection('pgsql')->table('migrations')
                ->where('migration', pathinfo($migrationPath, PATHINFO_FILENAME))
                ->delete();
        }

        Artisan::call('migrate', [
            '--database' => 'pgsql',
            '--path' => $migrationPath,
            '--realpath' => false,
            '--force' => true,
        ]);
    }
}
