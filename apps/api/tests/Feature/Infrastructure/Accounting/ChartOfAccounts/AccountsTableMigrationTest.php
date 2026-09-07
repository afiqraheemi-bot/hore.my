<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\AccountOrigin;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration-level proof for the production `accounts` table migration
 * (M2-T8.1, `database/migrations/2026_09_04_030000_create_accounts_table.php`),
 * exercised against a real PostgreSQL instance via the app's own `pgsql`
 * connection and the real Laravel migrator (`Artisan::call('migrate', ...)`
 * / `migrate:rollback`) — never a hand-copied re-implementation of the
 * schema, and never SQLite as evidence of PostgreSQL-specific constraint
 * behavior (composite foreign key, `CHECK` constraint), mirroring the
 * precedent already established for {@see AccountPersistenceAdapterIntegrationTest}.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql` connection
 * (e.g. `docker compose up -d postgres` has not been run — see
 * `docker-compose.yml`), every test in this class is skipped with an
 * explicit reason.
 */
final class AccountsTableMigrationTest extends TestCase
{
    private const TABLE = 'accounts';

    private const MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private const CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        if (Schema::connection('pgsql')->hasTable('payments')) {
            DB::connection('pgsql')->table('payment_allocations')->delete();
            DB::connection('pgsql')->table('payments')->delete();
        }
        if (Schema::connection('pgsql')->hasTable('invoices')) {
            DB::connection('pgsql')->table('invoice_lines')->delete();
            DB::connection('pgsql')->table('invoices')->delete();
        }
        DB::connection('pgsql')->table(self::TABLE)->delete();
    }

    public function test_migration_creates_the_accounts_table_successfully(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns(self::TABLE, [
            'tenant_id',
            'account_id',
            'account_code',
            'account_name',
            'account_type',
            'account_origin',
            'active',
            'posting_eligible',
            'parent_id',
        ]));
    }

    public function test_account_id_uniqueness_is_enforced(): void
    {
        $this->insertRow(['tenant_id' => 'tenant-a', 'account_id' => 'account-dup']);

        $this->expectException(QueryException::class);

        $this->insertRow(['tenant_id' => 'tenant-b', 'account_id' => 'account-dup', 'account_code' => '2000']);
    }

    public function test_tenant_scoped_account_code_uniqueness_is_enforced(): void
    {
        $this->insertRow(['tenant_id' => 'tenant-a', 'account_id' => 'account-a1', 'account_code' => '1000']);

        $this->expectException(QueryException::class);

        $this->insertRow(['tenant_id' => 'tenant-a', 'account_id' => 'account-a2', 'account_code' => '1000']);
    }

    public function test_same_account_code_is_permitted_for_different_tenants(): void
    {
        $this->insertRow(['tenant_id' => 'tenant-a', 'account_id' => 'account-a1', 'account_code' => '1000']);
        $this->insertRow(['tenant_id' => 'tenant-b', 'account_id' => 'account-b1', 'account_code' => '1000']);

        $this->assertSame(2, DB::connection('pgsql')->table(self::TABLE)->where('account_code', '1000')->count());
    }

    public function test_parent_id_is_nullable(): void
    {
        $this->insertRow(['tenant_id' => 'tenant-a', 'account_id' => 'account-orphan', 'parent_id' => null]);

        $this->assertNull(
            DB::connection('pgsql')->table(self::TABLE)->where('account_id', 'account-orphan')->value('parent_id'),
        );
    }

    public function test_valid_same_tenant_parent_is_accepted(): void
    {
        $this->insertRow(['tenant_id' => 'tenant-a', 'account_id' => 'account-parent', 'account_code' => '9000', 'parent_id' => null]);
        $this->insertRow(['tenant_id' => 'tenant-a', 'account_id' => 'account-child', 'account_code' => '1000', 'parent_id' => 'account-parent']);

        $this->assertSame(
            'account-parent',
            DB::connection('pgsql')->table(self::TABLE)->where('account_id', 'account-child')->value('parent_id'),
        );
    }

    public function test_cross_tenant_parent_is_rejected(): void
    {
        $this->insertRow(['tenant_id' => 'tenant-a', 'account_id' => 'account-parent', 'account_code' => '9000', 'parent_id' => null]);

        $this->expectException(QueryException::class);

        $this->insertRow(['tenant_id' => 'tenant-b', 'account_id' => 'account-cross-child', 'account_code' => '1000', 'parent_id' => 'account-parent']);
    }

    public function test_self_parent_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRow(['tenant_id' => 'tenant-a', 'account_id' => 'account-self', 'parent_id' => 'account-self']);
    }

    public function test_account_type_is_required(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRow(['tenant_id' => 'tenant-a', 'account_id' => 'account-no-type', 'account_type' => null]);
    }

    public function test_account_origin_is_required(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRow(['tenant_id' => 'tenant-a', 'account_id' => 'account-no-origin', 'account_origin' => null]);
    }

    public function test_active_is_required(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRow(['tenant_id' => 'tenant-a', 'account_id' => 'account-no-active', 'active' => null]);
    }

    public function test_posting_eligible_is_required(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRow(['tenant_id' => 'tenant-a', 'account_id' => 'account-no-posting-eligible', 'posting_eligible' => null]);
    }

    public function test_every_canonical_account_type_is_accepted(): void
    {
        foreach (AccountType::cases() as $index => $type) {
            $this->insertRow([
                'tenant_id' => 'tenant-a',
                'account_id' => 'account-type-'.$index,
                'account_code' => (string) (1000 + $index),
                'account_type' => $type->name,
            ]);
        }

        $this->assertSame(count(AccountType::cases()), DB::connection('pgsql')->table(self::TABLE)->count());
    }

    public function test_unsupported_account_type_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRow(['tenant_id' => 'tenant-a', 'account_id' => 'account-bad-type', 'account_type' => 'NotACanonicalType']);
    }

    public function test_every_canonical_account_origin_is_accepted(): void
    {
        foreach (AccountOrigin::cases() as $index => $origin) {
            $this->insertRow([
                'tenant_id' => 'tenant-a',
                'account_id' => 'account-origin-'.$index,
                'account_code' => (string) (1000 + $index),
                'account_origin' => $origin->name,
            ]);
        }

        $this->assertSame(count(AccountOrigin::cases()), DB::connection('pgsql')->table(self::TABLE)->count());
    }

    public function test_unsupported_account_origin_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRow(['tenant_id' => 'tenant-a', 'account_id' => 'account-bad-origin', 'account_origin' => 'NotACanonicalOrigin']);
    }

    public function test_accounts_table_has_no_normal_balance_column(): void
    {
        $this->assertFalse(Schema::connection('pgsql')->hasColumn(self::TABLE, 'normal_balance'));
    }

    public function test_accounts_table_has_no_monetary_balance_columns(): void
    {
        $columnNames = Schema::connection('pgsql')->getColumnListing(self::TABLE);

        foreach ($columnNames as $columnName) {
            $this->assertStringNotContainsStringIgnoringCase('balance', $columnName);
            $this->assertStringNotContainsStringIgnoringCase('debit', $columnName);
            $this->assertStringNotContainsStringIgnoringCase('credit', $columnName);
        }

        $this->assertNotEmpty($columnNames);
    }

    /**
     * Proves the migration is reversible through Laravel's own migrator,
     * not merely that its `down()` method exists — `migrate:rollback`
     * actually drops the table, and the migration can then be re-applied
     * cleanly. Restores the table afterward so class-level state remains
     * consistent regardless of test execution order.
     *
     * `migrate:rollback --path=X` only rolls back the most recent
     * *batch*, using `--path` to filter which files within that batch
     * are eligible — it does not target a specific migration
     * regardless of batch. Since journal_lines' migration (M3-T9) is
     * almost always a separate, later batch than this one, this test
     * first force-remigrates this table fresh (guaranteeing it is the
     * newest batch at the moment `migrate:rollback` is called below) —
     * otherwise the rollback call could silently no-op.
     */
    public function test_migration_rollback_succeeds_cleanly(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));

        // journal_lines (M3-T9) carries a composite foreign key onto
        // this table — PostgreSQL correctly refuses to drop `accounts`
        // while it is still referenced. Force it out of the way first
        // (not this test's own migration to manage) and restore it
        // afterward, so this test's own "restores state afterward"
        // guarantee still holds regardless of whether journal_lines
        // existed when this test started.
        $journalMigrationPath = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';
        $journalLinesExistedBefore = Schema::connection('pgsql')->hasTable('journal_lines');
        if ($journalLinesExistedBefore) {
            // `posting_idempotency_keys`, `audit_events` (M6), and
            // `journal_evidence_links` (M6) each carry a composite
            // foreign key onto `journals` too — dropped here, not
            // recreated: any test class that needs one detects its
            // absence via its own `hasTable()` guard and creates it
            // fresh, exactly as it already would on a clean database.
            Schema::connection('pgsql')->dropIfExists('posting_idempotency_keys');
            Schema::connection('pgsql')->dropIfExists('posting_source_fingerprints');
            Schema::connection('pgsql')->dropIfExists('audit_events');
            Schema::connection('pgsql')->dropIfExists('journal_evidence_links');
            self::forceCleanState($journalMigrationPath, ['journal_lines', 'journals']);
        }

        // `expenses` (M7) carries a composite foreign key directly onto
        // `accounts` — independent of whether `journal_lines` exists —
        // so it must be dropped unconditionally before `accounts`
        // itself can be dropped below. Not recreated, for the same
        // reason as the tables above.
        Schema::connection('pgsql')->dropIfExists('expenses');
        Schema::connection('pgsql')->dropIfExists('incomes');
        Schema::connection('pgsql')->dropIfExists('transfers');
        Schema::connection('pgsql')->dropIfExists('owner_equity_transactions');
        Schema::connection('pgsql')->dropIfExists('period_closures');
        Schema::connection('pgsql')->dropIfExists('reconciliation_reopenings');
        Schema::connection('pgsql')->dropIfExists('matches');
        Schema::connection('pgsql')->dropIfExists('bank_transactions');
        Schema::connection('pgsql')->dropIfExists('reconciliations');
        Schema::connection('pgsql')->dropIfExists('bank_statement_import_batches');
        Schema::connection('pgsql')->dropIfExists('bank_accounts');
        Schema::connection('pgsql')->dropIfExists('payment_allocations');
        Schema::connection('pgsql')->dropIfExists('payments');
        Schema::connection('pgsql')->dropIfExists('invoice_lines');
        Schema::connection('pgsql')->dropIfExists('invoices');

        // Guarantee this table's migration is the newest batch before
        // testing rollback against it.
        self::forceCleanState(self::MIGRATION_PATH, [self::TABLE]);

        Artisan::call('migrate:rollback', [
            '--database' => 'pgsql',
            '--path' => self::MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertFalse(Schema::connection('pgsql')->hasTable(self::TABLE));

        Artisan::call('migrate', [
            '--database' => 'pgsql',
            '--path' => self::MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));

        if ($journalLinesExistedBefore) {
            self::forceCleanState($journalMigrationPath, ['journal_lines', 'journals']);
            // Restoring `journals` via its own base migration alone
            // omits the M5 correction-chain columns
            // (`2026_09_06_090000_add_correction_chain_to_journals_table.php`)
            // that other test classes sharing this real database within
            // the same PHPUnit process assume are present — re-apply it
            // immediately so the table matches the full production
            // schema again, not just this rollback's own concern.
            self::forceCleanState(self::CORRECTION_MIGRATION_PATH, []);
        }
    }

    /**
     * Drops the given tables directly and clears the migration's own
     * tracking row (if any) before re-running it — guaranteeing both
     * that the tables actually exist afterward (never silently skipped
     * because the tracking table believes the migration already ran)
     * and that it becomes the newest migration batch, which
     * `migrate:rollback`'s batch-oriented semantics require for a
     * deterministic, unambiguous rollback target.
     *
     * @param  list<string>  $tables
     */
    private static function forceCleanState(string $migrationPath, array $tables): void
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertRow(array $overrides = []): void
    {
        DB::connection('pgsql')->table(self::TABLE)->insert(array_merge([
            'tenant_id' => 'tenant-0001',
            'account_id' => 'account-0001',
            'account_code' => '1000',
            'account_name' => 'Cash',
            'account_type' => 'Asset',
            'account_origin' => 'UserCreated',
            'active' => true,
            'posting_eligible' => true,
            'parent_id' => null,
        ], $overrides));
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

        // journal_lines (M3-T9) carries a composite foreign key onto
        // this table's (tenant_id, account_id) — if it already exists
        // from a prior test run against this same persistent database,
        // PostgreSQL correctly refuses to drop `accounts` while it is
        // still referenced. Drop it defensively first; this class owns
        // no opinion about that table's own tests, it just cannot leave
        // a downstream dependent blocking its own reconciliation. Its
        // own migration's tracking row is left alone — whichever test
        // class owns that migration reconciles it independently the
        // next time it runs.
        Schema::connection('pgsql')->dropIfExists('journal_lines');

        // `expenses` (M7) carries a composite foreign key directly onto
        // `accounts`, independent of `journal_lines` — the identical
        // reasoning as above.
        Schema::connection('pgsql')->dropIfExists('expenses');
        Schema::connection('pgsql')->dropIfExists('incomes');
        Schema::connection('pgsql')->dropIfExists('transfers');
        Schema::connection('pgsql')->dropIfExists('owner_equity_transactions');
        Schema::connection('pgsql')->dropIfExists('period_closures');
        Schema::connection('pgsql')->dropIfExists('reconciliation_reopenings');
        Schema::connection('pgsql')->dropIfExists('matches');
        Schema::connection('pgsql')->dropIfExists('bank_transactions');
        Schema::connection('pgsql')->dropIfExists('reconciliations');
        Schema::connection('pgsql')->dropIfExists('bank_statement_import_batches');
        Schema::connection('pgsql')->dropIfExists('bank_accounts');
        Schema::connection('pgsql')->dropIfExists('payment_allocations');
        Schema::connection('pgsql')->dropIfExists('payments');
        Schema::connection('pgsql')->dropIfExists('invoice_lines');
        Schema::connection('pgsql')->dropIfExists('invoices');

        // Reconcile any state left behind by a prior interrupted run
        // before migrating fresh, so this class is idempotent across
        // repeated suite runs against the same persistent database —
        // see {@see forceCleanState()} for why a plain
        // `migrate:rollback` call cannot be relied on here once more
        // than one migration batch exists.
        self::forceCleanState(self::MIGRATION_PATH, [self::TABLE]);

        self::$migrated = true;
    }
}
