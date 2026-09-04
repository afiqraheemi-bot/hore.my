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

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
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
     */
    public function test_migration_rollback_succeeds_cleanly(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));

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

        // Reconcile any state left behind by a prior interrupted run
        // before migrating fresh, so this class is idempotent across
        // repeated suite runs against the same persistent database.
        Artisan::call('migrate:rollback', [
            '--database' => 'pgsql',
            '--path' => self::MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);
        Schema::connection('pgsql')->dropIfExists(self::TABLE);

        Artisan::call('migrate', [
            '--database' => 'pgsql',
            '--path' => self::MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);

        self::$migrated = true;
    }
}
