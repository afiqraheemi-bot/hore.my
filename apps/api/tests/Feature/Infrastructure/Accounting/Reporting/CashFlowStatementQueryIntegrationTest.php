<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Accounting\Reporting;

use App\Domain\Accounting\Reporting\Exception\AmbiguousCashFlowClassificationException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Reporting\CashFlowStatementQuery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CleansSharedAccountingTables;
use Tests\Feature\Infrastructure\Banking\BankAccountsTableMigrationTest;
use Tests\TestCase;

/**
 * Integration-level proof for {@see CashFlowStatementQuery} (AETS-009
 * §22) that a real Posting Command cannot exercise: every command
 * this codebase currently translates produces a simple two-line
 * Journal, so the "counterparty Lines span more than one Account
 * Type" defensive check ({@see AmbiguousCashFlowClassificationException})
 * can only be proven by inserting a hand-crafted Journal directly —
 * exactly the kind of state this query must fail closed against
 * rather than silently misclassify. The ordinary golden-dataset
 * derivation itself is already proven end to end, through real
 * Posting Commands, by
 * `Tests\Feature\Http\Api\IdentityAndAccountingApiTest::test_the_cash_flow_statement_classifies_every_supported_transaction_type_correctly`.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class CashFlowStatementQueryIntegrationTest extends TestCase
{
    use CleansSharedAccountingTables;

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private CashFlowStatementQuery $query;

    private TenantId $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        self::cleanSharedAccountingTables();

        $this->tenant = TenantId::of('tenant-0001');
        $this->query = new CashFlowStatementQuery(DB::connection('pgsql'));
    }

    public function test_a_journal_with_counterparty_lines_spanning_more_than_one_account_type_throws(): void
    {
        $this->insertAccount('account-cash', 'Asset');
        $this->insertAccount('account-rent-expense', 'Expense');
        $this->insertAccount('account-loan-payable', 'Liability');
        $this->insertBankAccount('account-cash');

        // A hand-crafted three-line Journal no real command in this
        // codebase can produce: one cash-equivalent Line, plus
        // counterparty Lines of two *different* Account Types
        // (Expense and Liability) — the exact ambiguity this query
        // must refuse to silently classify.
        $this->insertJournal('journal-ambiguous-0001', '2026-09-10');
        $this->insertJournalLine('journal-ambiguous-0001', 0, 'account-cash', 'Debit', '15000');
        $this->insertJournalLine('journal-ambiguous-0001', 1, 'account-rent-expense', 'Credit', '10000');
        $this->insertJournalLine('journal-ambiguous-0001', 2, 'account-loan-payable', 'Credit', '5000');

        $this->expectException(AmbiguousCashFlowClassificationException::class);

        $this->query->forPeriod($this->tenant, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));
    }

    public function test_a_tenant_with_no_registered_bank_account_gets_an_entirely_empty_statement(): void
    {
        $this->insertAccount('account-cash', 'Asset');
        // Deliberately no bank_accounts row — this Account is never
        // treated as cash-equivalent, so it can never appear as a
        // "cash line" no matter how much Posted activity touches it.
        $this->insertAccount('account-revenue', 'Revenue');
        $this->insertJournal('journal-0001', '2026-09-10');
        $this->insertJournalLine('journal-0001', 0, 'account-cash', 'Debit', '10000');
        $this->insertJournalLine('journal-0001', 1, 'account-revenue', 'Credit', '10000');

        $statement = $this->query->forPeriod($this->tenant, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));

        $this->assertSame([], $statement->operatingLines());
        $this->assertTrue($statement->cashAtPeriodEnd()->isZero());
    }

    private function insertAccount(string $accountId, string $accountType): void
    {
        DB::connection('pgsql')->table('accounts')->insert([
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

    private function insertBankAccount(string $linkedAccountId): void
    {
        DB::connection('pgsql')->table('bank_accounts')->insert([
            'id' => sprintf('bank-account-%s', substr(md5($linkedAccountId), 0, 10)),
            'tenant_id' => $this->tenant->toString(),
            'linked_account_id' => $linkedAccountId,
            'bank_name' => 'Test Bank',
            'account_number_last4' => null,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertJournal(string $journalId, string $financialDate): void
    {
        DB::connection('pgsql')->table('journals')->insert([
            'tenant_id' => $this->tenant->toString(),
            'journal_id' => $journalId,
            'state' => 'Posted',
            'correction_type' => null,
            'corrected_journal_id' => null,
            'financial_date' => $financialDate,
            'posted_at' => now(),
        ]);
    }

    private function insertJournalLine(string $journalId, int $linePosition, string $accountId, string $direction, string $amount): void
    {
        DB::connection('pgsql')->table('journal_lines')->insert([
            'tenant_id' => $this->tenant->toString(),
            'journal_id' => $journalId,
            'line_position' => $linePosition,
            'account_id' => $accountId,
            'amount' => $amount,
            'currency' => 'MYR',
            'direction' => $direction,
        ]);
    }

    /**
     * Unlike every other Integration test's own identical-looking
     * `ensureMigrated()`, this one deliberately does **not** cache a
     * missing-table result in `self::$migrated`/`$skipReason` beyond
     * the one-time "is PostgreSQL reachable at all" check: `bank_accounts`
     * specifically is intentionally, temporarily dropped and
     * recreated by {@see BankAccountsTableMigrationTest}'s
     * own migration round-trip proof elsewhere in the same PHPUnit
     * process — a static "not migrated" cache taken at the wrong
     * moment would permanently, silently skip every test in this
     * class for the rest of that run even though the table exists
     * again moments later. Every other required table here is never
     * intentionally dropped by any test, so re-checking their
     * existence on every run costs one cheap query and buys real
     * correctness.
     */
    private function ensureMigrated(): void
    {
        if (self::$skipReason !== null) {
            return;
        }

        if (! self::$migrated) {
            try {
                DB::connection('pgsql')->select('select 1');
                self::$migrated = true;
            } catch (\Throwable $e) {
                self::$skipReason = sprintf(
                    'A real PostgreSQL instance is not reachable via the "pgsql" connection (%s). '
                    .'Run `docker compose up -d postgres` (see docker-compose.yml) to enable this integration test.',
                    $e->getMessage(),
                );

                return;
            }
        }

        $requiredTables = ['accounts', 'bank_accounts', 'journals', 'journal_lines'];

        // The migration that created each required table, keyed the
        // same way — used only for the self-heal below.
        $migrationPathByTable = [
            'bank_accounts' => 'database/migrations/2026_09_07_090000_create_bank_accounts_table.php',
        ];

        foreach ($requiredTables as $table) {
            if (Schema::connection('pgsql')->hasTable($table)) {
                continue;
            }

            $migrationPath = $migrationPathByTable[$table] ?? null;

            if ($migrationPath === null) {
                continue;
            }

            // Self-healing: `AccountsTableMigrationTest::ensureMigrated()`
            // unconditionally drops `bank_accounts` as a dependent of
            // `accounts` without ever recreating it or clearing its
            // `migrations` tracking row — so a plain `artisan migrate`
            // believes that migration already ran and skips it.
            // Clearing the tracking row first, then re-running just
            // that migration, is the same `forceCleanState()` /
            // `forceCleanMigration()` pattern already established by
            // the migration-round-trip test classes in this suite.
            DB::connection('pgsql')->table('migrations')
                ->where('migration', pathinfo($migrationPath, PATHINFO_FILENAME))
                ->delete();

            Artisan::call('migrate', [
                '--database' => 'pgsql',
                '--path' => $migrationPath,
                '--realpath' => false,
                '--force' => true,
            ]);
        }

        foreach ($requiredTables as $table) {
            if (! Schema::connection('pgsql')->hasTable($table)) {
                $this->markTestSkipped(sprintf('Required table "%s" does not exist right now — run migrations against the "pgsql" connection.', $table));
            }
        }
    }
}
