<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api;

use App\Http\Controllers\Api\ExpenseController;
use Illuminate\Http\UploadedFile;
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
        'period_closures',
        'posting_idempotency_keys',
        'posting_source_fingerprints',
        'audit_events',
        'journal_evidence_links',
        'expenses',
        'incomes',
        'transfers',
        'owner_equity_transactions',
        'reconciliation_reopenings',
        'matches',
        'bank_transactions',
        'reconciliations',
        'bank_statement_import_batches',
        'bank_accounts',
        'invoice_lines',
        'invoices',
        'invoice_number_sequences',
        'customers',
        'journal_lines',
        'journals',
        'accounts',
        'business_profiles',
        'tenants',
        'consents',
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
            'terms_accepted' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['user' => ['id', 'name', 'email'], 'tenant' => ['id']]);

        $this->assertSame(1, DB::connection('pgsql')->table('users')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('tenants')->count());

        $userId = DB::connection('pgsql')->table('users')->value('id');
        $ownerUserId = DB::connection('pgsql')->table('tenants')->value('owner_user_id');
        $this->assertSame($userId, $ownerUserId);

        $consent = DB::connection('pgsql')->table('consents')->where('user_id', $userId)->first();
        $this->assertNotNull($consent);
        $this->assertSame('terms_of_service', $consent->consent_type);
    }

    public function test_registration_without_accepting_terms_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'No Consent',
            'email' => 'no-consent@example.my',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => false,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::connection('pgsql')->table('users')->count());
    }

    public function test_duplicate_email_registration_is_rejected(): void
    {
        $this->registerAndReturnCredentials('dup@example.my');

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Second Person',
            'email' => 'dup@example.my',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
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

    // --- Customers (M19, Modul 7 foundation) ---------------------------

    public function test_a_registered_tenant_can_create_list_and_update_its_own_customers(): void
    {
        $this->registerAndReturnCredentials('customers@example.my');

        $created = $this->postJson('/api/v1/customers', [
            'name' => 'Kedai Runcit Aminah',
            'email' => 'aminah@example.com',
            'phone' => '0123456789',
        ]);
        $created->assertStatus(201);
        $created->assertJsonPath('name', 'Kedai Runcit Aminah');
        $created->assertJsonPath('active', true);

        /** @var string $customerId */
        $customerId = $created->json('id');

        $list = $this->getJson('/api/v1/customers');
        $list->assertStatus(200);
        $list->assertJsonCount(1, 'data');
        $list->assertJsonPath('data.0.name', 'Kedai Runcit Aminah');

        $updated = $this->putJson("/api/v1/customers/{$customerId}", [
            'name' => 'Kedai Runcit Aminah Sdn Bhd',
            'email' => 'new@example.com',
            'active' => false,
        ]);
        $updated->assertStatus(200);
        $updated->assertJsonPath('name', 'Kedai Runcit Aminah Sdn Bhd');
        $updated->assertJsonPath('email', 'new@example.com');
        $updated->assertJsonPath('active', false);
    }

    public function test_registering_a_customer_with_an_empty_name_is_rejected(): void
    {
        $this->registerAndReturnCredentials('customer-empty-name@example.my');

        $this->postJson('/api/v1/customers', ['name' => ''])->assertStatus(422);
    }

    public function test_registering_a_customer_with_a_malformed_email_is_rejected(): void
    {
        $this->registerAndReturnCredentials('customer-bad-email@example.my');

        $this->postJson('/api/v1/customers', [
            'name' => 'Kedai Runcit Aminah',
            'email' => 'not-an-email',
        ])->assertStatus(422);
    }

    public function test_updating_a_nonexistent_customer_returns_404(): void
    {
        $this->registerAndReturnCredentials('customer-404@example.my');

        $this->putJson('/api/v1/customers/does-not-exist', [
            'name' => 'Somebody',
        ])->assertStatus(404);
    }

    public function test_a_tenants_customers_are_never_visible_to_another_tenant(): void
    {
        $this->registerAndReturnCredentials('customer-tenant-a@example.my');
        $this->postJson('/api/v1/customers', ['name' => 'Tenant A Customer'])->assertStatus(201);
        $this->logout();

        $this->registerAndReturnCredentials('customer-tenant-b@example.my');
        $this->postJson('/api/v1/customers', ['name' => 'Tenant B Customer'])->assertStatus(201);

        $response = $this->getJson('/api/v1/customers');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Tenant B Customer');
    }

    // --- Invoices (M20, Modul 7 phase 2) --------------------------------

    public function test_a_tenant_can_draft_edit_and_issue_an_invoice_via_the_api(): void
    {
        $this->registerAndReturnCredentials('invoices@example.my');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $draft = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
            'lines' => [
                ['description' => 'Consulting', 'quantity' => 2, 'unit_price' => '150.00'],
            ],
        ]);
        $draft->assertStatus(201);
        $draft->assertJsonPath('status', 'Draft');
        $draft->assertJsonPath('total_amount', '300.00');
        $invoiceId = $draft->json('id');

        $updated = $this->putJson("/api/v1/invoices/{$invoiceId}", [
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
            'lines' => [
                ['description' => 'Consulting', 'quantity' => 3, 'unit_price' => '150.00'],
            ],
        ]);
        $updated->assertStatus(200);
        $updated->assertJsonPath('total_amount', '450.00');

        $issued = $this->postJson("/api/v1/invoices/{$invoiceId}/issue", [], ['Idempotency-Key' => 'key-invoice-issue-0001']);
        $issued->assertStatus(201);
        $issued->assertJsonPath('status', 'Issued');
        $issued->assertJsonPath('invoice_number', 'INV-000001');
        $this->assertNotNull($issued->json('journal_id'));

        $trialBalance = $this->getJson('/api/v1/reports/trial-balance?as_of=2026-09-08');
        $trialBalance->assertStatus(200);
    }

    public function test_issuing_an_invoice_without_an_idempotency_key_is_rejected(): void
    {
        $this->registerAndReturnCredentials('invoice-no-key@example.my');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $draft = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
            'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => '10.00']],
        ]);
        $invoiceId = $draft->json('id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/issue")->assertStatus(422);
    }

    public function test_issuing_an_empty_draft_invoice_is_rejected_via_the_api(): void
    {
        $this->registerAndReturnCredentials('invoice-empty@example.my');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $draft = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
        ]);
        $draft->assertStatus(201);
        $invoiceId = $draft->json('id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/issue", [], ['Idempotency-Key' => 'key-empty-invoice'])
            ->assertStatus(422);
    }

    public function test_deleting_an_issued_invoice_is_rejected(): void
    {
        $this->registerAndReturnCredentials('invoice-delete-issued@example.my');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $draft = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
            'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => '10.00']],
        ]);
        $invoiceId = $draft->json('id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/issue", [], ['Idempotency-Key' => 'key-delete-issued'])
            ->assertStatus(201);

        $this->deleteJson("/api/v1/invoices/{$invoiceId}")->assertStatus(409);
    }

    public function test_a_draft_invoice_can_be_deleted(): void
    {
        $this->registerAndReturnCredentials('invoice-delete-draft@example.my');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $draft = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
        ]);
        $invoiceId = $draft->json('id');

        $this->deleteJson("/api/v1/invoices/{$invoiceId}")->assertStatus(204);
        $this->getJson("/api/v1/invoices/{$invoiceId}")->assertStatus(404);
    }

    public function test_a_tenants_invoices_are_never_visible_to_another_tenant(): void
    {
        $this->registerAndReturnCredentials('invoice-tenant-a@example.my');
        $receivableAId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueAId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerAId = $this->createCustomer('Tenant A Customer');
        $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerAId,
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableAId,
            'revenue_account_id' => $revenueAId,
        ])->assertStatus(201);
        $this->logout();

        $this->registerAndReturnCredentials('invoice-tenant-b@example.my');
        $response = $this->getJson('/api/v1/invoices');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
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

    // --- Transfer (M14, AETS-014) ---------------------------------------

    public function test_a_transfer_posted_via_the_api_moves_funds_between_two_accounts(): void
    {
        $this->registerAndReturnCredentials('transfer-basic@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $pettyCashId = $this->createAccount('1010', 'Petty Cash', 'Asset');

        $response = $this->postJson('/api/v1/transfers', [
            'amount' => '150.00',
            'transaction_date' => '2026-08-15',
            'source_account_id' => $bankId,
            'destination_account_id' => $pettyCashId,
            'description' => 'Top up petty cash float',
        ], ['Idempotency-Key' => 'key-transfer-0001']);

        $response->assertStatus(201);
        $response->assertJsonPath('is_newly_recorded', true);

        $this->assertSame(1, DB::connection('pgsql')->table('transfers')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('journals')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('audit_events')->count());
    }

    public function test_a_transfer_retried_with_the_same_idempotency_key_replays_instead_of_duplicating(): void
    {
        $this->registerAndReturnCredentials('transfer-retry@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $pettyCashId = $this->createAccount('1010', 'Petty Cash', 'Asset');

        $payload = [
            'amount' => '150.00',
            'transaction_date' => '2026-08-15',
            'source_account_id' => $bankId,
            'destination_account_id' => $pettyCashId,
            'description' => 'Top up petty cash float',
        ];
        $headers = ['Idempotency-Key' => 'key-retry-transfer-0001'];

        $first = $this->postJson('/api/v1/transfers', $payload, $headers);
        $first->assertStatus(201);

        $second = $this->postJson('/api/v1/transfers', $payload, $headers);
        $second->assertStatus(200);
        $second->assertJsonPath('is_newly_recorded', false);

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame($first->json('journal_id'), $second->json('journal_id'));

        $this->assertSame(1, DB::connection('pgsql')->table('transfers')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('journals')->count());
    }

    public function test_transferring_an_account_into_itself_via_the_api_is_rejected(): void
    {
        $this->registerAndReturnCredentials('transfer-same-account@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');

        $response = $this->postJson('/api/v1/transfers', [
            'amount' => '50.00',
            'transaction_date' => '2026-08-15',
            'source_account_id' => $bankId,
            'destination_account_id' => $bankId,
            'description' => 'Invalid self-transfer',
        ], ['Idempotency-Key' => 'key-transfer-self-0001']);

        $response->assertStatus(422);
        $this->assertSame(0, DB::connection('pgsql')->table('transfers')->count());
    }

    public function test_posting_a_transfer_into_an_already_closed_period_is_rejected(): void
    {
        $this->registerAndReturnCredentials('transfer-period-lock@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $pettyCashId = $this->createAccount('1010', 'Petty Cash', 'Asset');
        $officeSuppliesId = $this->createAccount('5000', 'Office Supplies', 'Expense');
        $retainedEarningsId = $this->createAccount('3900', 'Retained Earnings', 'Equity');

        $this->postJson('/api/v1/expenses', [
            'amount' => '50.00',
            'transaction_date' => '2026-07-10',
            'expense_account_id' => $officeSuppliesId,
            'payment_account_id' => $bankId,
            'description' => 'Office supplies',
        ], ['Idempotency-Key' => 'key-lock-transfer-expense-0001'])->assertStatus(201);

        $this->postJson('/api/v1/periods/close', [
            'closed_through_date' => '2026-07-31',
            'retained_earnings_account_id' => $retainedEarningsId,
        ], ['Idempotency-Key' => 'key-lock-transfer-period-0001'])->assertStatus(201);

        $backdated = $this->postJson('/api/v1/transfers', [
            'amount' => '10.00',
            'transaction_date' => '2026-07-20',
            'source_account_id' => $bankId,
            'destination_account_id' => $pettyCashId,
            'description' => 'Backdated into a closed period',
        ], ['Idempotency-Key' => 'key-lock-transfer-backdated-0001']);

        $backdated->assertStatus(422);
        $this->assertSame(0, DB::connection('pgsql')->table('transfers')->count());
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

    // --- Banking Import (M17, SRS BNK-001/BNK-003/BNK-004) ---------------

    public function test_registering_a_bank_account_and_importing_a_statement_end_to_end(): void
    {
        $this->registerAndReturnCredentials('bank-import@example.my');
        $bankAccountId = $this->createAccount('1010', 'Bank', 'Asset');

        $register = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $bankAccountId,
            'bank_name' => 'Maybank',
            'account_number_last4' => '1234',
        ]);
        $register->assertStatus(201);
        $registeredBankAccountId = $register->json('id');

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,3000.00,IN,3000.00,REF001\n"
            ."2026-08-02,Rent payment,1200.00,OUT,1800.00,REF002\n";
        $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);

        $import = $this->post("/api/v1/bank-accounts/{$registeredBankAccountId}/import", ['statement' => $file]);

        $import->assertStatus(201);
        $import->assertJsonPath('row_count', 2);
        $import->assertJsonPath('inserted_count', 2);
        $import->assertJsonPath('duplicate_count', 0);
        $import->assertJsonPath('is_new_import', true);

        $transactions = $this->getJson("/api/v1/bank-accounts/{$registeredBankAccountId}/transactions");
        $transactions->assertStatus(200);
        $transactions->assertJsonCount(2, 'data');
        $transactions->assertJsonPath('data.0.description', 'Salary credit');
        $transactions->assertJsonPath('data.0.direction', 'MoneyIn');
    }

    public function test_reimporting_the_same_statement_file_replays_instead_of_duplicating(): void
    {
        $this->registerAndReturnCredentials('bank-import-retry@example.my');
        $bankAccountId = $this->createAccount('1010', 'Bank', 'Asset');
        $registeredBankAccountId = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $bankAccountId,
            'bank_name' => 'Maybank',
        ])->json('id');

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,3000.00,IN,,\n";

        $first = $this->post("/api/v1/bank-accounts/{$registeredBankAccountId}/import", [
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
        ]);
        $first->assertStatus(201);

        $second = $this->post("/api/v1/bank-accounts/{$registeredBankAccountId}/import", [
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
        ]);
        $second->assertStatus(200);
        $second->assertJsonPath('is_new_import', false);
        $this->assertSame($first->json('id'), $second->json('id'));

        $this->assertSame(1, DB::connection('pgsql')->table('bank_statement_import_batches')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('bank_transactions')->count());
    }

    public function test_a_malformed_statement_is_rejected_via_the_api(): void
    {
        $this->registerAndReturnCredentials('bank-import-malformed@example.my');
        $bankAccountId = $this->createAccount('1010', 'Bank', 'Asset');
        $registeredBankAccountId = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $bankAccountId,
            'bank_name' => 'Maybank',
        ])->json('id');

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,not-a-number,IN,,\n";

        $response = $this->post("/api/v1/bank-accounts/{$registeredBankAccountId}/import", [
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::connection('pgsql')->table('bank_transactions')->count());
    }

    public function test_registering_a_bank_account_against_a_non_asset_account_is_rejected(): void
    {
        $this->registerAndReturnCredentials('bank-account-wrong-type@example.my');
        $revenueAccountId = $this->createAccount('4000', 'Sales Revenue', 'Revenue');

        $response = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $revenueAccountId,
            'bank_name' => 'Maybank',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::connection('pgsql')->table('bank_accounts')->count());
    }

    // --- Banking Matching & Reconciliation (M18, SRS BNK-005/006/007) ----

    public function test_a_bank_transaction_is_suggested_against_a_posted_expense_and_can_be_confirmed(): void
    {
        $this->registerAndReturnCredentials('bank-match@example.my');
        $bankLinkedAccountId = $this->createAccount('1010', 'Bank', 'Asset');
        $officeSuppliesId = $this->createAccount('5000', 'Office Supplies', 'Expense');

        $this->postJson('/api/v1/expenses', [
            'amount' => '123.45',
            'transaction_date' => '2026-08-05',
            'expense_account_id' => $officeSuppliesId,
            'payment_account_id' => $bankLinkedAccountId,
            'description' => 'Office supplies',
        ], ['Idempotency-Key' => 'key-match-expense-0001'])->assertStatus(201);

        $bankAccountId = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $bankLinkedAccountId,
            'bank_name' => 'Maybank',
        ])->json('id');

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-05,Card payment - office supplies,123.45,OUT,,\n";
        $this->post("/api/v1/bank-accounts/{$bankAccountId}/import", [
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
        ])->assertStatus(201);

        $suggestions = $this->getJson("/api/v1/bank-accounts/{$bankAccountId}/match-suggestions");
        $suggestions->assertStatus(200);
        $suggestions->assertJsonCount(1, 'data');
        $suggestions->assertJsonPath('data.0.source_type', 'Expense');

        $bankTransactionId = $suggestions->json('data.0.bank_transaction_id');
        $journalId = $suggestions->json('data.0.journal_id');

        $confirm = $this->postJson("/api/v1/bank-transactions/{$bankTransactionId}/confirm-match", [
            'journal_id' => $journalId,
        ]);
        $confirm->assertStatus(201);

        $this->assertSame(1, DB::connection('pgsql')->table('matches')->count());

        // Confirmed matches never resurface as suggestions.
        $this->getJson("/api/v1/bank-accounts/{$bankAccountId}/match-suggestions")->assertJsonCount(0, 'data');
    }

    public function test_confirming_an_already_matched_bank_transaction_is_rejected_via_the_api(): void
    {
        $this->registerAndReturnCredentials('bank-match-dup@example.my');
        $bankLinkedAccountId = $this->createAccount('1010', 'Bank', 'Asset');
        $officeSuppliesId = $this->createAccount('5000', 'Office Supplies', 'Expense');

        $this->postJson('/api/v1/expenses', [
            'amount' => '50.00',
            'transaction_date' => '2026-08-05',
            'expense_account_id' => $officeSuppliesId,
            'payment_account_id' => $bankLinkedAccountId,
            'description' => 'Office supplies',
        ], ['Idempotency-Key' => 'key-match-expense-0002'])->assertStatus(201);

        $bankAccountId = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $bankLinkedAccountId,
            'bank_name' => 'Maybank',
        ])->json('id');

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-05,Card payment,50.00,OUT,,\n";
        $this->post("/api/v1/bank-accounts/{$bankAccountId}/import", [
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
        ])->assertStatus(201);

        $suggestion = $this->getJson("/api/v1/bank-accounts/{$bankAccountId}/match-suggestions")->json('data.0');

        $this->postJson("/api/v1/bank-transactions/{$suggestion['bank_transaction_id']}/confirm-match", [
            'journal_id' => $suggestion['journal_id'],
        ])->assertStatus(201);

        $again = $this->postJson("/api/v1/bank-transactions/{$suggestion['bank_transaction_id']}/confirm-match", [
            'journal_id' => $suggestion['journal_id'],
        ]);
        $again->assertStatus(422);
        $this->assertSame(1, DB::connection('pgsql')->table('matches')->count());
    }

    public function test_reconciliation_full_lifecycle_via_the_api(): void
    {
        $this->registerAndReturnCredentials('reconciliation-lifecycle@example.my');
        $bankLinkedAccountId = $this->createAccount('1010', 'Bank', 'Asset');

        $bankAccountId = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $bankLinkedAccountId,
            'bank_name' => 'Maybank',
        ])->json('id');

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Deposit,500.00,IN,,\n";
        $this->post("/api/v1/bank-accounts/{$bankAccountId}/import", [
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
        ])->assertStatus(201);

        $open = $this->postJson("/api/v1/bank-accounts/{$bankAccountId}/reconciliations", [
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'opening_balance' => '1000.00',
            'closing_balance' => '1500.00',
        ]);
        $open->assertStatus(201);
        $open->assertJsonPath('state', 'Draft');
        $open->assertJsonPath('difference.is_zero', true);

        $reconciliationId = $open->json('id');

        $this->postJson("/api/v1/reconciliations/{$reconciliationId}/start-review")->assertJsonPath('state', 'InReview');
        $this->postJson("/api/v1/reconciliations/{$reconciliationId}/mark-balanced")->assertJsonPath('state', 'Balanced');

        $complete = $this->postJson("/api/v1/reconciliations/{$reconciliationId}/complete");
        $complete->assertStatus(200);
        $complete->assertJsonPath('state', 'Completed');
        $this->assertNotNull($complete->json('completed_at'));

        $reopen = $this->postJson("/api/v1/reconciliations/{$reconciliationId}/reopen", [
            'reason' => 'Found a missing bank fee',
        ]);
        $reopen->assertStatus(200);
        $reopen->assertJsonPath('state', 'Draft');
        $this->assertSame(1, DB::connection('pgsql')->table('reconciliation_reopenings')->count());
    }

    public function test_marking_a_reconciliation_balanced_with_a_nonzero_difference_is_rejected_via_the_api(): void
    {
        $this->registerAndReturnCredentials('reconciliation-unbalanced@example.my');
        $bankLinkedAccountId = $this->createAccount('1010', 'Bank', 'Asset');

        $bankAccountId = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $bankLinkedAccountId,
            'bank_name' => 'Maybank',
        ])->json('id');

        $open = $this->postJson("/api/v1/bank-accounts/{$bankAccountId}/reconciliations", [
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'opening_balance' => '1000.00',
            'closing_balance' => '9999.00',
        ]);
        $reconciliationId = $open->json('id');
        $this->assertFalse($open->json('difference.is_zero'));

        $this->postJson("/api/v1/reconciliations/{$reconciliationId}/start-review")->assertStatus(200);

        $response = $this->postJson("/api/v1/reconciliations/{$reconciliationId}/mark-balanced");
        $response->assertStatus(422);
    }

    // --- Business Profile & Onboarding (M16, SRS IAM-003/IAM-004) --------

    public function test_business_profile_is_absent_before_it_is_ever_saved(): void
    {
        $this->registerAndReturnCredentials('profile-absent@example.my');

        $response = $this->getJson('/api/v1/business-profile');

        $response->assertStatus(200);
        $response->assertJsonPath('data', null);
    }

    public function test_saving_a_complete_business_profile_marks_it_complete_and_provisions_a_starter_chart_of_accounts(): void
    {
        $this->registerAndReturnCredentials('profile-complete@example.my');

        $response = $this->putJson('/api/v1/business-profile', [
            'legal_name' => 'Kedai Runcit Aina',
            'registration_number' => 'SSM-0012345',
            'tin' => 'IG12345678090',
            'address_line1' => 'No. 12, Jalan Sutera',
            'city' => 'Petaling Jaya',
            'state' => 'Selangor',
            'postcode' => '46000',
            'business_type' => 'Retail',
            'financial_year_start_month' => 1,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.is_complete', true);
        $response->assertJsonPath('data.legal_name', 'Kedai Runcit Aina');
        // Regression: the schema's own DEFAULT populates `timezone`,
        // which Eloquent's create() does not automatically refresh into
        // the in-memory model without an explicit reload.
        $response->assertJsonPath('data.timezone', 'Asia/Kuala_Lumpur');

        $this->assertSame(1, DB::connection('pgsql')->table('business_profiles')->count());

        // SRS IAM-004: a starter Chart of Accounts is auto-provisioned
        // on first save.
        $accountCount = DB::connection('pgsql')->table('accounts')->count();
        $this->assertGreaterThan(0, $accountCount);
        $this->assertSame(
            $accountCount,
            DB::connection('pgsql')->table('accounts')->where('account_origin', 'System')->count(),
            'Every auto-provisioned preset Account must carry AccountOrigin::System.',
        );
    }

    public function test_saving_a_partial_business_profile_is_accepted_but_not_marked_complete(): void
    {
        $this->registerAndReturnCredentials('profile-partial@example.my');

        $response = $this->putJson('/api/v1/business-profile', [
            'legal_name' => 'Aina Trading',
            'address_line1' => 'No. 5, Jalan Mawar',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'postcode' => '40000',
            'business_type' => 'Services',
            'financial_year_start_month' => 1,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.is_complete', false);
        $response->assertJsonPath('data.registration_number', null);
    }

    public function test_updating_an_already_saved_business_profile_does_not_re_provision_accounts(): void
    {
        $this->registerAndReturnCredentials('profile-update@example.my');

        $payload = [
            'legal_name' => 'Aina Trading',
            'registration_number' => 'SSM-0099999',
            'tin' => 'IG99999999090',
            'address_line1' => 'No. 5, Jalan Mawar',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'postcode' => '40000',
            'business_type' => 'Services',
            'financial_year_start_month' => 1,
        ];

        $this->putJson('/api/v1/business-profile', $payload)->assertStatus(201);
        $accountCountAfterFirstSave = DB::connection('pgsql')->table('accounts')->count();

        $payload['legal_name'] = 'Aina Trading Sdn Bhd (renamed)';
        $second = $this->putJson('/api/v1/business-profile', $payload);

        $second->assertStatus(200);
        $second->assertJsonPath('data.legal_name', 'Aina Trading Sdn Bhd (renamed)');
        $this->assertSame(1, DB::connection('pgsql')->table('business_profiles')->count());
        $this->assertSame($accountCountAfterFirstSave, DB::connection('pgsql')->table('accounts')->count());
    }

    public function test_business_profile_provisioning_does_not_clobber_a_pre_existing_manually_created_account(): void
    {
        $this->registerAndReturnCredentials('profile-preexisting-account@example.my');
        $this->createAccount('1000', 'My Own Cash Account', 'Asset');

        $response = $this->putJson('/api/v1/business-profile', [
            'legal_name' => 'Aina Trading',
            'registration_number' => 'SSM-0011111',
            'tin' => 'IG11111111090',
            'address_line1' => 'No. 5, Jalan Mawar',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'postcode' => '40000',
            'business_type' => 'Services',
            'financial_year_start_month' => 1,
        ]);

        $response->assertStatus(201);

        // Code 1000 already existed (manually created before the
        // profile was ever saved) — provisioning must skip it rather
        // than fail the whole request.
        $this->assertSame(1, DB::connection('pgsql')->table('accounts')->where('account_code', '1000')->count());
        $this->assertSame('My Own Cash Account', DB::connection('pgsql')->table('accounts')->where('account_code', '1000')->value('account_name'));
    }

    public function test_an_invalid_state_is_rejected(): void
    {
        $this->registerAndReturnCredentials('profile-invalid-state@example.my');

        $response = $this->putJson('/api/v1/business-profile', [
            'legal_name' => 'Aina Trading',
            'address_line1' => 'No. 5, Jalan Mawar',
            'city' => 'Shah Alam',
            'state' => 'Not A Real State',
            'postcode' => '40000',
            'business_type' => 'Services',
            'financial_year_start_month' => 1,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::connection('pgsql')->table('business_profiles')->count());
    }

    // --- Owner Equity (M15) ----------------------------------------------

    public function test_a_capital_contribution_posted_via_the_api_increases_cash_and_equity(): void
    {
        $this->registerAndReturnCredentials('capital-basic@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $ownerCapitalId = $this->createAccount('3000', "Owner's Capital", 'Equity');

        $response = $this->postJson('/api/v1/capital-contributions', [
            'amount' => '5000.00',
            'transaction_date' => '2026-08-15',
            'equity_account_id' => $ownerCapitalId,
            'cash_account_id' => $bankId,
            'description' => 'Owner injected startup capital',
        ], ['Idempotency-Key' => 'key-capital-0001']);

        $response->assertStatus(201);
        $response->assertJsonPath('is_newly_recorded', true);

        $this->assertSame(1, DB::connection('pgsql')->table('owner_equity_transactions')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('journals')->count());
    }

    public function test_an_owner_drawing_posted_via_the_api_decreases_cash_and_equity(): void
    {
        $this->registerAndReturnCredentials('drawing-basic@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $ownerCapitalId = $this->createAccount('3000', "Owner's Capital", 'Equity');

        $response = $this->postJson('/api/v1/owner-drawings', [
            'amount' => '800.00',
            'transaction_date' => '2026-08-15',
            'equity_account_id' => $ownerCapitalId,
            'cash_account_id' => $bankId,
            'description' => 'Owner withdrew funds for personal use',
        ], ['Idempotency-Key' => 'key-drawing-0001']);

        $response->assertStatus(201);
        $response->assertJsonPath('is_newly_recorded', true);

        $this->assertSame(1, DB::connection('pgsql')->table('owner_equity_transactions')->count());

        $journalId = $response->json('journal_id');
        $lines = DB::connection('pgsql')->table('journal_lines')->where('journal_id', $journalId)->orderBy('line_position')->get();

        $this->assertSame('Debit', $lines[0]->direction);
        $this->assertSame($ownerCapitalId, $lines[0]->account_id);
        $this->assertSame('Credit', $lines[1]->direction);
        $this->assertSame($bankId, $lines[1]->account_id);
    }

    public function test_an_owner_drawing_retried_with_the_same_idempotency_key_replays_instead_of_duplicating(): void
    {
        $this->registerAndReturnCredentials('drawing-retry@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $ownerCapitalId = $this->createAccount('3000', "Owner's Capital", 'Equity');

        $payload = [
            'amount' => '800.00',
            'transaction_date' => '2026-08-15',
            'equity_account_id' => $ownerCapitalId,
            'cash_account_id' => $bankId,
            'description' => 'Owner withdrew funds for personal use',
        ];
        $headers = ['Idempotency-Key' => 'key-retry-drawing-0001'];

        $first = $this->postJson('/api/v1/owner-drawings', $payload, $headers);
        $first->assertStatus(201);

        $second = $this->postJson('/api/v1/owner-drawings', $payload, $headers);
        $second->assertStatus(200);
        $second->assertJsonPath('is_newly_recorded', false);

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, DB::connection('pgsql')->table('owner_equity_transactions')->count());
    }

    public function test_a_capital_contribution_against_a_non_equity_account_is_rejected(): void
    {
        $this->registerAndReturnCredentials('capital-wrong-type@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $officeSuppliesId = $this->createAccount('5000', 'Office Supplies', 'Expense');

        $response = $this->postJson('/api/v1/capital-contributions', [
            'amount' => '100.00',
            'transaction_date' => '2026-08-15',
            'equity_account_id' => $officeSuppliesId,
            'cash_account_id' => $bankId,
            'description' => 'Invalid equity account',
        ], ['Idempotency-Key' => 'key-capital-wrong-type-0001']);

        $response->assertStatus(422);
        $this->assertSame(0, DB::connection('pgsql')->table('owner_equity_transactions')->count());
    }

    // --- Period closing (M13, AETS-014) ---------------------------------

    public function test_closing_a_period_via_the_api_zeroes_revenue_and_expense_and_balances(): void
    {
        $this->registerAndReturnCredentials('period-close@example.my');
        $cashId = $this->createAccount('1000', 'Cash', 'Asset');
        $officeSuppliesId = $this->createAccount('5000', 'Office Supplies', 'Expense');
        $revenueId = $this->createAccount('4000', 'Consulting Revenue', 'Revenue');
        $retainedEarningsId = $this->createAccount('3900', 'Retained Earnings', 'Equity');

        $this->postJson('/api/v1/expenses', [
            'amount' => '50.00',
            'transaction_date' => '2026-08-10',
            'expense_account_id' => $officeSuppliesId,
            'payment_account_id' => $cashId,
            'description' => 'Office supplies',
        ], ['Idempotency-Key' => 'key-close-expense-0001'])->assertStatus(201);

        $this->postJson('/api/v1/incomes', [
            'amount' => '200.00',
            'transaction_date' => '2026-08-15',
            'income_account_id' => $revenueId,
            'deposit_account_id' => $cashId,
            'description' => 'Consulting revenue',
        ], ['Idempotency-Key' => 'key-close-income-0001'])->assertStatus(201);

        $close = $this->postJson('/api/v1/periods/close', [
            'closed_through_date' => '2026-08-31',
            'retained_earnings_account_id' => $retainedEarningsId,
        ], ['Idempotency-Key' => 'key-close-period-0001']);

        $close->assertStatus(201);
        $close->assertJsonPath('is_newly_closed', true);
        $close->assertJsonPath('closed_through_date', '2026-08-31');

        $trialBalance = $this->getJson('/api/v1/reports/trial-balance?as_of=2026-08-31');
        $trialBalance->assertStatus(200);
        $trialBalance->assertJsonPath('is_balanced', true);

        // Retry with the same Idempotency-Key replays instead of
        // rejecting or double-closing.
        $retry = $this->postJson('/api/v1/periods/close', [
            'closed_through_date' => '2026-08-31',
            'retained_earnings_account_id' => $retainedEarningsId,
        ], ['Idempotency-Key' => 'key-close-period-0001']);

        $retry->assertStatus(200);
        $retry->assertJsonPath('is_newly_closed', false);
        $this->assertSame($close->json('closing_journal_id'), $retry->json('closing_journal_id'));
        $this->assertSame(1, DB::connection('pgsql')->table('period_closures')->count());
    }

    public function test_posting_an_expense_into_an_already_closed_period_is_rejected(): void
    {
        $this->registerAndReturnCredentials('period-lock@example.my');
        $cashId = $this->createAccount('1000', 'Cash', 'Asset');
        $officeSuppliesId = $this->createAccount('5000', 'Office Supplies', 'Expense');
        $retainedEarningsId = $this->createAccount('3900', 'Retained Earnings', 'Equity');

        $this->postJson('/api/v1/expenses', [
            'amount' => '50.00',
            'transaction_date' => '2026-08-10',
            'expense_account_id' => $officeSuppliesId,
            'payment_account_id' => $cashId,
            'description' => 'Office supplies',
        ], ['Idempotency-Key' => 'key-lock-expense-0001'])->assertStatus(201);

        $this->postJson('/api/v1/periods/close', [
            'closed_through_date' => '2026-08-31',
            'retained_earnings_account_id' => $retainedEarningsId,
        ], ['Idempotency-Key' => 'key-lock-period-0001'])->assertStatus(201);

        $backdated = $this->postJson('/api/v1/expenses', [
            'amount' => '10.00',
            'transaction_date' => '2026-08-20',
            'expense_account_id' => $officeSuppliesId,
            'payment_account_id' => $cashId,
            'description' => 'Backdated into a closed period',
        ], ['Idempotency-Key' => 'key-lock-expense-backdated-0001']);

        $backdated->assertStatus(422);
        $this->assertSame(1, DB::connection('pgsql')->table('expenses')->count());
    }

    // --- Fixtures and helpers ------------------------------------------

    private function registerAndReturnCredentials(string $email): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
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

    private function createCustomer(string $name): string
    {
        $response = $this->postJson('/api/v1/customers', ['name' => $name]);
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
