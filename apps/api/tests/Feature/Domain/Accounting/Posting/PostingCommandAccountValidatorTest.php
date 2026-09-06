<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Accounting\Posting;

use App\Domain\Accounting\ChartOfAccounts\Account;
use App\Domain\Accounting\ChartOfAccounts\AccountCode;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountName;
use App\Domain\Accounting\ChartOfAccounts\AccountOrigin;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration-level proof for `PostingCommandAccountValidator`
 * (M4-T8), exercised against a real PostgreSQL instance and the real
 * production `accounts` migration — never SQLite, mirroring the
 * precedent already established for `AccountRepositoryIntegrationTest`.
 * This validator composes `AccountRepository` directly, so its own
 * tenant-scoped lookup behavior must be proven for real, not simulated.
 *
 * Direct construction-level/behavioral coverage for `POST-T046`–
 * `POST-T053`. `POST-T047` (Account does not exist) and `POST-T048`
 * (Account belongs to a different Tenant) are both evidenced as
 * "rejected via the same `forUnresolvedAccount()` category" — see
 * {@see RejectedAccountReferenceException}'s own docblock for why
 * this is a deliberate, reported deviation from AETS-007 §18's literal
 * "each its own category" wording, not an oversight: the existing,
 * already-committed `AccountRepository::findById()` deliberately makes
 * the two cases observationally identical (`null`), to avoid ever
 * leaking cross-tenant Account existence, and this task explicitly
 * forbade introducing a new lookup to tell them apart.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class PostingCommandAccountValidatorTest extends TestCase
{
    private const ACCOUNT_TABLE = 'accounts';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private PostingCommandAccountValidator $validator;

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

        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->delete();

        $this->validator = new PostingCommandAccountValidator(new AccountRepository(DB::connection('pgsql')));
        $this->tenantA = TenantId::of('tenant-0001');
        $this->tenantB = TenantId::of('tenant-0002');
        $this->myr = Currency::of('MYR');
    }

    /**
     * (POST-T046) A command whose every referenced Account exists,
     * belongs to the command's Tenant, is Active, and is
     * posting-eligible passes Account validation.
     */
    public function test_command_with_every_account_valid_passes_validation(): void
    {
        $this->saveAccount($this->tenantA, 'account-cash');
        $this->saveAccount($this->tenantA, 'account-income');

        $this->validator->validate($this->makeCommand($this->tenantA, [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]));

        $this->addToAssertionCount(1);
    }

    /**
     * (POST-T047) An Account identifier that does not resolve to any
     * existing Account is rejected.
     */
    public function test_nonexistent_account_is_rejected(): void
    {
        $this->saveAccount($this->tenantA, 'account-income');

        $this->expectException(RejectedAccountReferenceException::class);

        $this->validator->validate($this->makeCommand($this->tenantA, [
            $this->debitLine('account-does-not-exist', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]));
    }

    /**
     * (POST-T048) An Account belonging to a different Tenant than the
     * command is rejected — via the same `forUnresolvedAccount()`
     * category as POST-T047, since `AccountRepository::findById()`
     * makes the two cases observationally identical by design (see
     * this class's own docblock).
     */
    public function test_wrong_tenant_account_is_rejected(): void
    {
        $this->saveAccount($this->tenantB, 'account-cash');
        $this->saveAccount($this->tenantA, 'account-income');

        try {
            $this->validator->validate($this->makeCommand($this->tenantA, [
                $this->debitLine('account-cash', '100.00'),
                $this->creditLine('account-income', '100.00'),
            ]));
            $this->fail('Expected RejectedAccountReferenceException.');
        } catch (RejectedAccountReferenceException $e) {
            $this->assertStringContainsString('could not be resolved', $e->getMessage());
        }
    }

    /**
     * (POST-T049) A currently Inactive Account is rejected.
     */
    public function test_inactive_account_is_rejected(): void
    {
        $inactive = $this->makeAccount($this->tenantA, 'account-cash')->deactivate();
        (new AccountRepository(DB::connection('pgsql')))->save($inactive);
        $this->saveAccount($this->tenantA, 'account-income');

        $this->expectException(RejectedAccountReferenceException::class);

        $this->validator->validate($this->makeCommand($this->tenantA, [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]));
    }

    /**
     * (POST-T050) A currently non-posting-eligible Account is
     * rejected.
     */
    public function test_non_posting_eligible_account_is_rejected(): void
    {
        $nonPostingEligible = $this->makeAccount($this->tenantA, 'account-cash', isPostingEligible: false);
        (new AccountRepository(DB::connection('pgsql')))->save($nonPostingEligible);
        $this->saveAccount($this->tenantA, 'account-income');

        $this->expectException(RejectedAccountReferenceException::class);

        $this->validator->validate($this->makeCommand($this->tenantA, [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]));
    }

    /**
     * (POST-T051) A non-posting/group Account is rejected regardless
     * of its Active/Inactive state — proven here with an Active,
     * non-posting-eligible Account, isolating the posting-eligibility
     * check from the Active check.
     */
    public function test_non_posting_group_account_is_rejected_while_still_active(): void
    {
        $groupAccount = $this->makeAccount($this->tenantA, 'account-group', isPostingEligible: false);
        (new AccountRepository(DB::connection('pgsql')))->save($groupAccount);
        $this->assertTrue($groupAccount->isActive());
        $this->saveAccount($this->tenantA, 'account-income');

        $this->expectException(RejectedAccountReferenceException::class);

        $this->validator->validate($this->makeCommand($this->tenantA, [
            $this->debitLine('account-group', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]));
    }

    /**
     * (POST-T052) A command with two proposed lines, one valid and
     * one invalid, is rejected in full — the valid line is never
     * partially accepted.
     */
    public function test_command_with_one_valid_and_one_invalid_line_is_rejected_in_full(): void
    {
        $this->saveAccount($this->tenantA, 'account-cash');

        $this->expectException(RejectedAccountReferenceException::class);

        $this->validator->validate($this->makeCommand($this->tenantA, [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-does-not-exist', '100.00'),
        ]));
    }

    /**
     * (POST-T053, Architecture) No code path in this validator
     * consults an Account's Normal Balance.
     */
    public function test_does_not_consult_normal_balance(): void
    {
        $reflection = new \ReflectionClass(PostingCommandAccountValidator::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('normalBalance', $source);
        $this->assertStringNotContainsString('NormalBalance::', $source);
    }

    /**
     * The validator only ever queries the tenant-scoped
     * `AccountRepository::findById()` — no new, tenant-unscoped
     * lookup exists that could distinguish "nonexistent" from "wrong
     * Tenant."
     */
    public function test_uses_only_the_existing_tenant_scoped_repository_lookup(): void
    {
        $reflection = new \ReflectionClass(PostingCommandAccountValidator::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringContainsString('findById(', $source);
        $this->assertStringNotContainsString('findByCode', $source);
        $this->assertStringNotContainsString('->where(', $source);
        $this->assertStringNotContainsString('DB::', $source);
    }

    private function saveAccount(TenantId $tenantId, string $accountId): void
    {
        (new AccountRepository(DB::connection('pgsql')))->save($this->makeAccount($tenantId, $accountId));
    }

    private function makeAccount(TenantId $tenantId, string $accountId, bool $isPostingEligible = true): Account
    {
        return Account::create(
            $tenantId,
            AccountId::of($accountId),
            AccountCode::of(substr(md5($tenantId->toString().$accountId), 0, 10)),
            AccountName::of('Test Account'),
            AccountType::Asset,
            $isPostingEligible,
            AccountOrigin::UserCreated,
        );
    }

    /**
     * @param  list<JournalLine>  $lines
     */
    private function makeCommand(TenantId $tenantId, array $lines): PostingCommand
    {
        return new PostingCommand(
            IdempotencyKey::of('key-0001'),
            $tenantId,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-0001'),
            JournalId::of('journal-0001'),
            $lines,
            new \DateTimeImmutable('2026-08-15'),
        );
    }

    private function debitLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Debit);
    }

    private function creditLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Credit);
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

        Schema::connection('pgsql')->dropIfExists('journal_lines');
        Schema::connection('pgsql')->dropIfExists('expenses');
        Schema::connection('pgsql')->dropIfExists('incomes');
        Schema::connection('pgsql')->dropIfExists('transfers');
        Schema::connection('pgsql')->dropIfExists('period_closures');
        Schema::connection('pgsql')->dropIfExists(self::ACCOUNT_TABLE);

        if (Schema::connection('pgsql')->hasTable('migrations')) {
            DB::connection('pgsql')->table('migrations')
                ->where('migration', pathinfo(self::ACCOUNTS_MIGRATION_PATH, PATHINFO_FILENAME))
                ->delete();
        }

        Artisan::call('migrate', [
            '--database' => 'pgsql',
            '--path' => self::ACCOUNTS_MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);

        self::$migrated = true;
    }
}
