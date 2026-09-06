<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api;

use App\Http\Controllers\Api\ExpenseController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration-level proof for M11 (ADR-0008) — the first real HTTP
 * boundary over Accounting Core. Exercises registration, login,
 * logout, tenant isolation, Account/Expense/Income creation, and every
 * Reporting endpoint through real HTTP requests against a real
 * PostgreSQL instance, never mocked.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason (mirrors the established convention).
 */
final class IdentityAndAccountingApiTest extends TestCase
{
    private const TABLES_TO_CLEAN = [
        'posting_idempotency_keys',
        'posting_source_fingerprints',
        'audit_events',
        'journal_evidence_links',
        'expenses',
        'incomes',
        'journal_lines',
        'journals',
        'accounts',
        'tenants',
        'users',
        'password_reset_tokens',
    ];

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        foreach (self::TABLES_TO_CLEAN as $table) {
            DB::connection('pgsql')->table($table)->delete();
        }

        // Sanctum's SPA cookie authentication (ADR-0008) only attaches
        // session/CSRF middleware to a request it recognizes as coming
        // from a configured "stateful" frontend origin — determined by
        // the `Origin`/`Referer` header, which a real browser sends
        // automatically but a raw test request does not.
        $this->withHeaders(['Origin' => 'http://localhost:3000']);

        // CSRF token issuance/matching (the `/sanctum/csrf-cookie`
        // handshake a real browser performs automatically) is Laravel
        // and Sanctum's own already-tested framework machinery, not
        // application code this suite owns. Sanctum's
        // `EnsureFrontendRequestsAreStateful` middleware builds its own
        // internal pipeline for "stateful" requests rather than going
        // through the router's normal middleware stack, so
        // `withoutMiddleware()` cannot reach it — this config override
        // is Sanctum's own documented mechanism for the same effect.
        config(['sanctum.middleware.validate_csrf_token' => null]);
    }

    /**
     * Leaves no residual row behind for the next test class sharing
     * this long-lived PostgreSQL instance — otherwise an `expenses`/
     * `incomes` row created by this class's own last test would still
     * reference a `journals` row after this class finishes, and a
     * sibling migration test elsewhere that does `delete from journals`
     * without first clearing those tables would fail on the foreign
     * key (the same cross-test-class contamination class of bug already
     * hardened against before, M8A and M10).
     */
    protected function tearDown(): void
    {
        if (self::$skipReason === null) {
            foreach (self::TABLES_TO_CLEAN as $table) {
                DB::connection('pgsql')->table($table)->delete();
            }
        }

        parent::tearDown();
    }

    // --- Registration -------------------------------------------------

    public function test_registration_creates_exactly_one_tenant_and_one_user(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Aina Binti Ahmad',
            'email' => 'aina@example.my',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['user' => ['id', 'name', 'email'], 'tenant' => ['id']]);

        $this->assertSame(1, DB::connection('pgsql')->table('users')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('tenants')->count());

        $userId = DB::connection('pgsql')->table('users')->value('id');
        $ownerUserId = DB::connection('pgsql')->table('tenants')->value('owner_user_id');
        $this->assertSame($userId, $ownerUserId);
    }

    public function test_duplicate_email_registration_is_rejected(): void
    {
        $this->registerAndReturnCredentials('dup@example.my');

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Second Person',
            'email' => 'dup@example.my',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, DB::connection('pgsql')->table('users')->count());
    }

    // --- Login / logout / me -------------------------------------------

    public function test_login_with_correct_credentials_establishes_a_session(): void
    {
        $this->registerAndReturnCredentials('login-ok@example.my');
        $this->logout();

        $response = $this->postJson('/api/v1/login', [
            'email' => 'login-ok@example.my',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.email', 'login-ok@example.my');
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        $this->registerAndReturnCredentials('login-bad@example.my');
        $this->logout();

        $response = $this->postJson('/api/v1/login', [
            'email' => 'login-bad@example.my',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_me_reflects_the_authenticated_user_and_tenant(): void
    {
        $this->registerAndReturnCredentials('me@example.my');

        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(200);
        $response->assertJsonPath('user.email', 'me@example.my');
        $response->assertJsonStructure(['tenant' => ['id']]);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $this->registerAndReturnCredentials('logout@example.my');

        $this->logout();

        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    // --- Unauthenticated access -----------------------------------------

    public function test_every_protected_route_rejects_an_unauthenticated_request(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->getJson('/api/v1/accounts')->assertStatus(401);
        $this->postJson('/api/v1/accounts', [])->assertStatus(401);
        $this->postJson('/api/v1/expenses', [])->assertStatus(401);
        $this->postJson('/api/v1/incomes', [])->assertStatus(401);
        $this->getJson('/api/v1/reports/trial-balance?as_of=2026-08-31')->assertStatus(401);
    }

    // --- Accounts ---------------------------------------------------------

    public function test_a_registered_tenant_can_create_and_list_its_own_accounts(): void
    {
        $this->registerAndReturnCredentials('accounts@example.my');

        $this->postJson('/api/v1/accounts', [
            'account_code' => '1000',
            'account_name' => 'Cash',
            'account_type' => 'Asset',
        ])->assertStatus(201);

        $response = $this->getJson('/api/v1/accounts');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.account_code', '1000');
        $response->assertJsonPath('data.0.account_type', 'Asset');
    }

    public function test_a_duplicate_account_code_within_the_same_tenant_is_rejected(): void
    {
        $this->registerAndReturnCredentials('dup-account@example.my');

        $this->postJson('/api/v1/accounts', [
            'account_code' => '1000',
            'account_name' => 'Cash',
            'account_type' => 'Asset',
        ])->assertStatus(201);

        $this->postJson('/api/v1/accounts', [
            'account_code' => '1000',
            'account_name' => 'Another Cash',
            'account_type' => 'Asset',
        ])->assertStatus(422);
    }

    // --- Tenant isolation ---------------------------------------------------

    public function test_a_tenants_accounts_are_never_visible_to_another_tenant(): void
    {
        $this->registerAndReturnCredentials('tenant-a@example.my');
        $this->postJson('/api/v1/accounts', [
            'account_code' => '1000',
            'account_name' => 'Tenant A Cash',
            'account_type' => 'Asset',
        ])->assertStatus(201);
        $this->logout();

        $this->registerAndReturnCredentials('tenant-b@example.my');
        $this->postJson('/api/v1/accounts', [
            'account_code' => '2000',
            'account_name' => 'Tenant B Cash',
            'account_type' => 'Asset',
        ])->assertStatus(201);

        $response = $this->getJson('/api/v1/accounts');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.account_code', '2000');
    }

    public function test_a_tenant_cannot_post_an_expense_against_another_tenants_account(): void
    {
        $this->registerAndReturnCredentials('owner-a@example.my');
        $accountAId = $this->createAccount('1000', 'Office Supplies', 'Expense');
        $cashAId = $this->createAccount('1001', 'Cash', 'Asset');
        $this->logout();

        $this->registerAndReturnCredentials('owner-b@example.my');

        $response = $this->postJson('/api/v1/expenses', [
            'amount' => '50.00',
            'transaction_date' => '2026-08-10',
            'expense_account_id' => $accountAId,
            'payment_account_id' => $cashAId,
            'description' => 'Cross-tenant attempt',
        ], ['Idempotency-Key' => 'key-cross-tenant-0001']);

        $response->assertStatus(422);
        $this->assertSame(0, DB::connection('pgsql')->table('expenses')->count());
    }

    // --- Expense / Income / Reporting end-to-end ----------------------------

    public function test_an_expense_and_income_posted_via_the_api_reconcile_through_the_reporting_endpoints(): void
    {
        $this->registerAndReturnCredentials('reconcile@example.my');

        $cashId = $this->createAccount('1000', 'Cash', 'Asset');
        $officeSuppliesId = $this->createAccount('5000', 'Office Supplies', 'Expense');
        $revenueId = $this->createAccount('4000', 'Consulting Revenue', 'Revenue');

        $expenseResponse = $this->postJson('/api/v1/expenses', [
            'amount' => '50.00',
            'transaction_date' => '2026-08-10',
            'expense_account_id' => $officeSuppliesId,
            'payment_account_id' => $cashId,
            'description' => 'Office supplies',
        ], ['Idempotency-Key' => 'key-e2e-expense-0001']);
        $expenseResponse->assertStatus(201);
        $expenseResponse->assertJsonPath('is_newly_recorded', true);

        $incomeResponse = $this->postJson('/api/v1/incomes', [
            'amount' => '200.00',
            'transaction_date' => '2026-08-15',
            'income_account_id' => $revenueId,
            'deposit_account_id' => $cashId,
            'description' => 'Consulting revenue',
        ], ['Idempotency-Key' => 'key-e2e-income-0001']);
        $incomeResponse->assertStatus(201);

        // Missing Idempotency-Key is rejected before reaching the domain.
        $this->postJson('/api/v1/expenses', [
            'amount' => '10.00',
            'transaction_date' => '2026-08-10',
            'expense_account_id' => $officeSuppliesId,
            'payment_account_id' => $cashId,
            'description' => 'No key',
        ])->assertStatus(422);

        $trialBalance = $this->getJson('/api/v1/reports/trial-balance?as_of=2026-08-31');
        $trialBalance->assertStatus(200);
        $trialBalance->assertJsonPath('is_balanced', true);
        $trialBalance->assertJsonPath('total_debit', '250.00');
        $trialBalance->assertJsonPath('total_credit', '250.00');

        $pnl = $this->getJson('/api/v1/reports/profit-and-loss?period_start=2026-08-01&period_end=2026-08-31');
        $pnl->assertStatus(200);
        $pnl->assertJsonPath('net_income', '150.00');
        $pnl->assertJsonPath('is_profit', true);

        $balanceSheet = $this->getJson('/api/v1/reports/balance-sheet?as_of=2026-08-31');
        $balanceSheet->assertStatus(200);
        $balanceSheet->assertJsonPath('is_balanced', true);
        $balanceSheet->assertJsonPath('total_assets', '150.00');

        $generalLedger = $this->getJson("/api/v1/reports/general-ledger?account_id={$cashId}&period_start=2026-08-01&period_end=2026-08-31");
        $generalLedger->assertStatus(200);
        $generalLedger->assertJsonCount(2, 'entries');
        $generalLedger->assertJsonPath('closing_balance.amount', '150.00');
        $generalLedger->assertJsonPath('closing_balance.direction', 'Debit');

        $evidenceIndex = $this->getJson('/api/v1/reports/evidence-index?period_start=2026-08-01&period_end=2026-08-31');
        $evidenceIndex->assertStatus(200);
        $evidenceIndex->assertJsonCount(2, 'entries');
    }

    // --- HTTP idempotency: a real retry must replay, never duplicate -------

    /**
     * The core retry-safety proof: a client that never saw the first
     * response (e.g. the connection dropped) must be able to resend
     * the *exact same* HTTP request — same Idempotency-Key, same body
     * — and get back the *same* authoritative result, not a rejection
     * and not a second economic effect. This exercises the real HTTP
     * boundary, not just the Domain service directly: `ExpenseId`/
     * `JournalId` are minted inside {@see ExpenseController}
     * itself on every call, so this is the only place a naive random-ID
     * bug could hide.
     */
    public function test_an_expense_retried_with_the_same_idempotency_key_replays_instead_of_duplicating(): void
    {
        $this->registerAndReturnCredentials('expense-retry@example.my');
        $cashId = $this->createAccount('1000', 'Cash', 'Asset');
        $officeSuppliesId = $this->createAccount('5000', 'Office Supplies', 'Expense');

        $payload = [
            'amount' => '50.00',
            'transaction_date' => '2026-08-10',
            'expense_account_id' => $officeSuppliesId,
            'payment_account_id' => $cashId,
            'description' => 'Office supplies',
        ];
        $headers = ['Idempotency-Key' => 'key-retry-expense-0001'];

        $first = $this->postJson('/api/v1/expenses', $payload, $headers);
        $first->assertStatus(201);
        $first->assertJsonPath('is_newly_recorded', true);

        $second = $this->postJson('/api/v1/expenses', $payload, $headers);
        $second->assertStatus(200);
        $second->assertJsonPath('is_newly_recorded', false);

        // The replay is the *same* resource and the *same* Journal —
        // not a lookalike created a second time.
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame($first->json('journal_id'), $second->json('journal_id'));

        $this->assertSame(1, DB::connection('pgsql')->table('expenses')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('journals')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('audit_events')->count());

        // No duplicate economic effect: the ledger reflects exactly one
        // RM50.00 posting, not two.
        $trialBalance = $this->getJson('/api/v1/reports/trial-balance?as_of=2026-08-31');
        $trialBalance->assertJsonPath('total_debit', '50.00');
        $trialBalance->assertJsonPath('total_credit', '50.00');
    }

    public function test_an_income_retried_with_the_same_idempotency_key_replays_instead_of_duplicating(): void
    {
        $this->registerAndReturnCredentials('income-retry@example.my');
        $cashId = $this->createAccount('1000', 'Cash', 'Asset');
        $revenueId = $this->createAccount('4000', 'Consulting Revenue', 'Revenue');

        $payload = [
            'amount' => '200.00',
            'transaction_date' => '2026-08-15',
            'income_account_id' => $revenueId,
            'deposit_account_id' => $cashId,
            'description' => 'Consulting revenue',
        ];
        $headers = ['Idempotency-Key' => 'key-retry-income-0001'];

        $first = $this->postJson('/api/v1/incomes', $payload, $headers);
        $first->assertStatus(201);

        $second = $this->postJson('/api/v1/incomes', $payload, $headers);
        $second->assertStatus(200);
        $second->assertJsonPath('is_newly_recorded', false);

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame($first->json('journal_id'), $second->json('journal_id'));

        $this->assertSame(1, DB::connection('pgsql')->table('incomes')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('journals')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('audit_events')->count());
    }

    /**
     * Reusing the same Idempotency-Key for a *materially different*
     * request (a different amount here) is a conflicting reuse, never
     * a replay — `PostingCommandLogicalEquivalence` rejects it even
     * though the derived `JournalId` is identical to the first attempt,
     * because the proposed Journal Lines themselves now disagree.
     */
    public function test_reusing_an_idempotency_key_with_a_different_amount_is_rejected_as_conflicting(): void
    {
        $this->registerAndReturnCredentials('expense-conflict@example.my');
        $cashId = $this->createAccount('1000', 'Cash', 'Asset');
        $officeSuppliesId = $this->createAccount('5000', 'Office Supplies', 'Expense');
        $headers = ['Idempotency-Key' => 'key-conflict-expense-0001'];

        $this->postJson('/api/v1/expenses', [
            'amount' => '50.00',
            'transaction_date' => '2026-08-10',
            'expense_account_id' => $officeSuppliesId,
            'payment_account_id' => $cashId,
            'description' => 'Office supplies',
        ], $headers)->assertStatus(201);

        $conflicting = $this->postJson('/api/v1/expenses', [
            'amount' => '999.00',
            'transaction_date' => '2026-08-10',
            'expense_account_id' => $officeSuppliesId,
            'payment_account_id' => $cashId,
            'description' => 'Office supplies',
        ], $headers);

        $conflicting->assertStatus(422);
        $this->assertSame(1, DB::connection('pgsql')->table('expenses')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('journals')->count());
    }

    // --- Fixtures and helpers ------------------------------------------

    private function registerAndReturnCredentials(string $email): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201);

        $this->forgetCachedAuthGuards();
    }

    private function logout(): void
    {
        $this->postJson('/api/v1/logout')->assertStatus(204);

        $this->forgetCachedAuthGuards();
    }

    /**
     * Laravel's test HTTP client reuses one Application container
     * across every call within a single test method — unlike real
     * traffic, where each request gets a fresh container. Sanctum's
     * `RequestGuard` (the `sanctum` guard) caches the User it resolved
     * on its *first* successful check for the lifetime of that cached
     * guard instance, so a later request in the same test would
     * otherwise keep seeing a now-logged-out (or since-replaced) User.
     * This is purely a test-harness artifact — production traffic never
     * shares a container across requests — so the fix belongs here, not
     * in application code.
     */
    private function forgetCachedAuthGuards(): void
    {
        Auth::forgetGuards();
    }

    private function createAccount(string $code, string $name, string $type): string
    {
        $response = $this->postJson('/api/v1/accounts', [
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
        ]);
        $response->assertStatus(201);

        /** @var string $id */
        $id = $response->json('id');

        return $id;
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

        // Every table this class's own setUp() cleans, plus `users`/
        // `tenants`, must exist — checking only `users`/`tenants` was
        // not enough: another test class earlier in a full-suite run
        // may drop and never recreate a table this class also depends
        // on (e.g. `posting_source_fingerprints`), the same class of
        // cross-test-class contamination hardened against once before
        // (M8A, `FinancialDateMigrationTest`).
        $requiredTables = [...self::TABLES_TO_CLEAN, 'users', 'tenants'];
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
