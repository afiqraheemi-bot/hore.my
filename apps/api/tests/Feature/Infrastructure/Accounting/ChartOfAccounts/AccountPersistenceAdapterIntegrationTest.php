<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\Account;
use App\Domain\Accounting\ChartOfAccounts\AccountCode;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountName;
use App\Domain\Accounting\ChartOfAccounts\AccountOrigin;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountPersistenceAdapter;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Infrastructure\Accounting\Money\MoneyPersistenceAdapterIntegrationTest;
use Tests\TestCase;

/**
 * Integration-level persistence tests for {@see AccountPersistenceAdapter}
 * (M2-T6), exercised against a real PostgreSQL instance via the app's
 * own `pgsql` connection — mirroring the precedent already established
 * for Money's Persistence Adapter
 * ({@see MoneyPersistenceAdapterIntegrationTest}).
 * This suite deliberately does not run against the default `sqlite`
 * testing connection `phpunit.xml` otherwise selects, since SQLite
 * does not enforce PostgreSQL's real uniqueness-constraint semantics —
 * the whole point of the tenant-scoped Account Code uniqueness proof
 * below.
 *
 * `account_persistence_adapter_test_fixture` is a minimal, adapter-only
 * test fixture table — not a business table, not a production
 * migration, and not part of any future Chart of Accounts schema. It
 * is created and dropped by this test class itself (idempotently, via
 * `dropIfExists` + `create`), not via a committed migration file, so
 * it cannot be mistaken for real schema.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason. This suite never falls back to SQLite as
 * evidence of PostgreSQL constraint behavior.
 */
final class AccountPersistenceAdapterIntegrationTest extends TestCase
{
    private const FIXTURE_TABLE = 'account_persistence_adapter_test_fixture';

    private static ?string $skipReason = null;

    private static bool $fixtureReady = false;

    private AccountPersistenceAdapter $adapter;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = new AccountPersistenceAdapter;
        $this->tenantId = TenantId::of('tenant-0001');

        $this->ensureFixtureIsReady();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        DB::connection('pgsql')->table(self::FIXTURE_TABLE)->truncate();
    }

    /**
     * A full write-then-read round trip through a real PostgreSQL row
     * reproduces an exactly equal Account, for a representative
     * Account.
     */
    public function test_account_round_trips_through_a_real_postgres_row(): void
    {
        $original = Account::create(
            $this->tenantId,
            AccountId::of('account-cash'),
            AccountCode::of('1000'),
            AccountName::of('Cash / Bank'),
            AccountType::Asset,
            true,
            AccountOrigin::System,
        );

        $id = $this->insertFixtureRow($original);
        $reconstructed = $this->selectFixtureRow($id);

        $this->assertTrue($original->equals($reconstructed));
        $this->assertTrue($original->tenantId()->equals($reconstructed->tenantId()));
        $this->assertTrue($original->code()->equals($reconstructed->code()));
        $this->assertTrue($original->name()->equals($reconstructed->name()));
        $this->assertSame($original->type(), $reconstructed->type());
        $this->assertSame($original->normalBalance(), $reconstructed->normalBalance());
        $this->assertSame($original->isActive(), $reconstructed->isActive());
        $this->assertSame($original->isPostingEligible(), $reconstructed->isPostingEligible());
        $this->assertSame($original->origin(), $reconstructed->origin());
    }

    /**
     * Account Origin round-trips exactly through a real PostgreSQL row,
     * for every canonical origin — `System` and `UserCreated` alike.
     */
    public function test_account_origin_round_trips_through_a_real_postgres_row(): void
    {
        foreach (AccountOrigin::cases() as $origin) {
            $original = Account::create($this->tenantId, AccountId::of('account-'.strtolower($origin->name)), AccountCode::of('1000'), AccountName::of('Cash'), AccountType::Asset, true, $origin);

            $id = $this->insertFixtureRow($original);
            $reconstructed = $this->selectFixtureRow($id);

            $this->assertSame($origin, $reconstructed->origin());

            DB::connection('pgsql')->table(self::FIXTURE_TABLE)->truncate();
        }
    }

    /**
     * Tenant-scoped Account Code uniqueness is enforced at the
     * persistence layer itself — a real unique constraint, not only
     * application-level validation: a second insert with the same
     * (Tenant, Code) pair fails.
     */
    public function test_tenant_scoped_account_code_uniqueness_is_enforced(): void
    {
        $account = Account::create($this->tenantId, AccountId::of('account-a'), AccountCode::of('1000'), AccountName::of('Cash'), AccountType::Asset, true, AccountOrigin::UserCreated);
        $duplicate = Account::create($this->tenantId, AccountId::of('account-b'), AccountCode::of('1000'), AccountName::of('Cash Duplicate'), AccountType::Asset, true, AccountOrigin::UserCreated);

        $this->insertFixtureRow($account);

        $this->expectException(QueryException::class);

        $this->insertFixtureRow($duplicate);
    }

    /**
     * The same Account Code is permitted across two different
     * Tenants — the unique constraint is tenant-scoped, not global.
     */
    public function test_same_account_code_is_permitted_for_different_tenants(): void
    {
        $tenantB = TenantId::of('tenant-0002');
        $accountA = Account::create($this->tenantId, AccountId::of('account-a'), AccountCode::of('1000'), AccountName::of('Cash'), AccountType::Asset, true, AccountOrigin::UserCreated);
        $accountB = Account::create($tenantB, AccountId::of('account-b'), AccountCode::of('1000'), AccountName::of('Cash'), AccountType::Asset, true, AccountOrigin::UserCreated);

        $idA = $this->insertFixtureRow($accountA);
        $idB = $this->insertFixtureRow($accountB);

        $this->assertNotSame($idA, $idB);
        $this->assertSame(2, DB::connection('pgsql')->table(self::FIXTURE_TABLE)->where('account_code', '1000')->count());
    }

    /**
     * AccountId round-trips exactly through a real PostgreSQL row.
     */
    public function test_identifier_round_trips_through_a_real_postgres_row(): void
    {
        $accountId = AccountId::of('account-cash');
        $account = Account::create($this->tenantId, $accountId, AccountCode::of('1000'), AccountName::of('Cash'), AccountType::Asset, true, AccountOrigin::UserCreated);

        $id = $this->insertFixtureRow($account);
        $reconstructed = $this->selectFixtureRow($id);

        $this->assertTrue($accountId->equals($reconstructed->id()));
    }

    /**
     * A nullable parent reference round-trips exactly, both present
     * and absent, through a real PostgreSQL row.
     */
    public function test_nullable_parent_reference_round_trips(): void
    {
        $parent = Account::create($this->tenantId, AccountId::of('account-parent'), AccountCode::of('9000'), AccountName::of('Parent'), AccountType::Asset, false, AccountOrigin::UserCreated);
        $childWithParent = Account::create($this->tenantId, AccountId::of('account-child'), AccountCode::of('1000'), AccountName::of('Child'), AccountType::Asset, true, AccountOrigin::UserCreated)
            ->withParent($parent, [$parent]);
        $childWithoutParent = Account::create($this->tenantId, AccountId::of('account-orphan'), AccountCode::of('1100'), AccountName::of('Orphan'), AccountType::Asset, true, AccountOrigin::UserCreated);

        $idWithParent = $this->insertFixtureRow($childWithParent);
        $idWithoutParent = $this->insertFixtureRow($childWithoutParent);

        $reconstructedWithParent = $this->selectFixtureRow($idWithParent);
        $reconstructedWithoutParent = $this->selectFixtureRow($idWithoutParent);

        $this->assertNotNull($reconstructedWithParent->parentId());
        $this->assertTrue($parent->id()->equals($reconstructedWithParent->parentId()));
        $this->assertNull($reconstructedWithoutParent->parentId());
    }

    /**
     * Lifecycle (Active/Inactive) and posting-eligibility round-trip
     * through a real PostgreSQL row, including the case
     * `isPostingAllowed()` alone cannot distinguish: an Inactive
     * Account that was configured posting-eligible.
     */
    public function test_lifecycle_and_posting_eligibility_round_trip(): void
    {
        $account = Account::create($this->tenantId, AccountId::of('account-cash'), AccountCode::of('1000'), AccountName::of('Cash'), AccountType::Asset, true, AccountOrigin::UserCreated)
            ->deactivate();

        $id = $this->insertFixtureRow($account);
        $reconstructed = $this->selectFixtureRow($id);

        $this->assertFalse($reconstructed->isActive());
        $this->assertTrue($reconstructed->isPostingEligible());
        $this->assertFalse($reconstructed->isPostingAllowed());
    }

    /**
     * The fixture carries no authoritative monetary balance column —
     * confirmed directly against PostgreSQL's own catalog, not merely
     * assumed from the migration source.
     */
    public function test_fixture_has_no_authoritative_monetary_balance_column(): void
    {
        $columns = DB::connection('pgsql')->select(
            'select column_name from information_schema.columns where table_name = ?',
            [self::FIXTURE_TABLE],
        );

        $columnNames = array_map(static fn (object $column): string => (string) $column->column_name, $columns);

        foreach ($columnNames as $columnName) {
            $this->assertStringNotContainsStringIgnoringCase('balance', $columnName);
            $this->assertStringNotContainsStringIgnoringCase('debit', $columnName);
            $this->assertStringNotContainsStringIgnoringCase('credit', $columnName);
        }

        $this->assertNotEmpty($columnNames);
    }

    private function insertFixtureRow(Account $account): int
    {
        /** @var int $id */
        $id = DB::connection('pgsql')->table(self::FIXTURE_TABLE)->insertGetId(
            $this->adapter->toPersistedRow($account),
        );

        return $id;
    }

    private function selectFixtureRow(int $id): Account
    {
        /** @var object{tenant_id: string, account_id: string, account_code: string, account_name: string, account_type: string, account_origin: string, active: bool, posting_eligible: bool, parent_id: string|null} $row */
        $row = DB::connection('pgsql')->table(self::FIXTURE_TABLE)->where('id', $id)->firstOrFail();

        return $this->adapter->fromPersistedRow([
            'tenant_id' => $row->tenant_id,
            'account_id' => $row->account_id,
            'account_code' => $row->account_code,
            'account_name' => $row->account_name,
            'account_type' => $row->account_type,
            'account_origin' => $row->account_origin,
            'active' => (bool) $row->active,
            'posting_eligible' => (bool) $row->posting_eligible,
            'parent_id' => $row->parent_id,
        ]);
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

        Schema::connection('pgsql')->dropIfExists(self::FIXTURE_TABLE);
        Schema::connection('pgsql')->create(self::FIXTURE_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 64);
            $table->string('account_id', 64);
            $table->string('account_code', 64);
            $table->string('account_name', 64);
            $table->string('account_type', 32);
            $table->string('account_origin', 32);
            $table->boolean('active');
            $table->boolean('posting_eligible');
            $table->string('parent_id', 64)->nullable();
            $table->unique(['tenant_id', 'account_code']);
        });

        self::$fixtureReady = true;
    }
}
