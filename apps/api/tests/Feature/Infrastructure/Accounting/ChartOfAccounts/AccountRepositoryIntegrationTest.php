<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\Account;
use App\Domain\Accounting\ChartOfAccounts\AccountCode;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountName;
use App\Domain\Accounting\ChartOfAccounts\AccountOrigin;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountNameException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use App\Infrastructure\Accounting\ChartOfAccounts\Exception\DuplicateAccountCodeException;
use App\Infrastructure\Accounting\ChartOfAccounts\Exception\ImmutableAccountStateException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration-level proof for {@see AccountRepository} (M2-T8.2),
 * exercised against a real PostgreSQL instance and the real production
 * `accounts` migration — never a hand-copied re-implementation of the
 * schema, and never SQLite as evidence of uniqueness or tenant
 * isolation, mirroring the precedent already established for
 * {@see AccountPersistenceAdapterIntegrationTest} and
 * {@see AccountsTableMigrationTest}.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class AccountRepositoryIntegrationTest extends TestCase
{
    private const TABLE = 'accounts';

    private const MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private AccountRepository $repository;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        DB::connection('pgsql')->table(self::TABLE)->delete();

        $this->repository = new AccountRepository(DB::connection('pgsql'));
        $this->tenantId = TenantId::of('tenant-0001');
    }

    public function test_save_and_find_by_id_exact_round_trip(): void
    {
        $account = $this->makeAccount(accountId: 'account-cash', accountCode: '1000');

        $this->repository->save($account);
        $found = $this->repository->findById($this->tenantId, AccountId::of('account-cash'));

        $this->assertNotNull($found);
        $this->assertTrue($account->equals($found));
        $this->assertTrue($account->tenantId()->equals($found->tenantId()));
        $this->assertTrue($account->code()->equals($found->code()));
        $this->assertTrue($account->name()->equals($found->name()));
        $this->assertSame($account->type(), $found->type());
        $this->assertSame($account->normalBalance(), $found->normalBalance());
        $this->assertSame($account->origin(), $found->origin());
        $this->assertSame($account->isActive(), $found->isActive());
        $this->assertSame($account->isPostingEligible(), $found->isPostingEligible());
        $this->assertNull($found->parentId());
    }

    public function test_save_and_find_by_code_exact_round_trip(): void
    {
        $account = $this->makeAccount(accountId: 'account-cash', accountCode: '1000');

        $this->repository->save($account);
        $found = $this->repository->findByCode($this->tenantId, AccountCode::of('1000'));

        $this->assertNotNull($found);
        $this->assertTrue($account->equals($found));
    }

    public function test_find_by_id_returns_null_when_absent(): void
    {
        $this->assertNull($this->repository->findById($this->tenantId, AccountId::of('does-not-exist')));
    }

    public function test_find_by_code_returns_null_when_absent(): void
    {
        $this->assertNull($this->repository->findByCode($this->tenantId, AccountCode::of('9999')));
    }

    public function test_code_exists_is_true_and_false(): void
    {
        $this->assertFalse($this->repository->codeExists($this->tenantId, AccountCode::of('1000')));

        $this->repository->save($this->makeAccount(accountId: 'account-cash', accountCode: '1000'));

        $this->assertTrue($this->repository->codeExists($this->tenantId, AccountCode::of('1000')));
    }

    public function test_tenant_isolation_for_find_by_id(): void
    {
        $tenantB = TenantId::of('tenant-0002');
        $account = $this->makeAccount(tenantId: $tenantB, accountId: 'account-cash', accountCode: '1000');

        $this->repository->save($account);

        $this->assertNull($this->repository->findById($this->tenantId, AccountId::of('account-cash')));
        $this->assertNotNull($this->repository->findById($tenantB, AccountId::of('account-cash')));
    }

    public function test_tenant_isolation_for_find_by_code(): void
    {
        $tenantB = TenantId::of('tenant-0002');
        $account = $this->makeAccount(tenantId: $tenantB, accountId: 'account-cash', accountCode: '1000');

        $this->repository->save($account);

        $this->assertNull($this->repository->findByCode($this->tenantId, AccountCode::of('1000')));
        $this->assertNotNull($this->repository->findByCode($tenantB, AccountCode::of('1000')));
    }

    public function test_tenant_isolation_for_code_exists(): void
    {
        $tenantB = TenantId::of('tenant-0002');
        $this->repository->save($this->makeAccount(tenantId: $tenantB, accountId: 'account-cash', accountCode: '1000'));

        $this->assertFalse($this->repository->codeExists($this->tenantId, AccountCode::of('1000')));
        $this->assertTrue($this->repository->codeExists($tenantB, AccountCode::of('1000')));
    }

    public function test_same_account_code_is_permitted_for_different_tenants(): void
    {
        $tenantB = TenantId::of('tenant-0002');

        $this->repository->save($this->makeAccount(tenantId: $this->tenantId, accountId: 'account-a', accountCode: '1000'));
        $this->repository->save($this->makeAccount(tenantId: $tenantB, accountId: 'account-b', accountCode: '1000'));

        $this->assertNotNull($this->repository->findByCode($this->tenantId, AccountCode::of('1000')));
        $this->assertNotNull($this->repository->findByCode($tenantB, AccountCode::of('1000')));
    }

    public function test_duplicate_account_code_is_rejected_within_the_same_tenant(): void
    {
        $this->repository->save($this->makeAccount(accountId: 'account-a', accountCode: '1000'));

        $this->expectException(DuplicateAccountCodeException::class);

        $this->repository->save($this->makeAccount(accountId: 'account-b', accountCode: '1000'));
    }

    /**
     * COA-T056: uniqueness is origin-agnostic at the database
     * constraint level — a User-Created Account cannot use the same
     * Account Code as an existing System Account in the same Tenant,
     * and the rejection surfaces through the same
     * {@see DuplicateAccountCodeException} as any other collision.
     */
    public function test_user_created_account_cannot_collide_with_a_system_account_code_in_the_same_tenant(): void
    {
        $systemAccount = $this->makeAccount(accountId: 'account-system', accountCode: '9000', origin: AccountOrigin::System);
        $this->repository->save($systemAccount);

        $userCreatedAccount = $this->makeAccount(accountId: 'account-user', accountCode: '9000', origin: AccountOrigin::UserCreated);

        $this->expectException(DuplicateAccountCodeException::class);

        $this->repository->save($userCreatedAccount);
    }

    public function test_account_origin_round_trips_for_every_origin(): void
    {
        foreach (AccountOrigin::cases() as $index => $origin) {
            $account = $this->makeAccount(accountId: 'account-origin-'.$index, accountCode: (string) (1000 + $index), origin: $origin);

            $this->repository->save($account);
            $found = $this->repository->findById($this->tenantId, AccountId::of('account-origin-'.$index));

            $this->assertNotNull($found);
            $this->assertSame($origin, $found->origin());
        }
    }

    public function test_active_inactive_round_trips_exactly(): void
    {
        $active = $this->makeAccount(accountId: 'account-active', accountCode: '1000');
        $inactive = $this->makeAccount(accountId: 'account-inactive', accountCode: '1100')->deactivate();

        $this->repository->save($active);
        $this->repository->save($inactive);

        $this->assertTrue($this->repository->findById($this->tenantId, AccountId::of('account-active'))->isActive());
        $this->assertFalse($this->repository->findById($this->tenantId, AccountId::of('account-inactive'))->isActive());
    }

    public function test_posting_eligibility_round_trips_exactly(): void
    {
        $eligible = $this->makeAccount(accountId: 'account-eligible', accountCode: '1000', isPostingEligible: true);
        $nonPosting = $this->makeAccount(accountId: 'account-non-posting', accountCode: '1100', isPostingEligible: false);

        $this->repository->save($eligible);
        $this->repository->save($nonPosting);

        $this->assertTrue($this->repository->findById($this->tenantId, AccountId::of('account-eligible'))->isPostingEligible());
        $this->assertFalse($this->repository->findById($this->tenantId, AccountId::of('account-non-posting'))->isPostingEligible());
    }

    public function test_nullable_parent_id_round_trips_exactly(): void
    {
        $parent = $this->makeAccount(accountId: 'account-parent', accountCode: '9000', isPostingEligible: false);
        $this->repository->save($parent);

        $child = $this->makeAccount(accountId: 'account-child', accountCode: '1000')->withParent($parent, [$parent]);
        $this->repository->save($child);

        $orphan = $this->makeAccount(accountId: 'account-orphan', accountCode: '1100');
        $this->repository->save($orphan);

        $foundChild = $this->repository->findById($this->tenantId, AccountId::of('account-child'));
        $foundOrphan = $this->repository->findById($this->tenantId, AccountId::of('account-orphan'));

        $this->assertNotNull($foundChild->parentId());
        $this->assertTrue($parent->id()->equals($foundChild->parentId()));
        $this->assertNull($foundOrphan->parentId());
    }

    /**
     * A `deactivate()`d Account re-saved under the same identifier
     * updates the existing row's `active` state — the update path, not
     * a fresh insert.
     */
    public function test_valid_deactivate_state_can_be_saved_as_an_update(): void
    {
        $account = $this->makeAccount(accountId: 'account-cash', accountCode: '1000');
        $this->repository->save($account);
        $this->assertSame(1, DB::connection('pgsql')->table(self::TABLE)->count());

        $this->repository->save($account->deactivate());

        $this->assertSame(1, DB::connection('pgsql')->table(self::TABLE)->count());
        $this->assertFalse($this->repository->findById($this->tenantId, AccountId::of('account-cash'))->isActive());
    }

    /**
     * An Account assigned a parent via `withParent()` and re-saved
     * under the same identifier updates the existing row's `parent_id`
     * — the update path, not a fresh insert.
     */
    public function test_valid_parent_assignment_can_be_saved_as_an_update(): void
    {
        $parent = $this->makeAccount(accountId: 'account-parent', accountCode: '9000', isPostingEligible: false);
        $this->repository->save($parent);

        $child = $this->makeAccount(accountId: 'account-child', accountCode: '1000');
        $this->repository->save($child);
        $this->assertNull($this->repository->findById($this->tenantId, AccountId::of('account-child'))->parentId());

        $this->repository->save($child->withParent($parent, [$parent]));

        $this->assertSame(2, DB::connection('pgsql')->table(self::TABLE)->count());
        $updatedChild = $this->repository->findById($this->tenantId, AccountId::of('account-child'));
        $this->assertNotNull($updatedChild->parentId());
        $this->assertTrue($parent->id()->equals($updatedChild->parentId()));
    }

    public function test_attempted_tenant_id_change_for_existing_account_id_is_rejected(): void
    {
        $original = $this->makeAccount(accountId: 'account-x', accountCode: '1000');
        $this->repository->save($original);
        $before = $this->fetchRawRow('account-x');

        $conflicting = Account::create(
            TenantId::of('tenant-9999'),
            AccountId::of('account-x'),
            AccountCode::of('1000'),
            AccountName::of('Cash / Bank'),
            AccountType::Asset,
            true,
            AccountOrigin::UserCreated,
        );

        $this->assertRejectedWithoutMutatingRow($conflicting, 'account-x', $before);
    }

    public function test_attempted_account_code_change_for_existing_account_id_is_rejected(): void
    {
        $original = $this->makeAccount(accountId: 'account-x', accountCode: '1000');
        $this->repository->save($original);
        $before = $this->fetchRawRow('account-x');

        $conflicting = $this->makeAccount(accountId: 'account-x', accountCode: '2000');

        $this->assertRejectedWithoutMutatingRow($conflicting, 'account-x', $before);
    }

    public function test_attempted_account_name_change_for_existing_account_id_is_rejected(): void
    {
        $original = $this->makeAccount(accountId: 'account-x', accountCode: '1000');
        $this->repository->save($original);
        $before = $this->fetchRawRow('account-x');

        $conflicting = Account::create(
            $this->tenantId,
            AccountId::of('account-x'),
            AccountCode::of('1000'),
            AccountName::of('Renamed Cash'),
            AccountType::Asset,
            true,
            AccountOrigin::UserCreated,
        );

        $this->assertRejectedWithoutMutatingRow($conflicting, 'account-x', $before);
    }

    public function test_attempted_account_type_change_for_existing_account_id_is_rejected(): void
    {
        $original = $this->makeAccount(accountId: 'account-x', accountCode: '1000', type: AccountType::Asset);
        $this->repository->save($original);
        $before = $this->fetchRawRow('account-x');

        $conflicting = $this->makeAccount(accountId: 'account-x', accountCode: '1000', type: AccountType::Liability);

        $this->assertRejectedWithoutMutatingRow($conflicting, 'account-x', $before);
    }

    public function test_attempted_account_origin_change_for_existing_account_id_is_rejected(): void
    {
        $original = $this->makeAccount(accountId: 'account-x', accountCode: '1000', origin: AccountOrigin::UserCreated);
        $this->repository->save($original);
        $before = $this->fetchRawRow('account-x');

        $conflicting = $this->makeAccount(accountId: 'account-x', accountCode: '1000', origin: AccountOrigin::System);

        $this->assertRejectedWithoutMutatingRow($conflicting, 'account-x', $before);
    }

    /**
     * Two competing writes for the same identifier must not silently
     * overwrite immutable state: a second connection's `save()` blocks
     * on the first connection's uncommitted row lock (`SELECT ... FOR
     * UPDATE` inside a transaction), rather than reading stale state
     * and racing past the immutable-field check. Proven with two real,
     * independent PostgreSQL connections — not simulated in-process.
     */
    public function test_concurrent_conflicting_writes_do_not_silently_overwrite_immutable_state(): void
    {
        $original = $this->makeAccount(accountId: 'account-concurrent', accountCode: '1000');
        $this->repository->save($original);
        $before = $this->fetchRawRow('account-concurrent');

        config(['database.connections.pgsql_secondary' => config('database.connections.pgsql')]);
        DB::purge('pgsql_secondary');
        $secondConnection = DB::connection('pgsql_secondary');
        $secondConnection->statement("set lock_timeout = '200ms'");
        $secondConnection->statement("set statement_timeout = '2000ms'");
        $secondRepository = new AccountRepository($secondConnection);

        $firstConnection = DB::connection('pgsql');
        $firstConnection->beginTransaction();
        $firstConnection->table(self::TABLE)
            ->where('account_id', 'account-concurrent')
            ->lockForUpdate()
            ->first();

        $conflicting = $this->makeAccount(accountId: 'account-concurrent', accountCode: '9999');

        try {
            $secondRepository->save($conflicting);
            $this->fail('Expected the concurrent save() to block on the row lock and then fail.');
        } catch (QueryException $e) {
            // Expected: the second connection could not acquire the row
            // lock within its lock_timeout, proving it was genuinely
            // blocked by the first connection's open transaction rather
            // than racing past it.
        } finally {
            $firstConnection->rollBack();
            DB::purge('pgsql_secondary');
        }

        $this->assertSame($before, $this->fetchRawRow('account-concurrent'));
    }

    /**
     * A row that satisfies every database-level constraint can still
     * carry a value one of the Value Objects' own validation rejects
     * (here, an empty Account Name — `NOT NULL` allows `''`, but
     * {@see AccountName::of()} does not). The repository must not
     * swallow that rejection: it propagates from
     * {@see AccountPersistenceAdapter::fromPersistedRow()} unmodified.
     */
    public function test_malformed_persisted_data_is_still_rejected_by_the_adapter_during_read(): void
    {
        DB::connection('pgsql')->table(self::TABLE)->insert([
            'tenant_id' => $this->tenantId->toString(),
            'account_id' => 'account-malformed',
            'account_code' => '1000',
            'account_name' => '',
            'account_type' => 'Asset',
            'account_origin' => 'UserCreated',
            'active' => true,
            'posting_eligible' => true,
            'parent_id' => null,
        ]);

        $this->expectException(InvalidAccountNameException::class);

        $this->repository->findById($this->tenantId, AccountId::of('account-malformed'));
    }

    public function test_no_delete_api_exists(): void
    {
        $reflection = new \ReflectionClass(AccountRepository::class);
        $publicMethodNames = array_map(
            static fn (\ReflectionMethod $method): string => strtolower($method->getName()),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        foreach (['delete', 'remove', 'destroy', 'purge'] as $forbiddenMethodName) {
            $this->assertNotContains($forbiddenMethodName, $publicMethodNames);
        }
    }

    /**
     * @param  array<string, mixed>  $expectedUnchangedRow
     */
    private function assertRejectedWithoutMutatingRow(Account $conflicting, string $accountId, array $expectedUnchangedRow): void
    {
        try {
            $this->repository->save($conflicting);
            $this->fail('Expected ImmutableAccountStateException to be thrown.');
        } catch (ImmutableAccountStateException $e) {
            // expected
        }

        $this->assertSame($expectedUnchangedRow, $this->fetchRawRow($accountId));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRawRow(string $accountId): array
    {
        /** @var object $row */
        $row = DB::connection('pgsql')->table(self::TABLE)->where('account_id', $accountId)->firstOrFail();

        return (array) $row;
    }

    private function makeAccount(
        ?TenantId $tenantId = null,
        string $accountId = 'account-0001',
        string $accountCode = '1000',
        AccountType $type = AccountType::Asset,
        bool $isPostingEligible = true,
        AccountOrigin $origin = AccountOrigin::UserCreated,
    ): Account {
        return Account::create(
            $tenantId ?? $this->tenantId,
            AccountId::of($accountId),
            AccountCode::of($accountCode),
            AccountName::of('Cash / Bank'),
            $type,
            $isPostingEligible,
            $origin,
        );
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

        // Reconcile any state left behind by a prior interrupted run
        // before migrating fresh, so this class is idempotent across
        // repeated suite runs against the same persistent database.
        // `migrate:rollback --path=X` only rolls back the most recent
        // *batch*, using `--path` merely to filter which files within
        // that batch are eligible — it does not target a specific
        // migration regardless of batch. Once any later migration
        // (e.g. M3-T9's journals/journal_lines migration) has run in a
        // separate batch, this table's own migration is no longer the
        // last batch, and `migrate:rollback` here would silently
        // no-op. Dropping the table directly and clearing its tracking
        // row is unambiguous regardless of batch history.
        Schema::connection('pgsql')->dropIfExists(self::TABLE);

        if (Schema::connection('pgsql')->hasTable('migrations')) {
            DB::connection('pgsql')->table('migrations')
                ->where('migration', pathinfo(self::MIGRATION_PATH, PATHINFO_FILENAME))
                ->delete();
        }

        Artisan::call('migrate', [
            '--database' => 'pgsql',
            '--path' => self::MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);

        self::$migrated = true;
    }
}
