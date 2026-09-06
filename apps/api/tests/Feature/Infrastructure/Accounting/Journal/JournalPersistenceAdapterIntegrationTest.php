<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Accounting\Journal;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Journal\JournalState;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Journal\Exception\InvalidPersistedJournalDirectionException;
use App\Infrastructure\Accounting\Journal\Exception\InvalidPersistedJournalStateException;
use App\Infrastructure\Accounting\Journal\JournalPersistenceAdapter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Infrastructure\Accounting\ChartOfAccounts\AccountPersistenceAdapterIntegrationTest;
use Tests\TestCase;

/**
 * Integration-level persistence tests for {@see JournalPersistenceAdapter}
 * (M3-T8), exercised against a real PostgreSQL instance via the app's
 * own `pgsql` connection — mirroring the precedent already established
 * for Account's own Persistence Adapter
 * ({@see AccountPersistenceAdapterIntegrationTest}).
 * This suite deliberately does not run against the default `sqlite`
 * testing connection `phpunit.xml` otherwise selects, since SQLite
 * does not enforce PostgreSQL's real `BIGINT` precision the way this
 * adapter's exact-minor-units mapping depends on.
 *
 * Both fixture tables below are minimal, adapter-only test fixtures —
 * not business tables, not a production migration, and not part of
 * any future Journal schema decision (line identity/order for the
 * real schema is explicitly deferred, per this task's own scope). They
 * are created and dropped by this test class itself, not via a
 * committed migration file, so they cannot be mistaken for real
 * schema.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason. This suite never falls back to SQLite as
 * evidence of PostgreSQL precision/constraint behavior.
 */
final class JournalPersistenceAdapterIntegrationTest extends TestCase
{
    private const JOURNAL_TABLE = 'journal_persistence_adapter_test_fixture';

    private const LINE_TABLE = 'journal_line_persistence_adapter_test_fixture';

    private static ?string $skipReason = null;

    private static bool $fixtureReady = false;

    private JournalPersistenceAdapter $adapter;

    private Currency $myr;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = new JournalPersistenceAdapter;
        $this->myr = Currency::of('MYR');
        $this->tenantId = TenantId::of('tenant-0001');

        $this->ensureFixtureIsReady();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        DB::connection('pgsql')->table(self::LINE_TABLE)->truncate();
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->truncate();
    }

    public function test_draft_journal_round_trips_through_a_real_postgres_row(): void
    {
        $journal = $this->makeJournal(state: JournalState::Draft);

        $this->insertJournal($journal);
        $reconstructed = $this->selectJournal('journal-0001');

        $this->assertTrue($journal->equals($reconstructed));
        $this->assertSame(JournalState::Draft, $reconstructed->state());
    }

    public function test_posted_journal_round_trips_through_a_real_postgres_row(): void
    {
        $journal = $this->makeJournal(state: JournalState::Posted);

        $this->insertJournal($journal);
        $reconstructed = $this->selectJournal('journal-0001');

        $this->assertSame(JournalState::Posted, $reconstructed->state());
    }

    public function test_exact_line_order_round_trips_through_a_real_postgres_row(): void
    {
        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-a', '10.00'),
            $this->debitLine('account-b', '20.00'),
            $this->creditLine('account-c', '30.00'),
        ], $this->financialDate());

        $this->insertJournal($journal);
        $reconstructed = $this->selectJournal('journal-0001');

        $accountIds = array_map(
            static fn (JournalLine $line): string => $line->accountId()->toString(),
            $reconstructed->lines(),
        );
        $this->assertSame(['account-a', 'account-b', 'account-c'], $accountIds);
    }

    /**
     * A large exact `BIGINT` amount round-trips through real
     * PostgreSQL with no precision loss — proof this specifically
     * requires a real database, since a PHP-only double could silently
     * lose precision at this magnitude if float were ever involved.
     */
    public function test_exact_bigint_amount_round_trips_with_no_precision_loss(): void
    {
        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '92233720368547.75'),
            $this->creditLine('account-income', '92233720368547.75'),
        ], $this->financialDate());

        $this->insertJournal($journal);
        $reconstructed = $this->selectJournal('journal-0001');

        $this->assertSame('92233720368547.75', $reconstructed->lines()[0]->money()->toDecimalString());
        $this->assertSame('92233720368547.75', $reconstructed->lines()[1]->money()->toDecimalString());
    }

    public function test_direction_round_trips_through_a_real_postgres_row(): void
    {
        $journal = $this->makeJournal();

        $this->insertJournal($journal);
        $reconstructed = $this->selectJournal('journal-0001');

        $this->assertSame(JournalDirection::Debit, $reconstructed->lines()[0]->direction());
        $this->assertSame(JournalDirection::Credit, $reconstructed->lines()[1]->direction());
    }

    public function test_state_round_trips_through_a_real_postgres_row(): void
    {
        $draft = $this->makeJournal(id: JournalId::of('journal-draft'), state: JournalState::Draft);
        $posted = $this->makeJournal(id: JournalId::of('journal-posted'), state: JournalState::Posted);

        $this->insertJournal($draft);
        $this->insertJournal($posted);

        $this->assertSame(JournalState::Draft, $this->selectJournal('journal-draft')->state());
        $this->assertSame(JournalState::Posted, $this->selectJournal('journal-posted')->state());
    }

    public function test_multiple_lines_round_trip_through_a_real_postgres_row(): void
    {
        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '60.00'),
            $this->debitLine('account-vat-input', '5.00'),
            $this->creditLine('account-income', '40.00'),
            $this->creditLine('account-income-2', '25.00'),
        ], $this->financialDate());

        $this->insertJournal($journal);
        $reconstructed = $this->selectJournal('journal-0001');

        $this->assertCount(4, $reconstructed->lines());
        $this->assertTrue($reconstructed->isBalanced());
        $this->assertTrue($journal->equals($reconstructed));
    }

    /**
     * (M8) `financial_date` and `posted_at` round-trip exactly through
     * a real PostgreSQL row — `financial_date` present for both Draft
     * and Posted, `posted_at` `null` for Draft and a real timestamp for
     * Posted.
     */
    public function test_financial_date_and_posted_at_round_trip_through_a_real_postgres_row(): void
    {
        $draft = $this->makeJournal(id: JournalId::of('journal-draft-date'), state: JournalState::Draft);
        $posted = $this->makeJournal(id: JournalId::of('journal-posted-date'), state: JournalState::Posted);

        $this->insertJournal($draft);
        $this->insertJournal($posted);

        $reconstructedDraft = $this->selectJournal('journal-draft-date');
        $reconstructedPosted = $this->selectJournal('journal-posted-date');

        $this->assertSame('2026-08-15', $reconstructedDraft->financialDate()->format('Y-m-d'));
        $this->assertNull($reconstructedDraft->postedAt());

        $this->assertSame('2026-08-15', $reconstructedPosted->financialDate()->format('Y-m-d'));
        $this->assertSame('2026-09-06 10:00:00', $reconstructedPosted->postedAt()?->format('Y-m-d H:i:s'));
    }

    public function test_invalid_persisted_state_is_rejected_on_read(): void
    {
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->insert([
            'tenant_id' => 'tenant-0001',
            'journal_id' => 'journal-0001',
            'state' => 'NotACanonicalState',
            'financial_date' => '2026-08-15',
        ]);
        DB::connection('pgsql')->table(self::LINE_TABLE)->insert($this->twoBalancedLineRows());

        $this->expectException(InvalidPersistedJournalStateException::class);

        $this->selectJournal('journal-0001');
    }

    public function test_invalid_persisted_direction_is_rejected_on_read(): void
    {
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->insert([
            'tenant_id' => 'tenant-0001',
            'journal_id' => 'journal-0001',
            'state' => 'Draft',
            'financial_date' => '2026-08-15',
        ]);
        $lines = $this->twoBalancedLineRows();
        $lines[0]['direction'] = 'NotACanonicalDirection';
        DB::connection('pgsql')->table(self::LINE_TABLE)->insert($lines);

        $this->expectException(InvalidPersistedJournalDirectionException::class);

        $this->selectJournal('journal-0001');
    }

    private function makeJournal(?TenantId $tenantId = null, ?JournalId $id = null, JournalState $state = JournalState::Draft): Journal
    {
        $journal = Journal::create($tenantId ?? $this->tenantId, $id ?? JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ], $this->financialDate());

        return $state === JournalState::Posted ? $journal->post($this->postedAt()) : $journal;
    }

    private function financialDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-15');
    }

    private function postedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-06 10:00:00');
    }

    private function debitLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Debit);
    }

    private function creditLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Credit);
    }

    /**
     * @return list<array{journal_id: string, line_position: int, account_id: string, amount: string, currency: string, direction: string}>
     */
    private function twoBalancedLineRows(): array
    {
        return [
            ['journal_id' => 'journal-0001', 'line_position' => 0, 'account_id' => 'account-cash', 'amount' => '10000', 'currency' => 'MYR', 'direction' => 'Debit'],
            ['journal_id' => 'journal-0001', 'line_position' => 1, 'account_id' => 'account-income', 'amount' => '10000', 'currency' => 'MYR', 'direction' => 'Credit'],
        ];
    }

    private function insertJournal(Journal $journal): void
    {
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->insert($this->adapter->toPersistedHeader($journal));
        DB::connection('pgsql')->table(self::LINE_TABLE)->insert($this->adapter->toPersistedLines($journal));
    }

    private function selectJournal(string $journalId): Journal
    {
        /** @var object{tenant_id: string, journal_id: string, state: string, correction_type: string|null, corrected_journal_id: string|null, financial_date: string, posted_at: string|null} $headerRow */
        $headerRow = DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', $journalId)->firstOrFail();

        /** @var Collection<int, object{journal_id: string, line_position: int, account_id: string, amount: int|string, currency: string, direction: string}> $lineRows */
        $lineRows = DB::connection('pgsql')->table(self::LINE_TABLE)->where('journal_id', $journalId)->get();

        $header = [
            'tenant_id' => $headerRow->tenant_id,
            'journal_id' => $headerRow->journal_id,
            'state' => $headerRow->state,
            'correction_type' => $headerRow->correction_type,
            'corrected_journal_id' => $headerRow->corrected_journal_id,
            'financial_date' => $headerRow->financial_date,
            'posted_at' => $headerRow->posted_at,
        ];

        $lines = $lineRows->map(static fn (object $row): array => [
            'journal_id' => $row->journal_id,
            'line_position' => (int) $row->line_position,
            'account_id' => $row->account_id,
            'amount' => (string) $row->amount,
            'currency' => $row->currency,
            'direction' => $row->direction,
        ])->all();

        return $this->adapter->fromPersistedJournal($header, $lines);
    }

    private function ensureFixtureIsReady(): void
    {
        if (self::$skipReason !== null || self::$fixtureReady) {
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

        Schema::connection('pgsql')->dropIfExists(self::LINE_TABLE);
        Schema::connection('pgsql')->dropIfExists(self::JOURNAL_TABLE);

        Schema::connection('pgsql')->create(self::JOURNAL_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 64);
            $table->string('journal_id', 64);
            $table->string('state', 32);
            $table->string('correction_type', 32)->nullable();
            $table->string('corrected_journal_id', 64)->nullable();
            $table->date('financial_date');
            $table->timestamp('posted_at')->nullable();
        });

        Schema::connection('pgsql')->create(self::LINE_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('journal_id', 64);
            $table->integer('line_position');
            $table->string('account_id', 64);
            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->string('direction', 32);
        });

        self::$fixtureReady = true;
    }
}
