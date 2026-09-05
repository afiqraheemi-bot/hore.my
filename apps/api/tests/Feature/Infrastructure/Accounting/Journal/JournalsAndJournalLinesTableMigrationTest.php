<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Accounting\Journal;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Infrastructure\Accounting\ChartOfAccounts\AccountsTableMigrationTest;
use Tests\TestCase;

/**
 * Integration-level proof for the production `journals` and
 * `journal_lines` table migration (M3-T9,
 * `database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php`),
 * exercised against a real PostgreSQL instance and the real Laravel
 * migrator — never a hand-copied re-implementation of the schema, and
 * never SQLite as evidence of PostgreSQL-specific constraint behavior
 * (composite foreign keys, `CHECK` constraints), mirroring the
 * precedent already established for
 * {@see AccountsTableMigrationTest}.
 *
 * `accounts` is a real dependency of `journal_lines`' composite
 * foreign key, so this class also ensures the existing production
 * `accounts` migration is applied before its own — not a duplicate of
 * `AccountsTableMigrationTest`, only a prerequisite for the tests that
 * need an Account to reference.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class JournalsAndJournalLinesTableMigrationTest extends TestCase
{
    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const JOURNAL_MIGRATION_PATH = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';

    private const CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        DB::connection('pgsql')->table(self::LINE_TABLE)->delete();
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->delete();
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->delete();
    }

    // -- journals ------------------------------------------------------

    public function test_journals_table_creates_successfully(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::JOURNAL_TABLE));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns(self::JOURNAL_TABLE, ['tenant_id', 'journal_id', 'state']));
    }

    public function test_journal_id_uniqueness_is_enforced(): void
    {
        $this->insertJournal('tenant-a', 'journal-1');

        $this->expectException(QueryException::class);

        $this->insertJournal('tenant-b', 'journal-1');
    }

    public function test_draft_state_is_accepted(): void
    {
        $this->insertJournal('tenant-a', 'journal-1', 'Draft');

        $this->assertSame('Draft', DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-1')->value('state'));
    }

    public function test_posted_state_is_accepted(): void
    {
        $this->insertJournal('tenant-a', 'journal-1', 'Posted');

        $this->assertSame('Posted', DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-1')->value('state'));
    }

    public function test_unsupported_journal_state_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insertJournal('tenant-a', 'journal-1', 'NotACanonicalState');
    }

    public function test_journal_tenant_id_is_required(): void
    {
        $this->expectException(QueryException::class);

        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->insert([
            'tenant_id' => null, 'journal_id' => 'journal-1', 'state' => 'Draft',
        ]);
    }

    public function test_journal_state_is_required(): void
    {
        $this->expectException(QueryException::class);

        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->insert([
            'tenant_id' => 'tenant-a', 'journal_id' => 'journal-1', 'state' => null,
        ]);
    }

    // -- journal_lines ---------------------------------------------------

    public function test_journal_lines_table_creates_successfully(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::LINE_TABLE));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns(self::LINE_TABLE, [
            'tenant_id', 'journal_id', 'line_position', 'account_id', 'amount', 'currency', 'direction',
        ]));
    }

    public function test_valid_line_is_accepted(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');
        $this->insertJournal('tenant-a', 'journal-1');

        $this->insertLine('tenant-a', 'journal-1', 0, 'account-cash');

        $this->assertSame(1, DB::connection('pgsql')->table(self::LINE_TABLE)->count());
    }

    public function test_duplicate_line_position_within_same_journal_is_rejected(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');
        $this->insertJournal('tenant-a', 'journal-1');
        $this->insertLine('tenant-a', 'journal-1', 0, 'account-cash');

        $this->expectException(QueryException::class);

        $this->insertLine('tenant-a', 'journal-1', 0, 'account-cash', direction: 'Credit');
    }

    public function test_same_line_position_across_different_journals_is_accepted(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');
        $this->insertJournal('tenant-a', 'journal-1');
        $this->insertJournal('tenant-a', 'journal-2');

        $this->insertLine('tenant-a', 'journal-1', 0, 'account-cash');
        $this->insertLine('tenant-a', 'journal-2', 0, 'account-cash');

        $this->assertSame(2, DB::connection('pgsql')->table(self::LINE_TABLE)->where('line_position', 0)->count());
    }

    public function test_negative_line_position_is_rejected(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');
        $this->insertJournal('tenant-a', 'journal-1');

        $this->expectException(QueryException::class);

        $this->insertLine('tenant-a', 'journal-1', -1, 'account-cash');
    }

    public function test_unsupported_direction_is_rejected(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');
        $this->insertJournal('tenant-a', 'journal-1');

        $this->expectException(QueryException::class);

        $this->insertLine('tenant-a', 'journal-1', 0, 'account-cash', direction: 'NotACanonicalDirection');
    }

    public function test_line_amount_is_required(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');
        $this->insertJournal('tenant-a', 'journal-1');

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->table(self::LINE_TABLE)->insert([
            'tenant_id' => 'tenant-a', 'journal_id' => 'journal-1', 'line_position' => 0,
            'account_id' => 'account-cash', 'amount' => null, 'currency' => 'MYR', 'direction' => 'Debit',
        ]);
    }

    public function test_line_currency_is_required(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');
        $this->insertJournal('tenant-a', 'journal-1');

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->table(self::LINE_TABLE)->insert([
            'tenant_id' => 'tenant-a', 'journal_id' => 'journal-1', 'line_position' => 0,
            'account_id' => 'account-cash', 'amount' => 10000, 'currency' => null, 'direction' => 'Debit',
        ]);
    }

    public function test_journal_foreign_key_is_enforced(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');

        $this->expectException(QueryException::class);

        $this->insertLine('tenant-a', 'journal-does-not-exist', 0, 'account-cash');
    }

    public function test_account_foreign_key_is_enforced(): void
    {
        $this->insertJournal('tenant-a', 'journal-1');

        $this->expectException(QueryException::class);

        $this->insertLine('tenant-a', 'journal-1', 0, 'account-does-not-exist');
    }

    /**
     * The composite `(tenant_id, account_id)` foreign key rejects a
     * Journal Line whose declared `tenant_id` does not match the
     * Account's own Tenant — the same-Tenant Journal/Account integrity
     * this schema is designed to enforce.
     */
    public function test_cross_tenant_account_reference_is_rejected(): void
    {
        $this->insertAccount('tenant-b', 'account-cash-b');
        $this->insertJournal('tenant-a', 'journal-1');

        $this->expectException(QueryException::class);

        $this->insertLine('tenant-a', 'journal-1', 0, 'account-cash-b');
    }

    /**
     * A same-Tenant Journal/Account pairing is accepted — the positive
     * counterpart to {@see test_cross_tenant_account_reference_is_rejected()}.
     */
    public function test_journal_with_account_in_same_tenant_is_accepted(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');
        $this->insertJournal('tenant-a', 'journal-1');

        $this->insertLine('tenant-a', 'journal-1', 0, 'account-cash');

        $this->assertSame(1, DB::connection('pgsql')->table(self::LINE_TABLE)->count());
    }

    public function test_exact_bigint_amount_round_trips(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');
        $this->insertJournal('tenant-a', 'journal-1');

        $this->insertLine('tenant-a', 'journal-1', 0, 'account-cash', amount: 9223372036854775);

        $this->assertSame(
            9223372036854775,
            (int) DB::connection('pgsql')->table(self::LINE_TABLE)->where('journal_id', 'journal-1')->value('amount'),
        );
    }

    /**
     * A Journal Line's Money is a non-negative magnitude — zero is
     * deliberately still accepted (AETS-004 does not prohibit it, and
     * no minimum monetary value is invented here).
     */
    public function test_zero_amount_is_accepted(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');
        $this->insertJournal('tenant-a', 'journal-1');

        $this->insertLine('tenant-a', 'journal-1', 0, 'account-cash', amount: 0);

        $this->assertSame(0, (int) DB::connection('pgsql')->table(self::LINE_TABLE)->where('journal_id', 'journal-1')->value('amount'));
    }

    public function test_positive_amount_is_accepted(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');
        $this->insertJournal('tenant-a', 'journal-1');

        $this->insertLine('tenant-a', 'journal-1', 0, 'account-cash', amount: 10000);

        $this->assertSame(10000, (int) DB::connection('pgsql')->table(self::LINE_TABLE)->where('journal_id', 'journal-1')->value('amount'));
    }

    /**
     * A negative `amount` is rejected by the `CHECK (amount >= 0)`
     * constraint — Money remains a non-negative magnitude at the
     * database level too; Debit/Credit polarity is represented
     * exclusively by `direction`, never by the sign of `amount`.
     */
    public function test_negative_amount_is_rejected(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');
        $this->insertJournal('tenant-a', 'journal-1');

        $this->expectException(QueryException::class);

        $this->insertLine('tenant-a', 'journal-1', 0, 'account-cash', amount: -1);
    }

    public function test_debit_with_positive_magnitude_is_accepted(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');
        $this->insertJournal('tenant-a', 'journal-1');

        $this->insertLine('tenant-a', 'journal-1', 0, 'account-cash', amount: 10000, direction: 'Debit');

        $row = DB::connection('pgsql')->table(self::LINE_TABLE)->where('journal_id', 'journal-1')->first();
        $this->assertSame(10000, (int) $row->amount);
        $this->assertSame('Debit', $row->direction);
    }

    public function test_credit_with_positive_magnitude_is_accepted(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');
        $this->insertJournal('tenant-a', 'journal-1');

        $this->insertLine('tenant-a', 'journal-1', 0, 'account-cash', amount: 10000, direction: 'Credit');

        $row = DB::connection('pgsql')->table(self::LINE_TABLE)->where('journal_id', 'journal-1')->first();
        $this->assertSame(10000, (int) $row->amount);
        $this->assertSame('Credit', $row->direction);
    }

    public function test_multiple_lines_for_one_journal_are_accepted(): void
    {
        $this->insertAccount('tenant-a', 'account-cash');
        $this->insertAccount('tenant-a', 'account-income');
        $this->insertJournal('tenant-a', 'journal-1');

        $this->insertLine('tenant-a', 'journal-1', 0, 'account-cash');
        $this->insertLine('tenant-a', 'journal-1', 1, 'account-income', direction: 'Credit');

        $this->assertSame(2, DB::connection('pgsql')->table(self::LINE_TABLE)->where('journal_id', 'journal-1')->count());
    }

    public function test_journal_lines_table_has_no_signed_amount_or_balance_columns(): void
    {
        $columnNames = Schema::connection('pgsql')->getColumnListing(self::LINE_TABLE);

        foreach ($columnNames as $columnName) {
            $this->assertStringNotContainsStringIgnoringCase('signed', $columnName);
            $this->assertStringNotContainsStringIgnoringCase('balance', $columnName);
            $this->assertStringNotContainsStringIgnoringCase('debit_total', $columnName);
            $this->assertStringNotContainsStringIgnoringCase('credit_total', $columnName);
        }
        $this->assertNotEmpty($columnNames);
    }

    public function test_journals_table_has_no_balance_columns(): void
    {
        $columnNames = Schema::connection('pgsql')->getColumnListing(self::JOURNAL_TABLE);

        foreach ($columnNames as $columnName) {
            $this->assertStringNotContainsStringIgnoringCase('balance', $columnName);
            $this->assertStringNotContainsStringIgnoringCase('debit_total', $columnName);
            $this->assertStringNotContainsStringIgnoringCase('credit_total', $columnName);
            $this->assertStringNotContainsStringIgnoringCase('currency', $columnName);
        }
        $this->assertNotEmpty($columnNames);
    }

    /**
     * Proves the migration is reversible through Laravel's own
     * migrator: `migrate:rollback` actually drops both tables, and the
     * migration re-applies cleanly afterward. Restores state afterward
     * so class-level fixtures remain consistent regardless of test
     * execution order.
     *
     * **Also restores the M5 correction-chain columns.** Rolling back
     * this migration drops `journals` entirely — including the
     * `correction_type`/`corrected_journal_id` columns
     * `2026_09_06_090000_add_correction_chain_to_journals_table.php`
     * later adds to it — even though that migration's own tracking row
     * is untouched by this rollback (it targets a different migration
     * path). Re-applying the base migration alone would therefore leave
     * `journals` missing those columns for the rest of this PHPUnit
     * process, silently breaking every other test class that assumes
     * the full production schema is present. Re-running the
     * correction-chain migration immediately afterward restores it.
     *
     * **Guarantees this migration is the newest batch first.** Since
     * `ensureMigrated()` now applies the M5 correction-chain migration
     * immediately after this one (both share this real database with
     * every other test class in the same PHPUnit process), that later
     * migration — not this one — would otherwise be the newest batch by
     * the time this test runs, and `migrate:rollback --path=X`'s
     * batch-oriented semantics (documented above `forceCleanMigration()`)
     * would silently roll back nothing.
     */
    public function test_migration_rollback_succeeds_cleanly(): void
    {
        self::forceCleanMigration(self::JOURNAL_MIGRATION_PATH, [self::LINE_TABLE, self::JOURNAL_TABLE]);

        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::JOURNAL_TABLE));
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::LINE_TABLE));

        Artisan::call('migrate:rollback', [
            '--database' => 'pgsql',
            '--path' => self::JOURNAL_MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertFalse(Schema::connection('pgsql')->hasTable(self::JOURNAL_TABLE));
        $this->assertFalse(Schema::connection('pgsql')->hasTable(self::LINE_TABLE));

        Artisan::call('migrate', [
            '--database' => 'pgsql',
            '--path' => self::JOURNAL_MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::JOURNAL_TABLE));
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::LINE_TABLE));

        self::forceCleanMigration(self::CORRECTION_MIGRATION_PATH, []);
    }

    private function insertJournal(string $tenantId, string $journalId, string $state = 'Draft'): void
    {
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->insert([
            'tenant_id' => $tenantId,
            'journal_id' => $journalId,
            'state' => $state,
        ]);
    }

    private function insertLine(string $tenantId, string $journalId, int $linePosition, string $accountId, int $amount = 10000, string $currency = 'MYR', string $direction = 'Debit'): void
    {
        DB::connection('pgsql')->table(self::LINE_TABLE)->insert([
            'tenant_id' => $tenantId,
            'journal_id' => $journalId,
            'line_position' => $linePosition,
            'account_id' => $accountId,
            'amount' => $amount,
            'currency' => $currency,
            'direction' => $direction,
        ]);
    }

    private function insertAccount(string $tenantId, string $accountId): void
    {
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->insert([
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'account_code' => substr(md5($accountId), 0, 10),
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

        // Reconcile any state left behind by a prior interrupted run
        // before migrating fresh, so this class is idempotent across
        // repeated suite runs against the same persistent database.
        // `migrate:rollback --path=X` only rolls back the most recent
        // *batch*, using `--path` merely to filter which files within
        // that batch are eligible — it does not target a specific
        // migration regardless of batch. Since this migration and the
        // accounts migration are almost always applied in different
        // batches (each `Artisan::call('migrate', ['--path' => ...])`
        // call below creates its own new batch), relying on
        // `migrate:rollback` here would silently no-op whenever this
        // migration is not the latest batch. Dropping the tables
        // directly and clearing their tracking rows is unambiguous
        // regardless of batch history.
        // `posting_idempotency_keys`, `audit_events` (M6), and
        // `journal_evidence_links` (M6) each hold a composite foreign
        // key on (tenant_id, journal_id) referencing this table, so all
        // must be dropped first or PostgreSQL refuses to drop `journals`.
        // None of them is this test's concern and none is recreated
        // here.
        Schema::connection('pgsql')->dropIfExists('posting_idempotency_keys');
        Schema::connection('pgsql')->dropIfExists('posting_source_fingerprints');
        Schema::connection('pgsql')->dropIfExists('audit_events');
        Schema::connection('pgsql')->dropIfExists('journal_evidence_links');

        self::forceCleanMigration(self::JOURNAL_MIGRATION_PATH, [self::LINE_TABLE, self::JOURNAL_TABLE]);
        // Production `journals` never exists without the M5
        // correction-chain migration also applied on top of it — every
        // other test class sharing this real database within the same
        // PHPUnit process assumes that full schema is present. Applying
        // it here too keeps this class's own fixture consistent with
        // that shared reality, not just with M3-T9 in isolation.
        self::forceCleanMigration(self::CORRECTION_MIGRATION_PATH, []);

        // journal_lines' composite foreign key depends on accounts
        // already existing — ensure the production accounts migration
        // has run (idempotent: the migrator skips it if already
        // applied and the table already exists).
        if (! Schema::connection('pgsql')->hasTable(self::ACCOUNT_TABLE)) {
            self::forceCleanMigration(self::ACCOUNTS_MIGRATION_PATH, [self::ACCOUNT_TABLE]);
        }

        self::$migrated = true;
    }

    /**
     * Drops the given tables directly and clears their migration's
     * tracking row (if any), so a subsequent `migrate` call for that
     * path is guaranteed to actually (re)run it — never silently
     * skipped because the tracking table believes it is already
     * applied while the table itself does not exist. See
     * {@see ensureMigrated()} for why `migrate:rollback` cannot be
     * relied on for this reconciliation once more than one migration
     * batch exists.
     *
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
