<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api;

use App\Domain\Banking\MaybankPdfBankStatementParser;
use App\Domain\Banking\XlsxBankStatementParser;
use App\Http\Controllers\Api\ExpenseController;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
        'evidence',
        'payment_allocations',
        'payments',
        'period_closures',
        'posting_idempotency_keys',
        'posting_source_fingerprints',
        'audit_events',
        'journal_evidence_links',
        'expenses',
        'incomes',
        'transfers',
        'owner_equity_transactions',
        'task_transitions',
        'proposals',
        'task_drafts',
        'tasks',
        'reconciliation_completion_snapshots',
        'reconciliation_reopenings',
        'matches',
        'bank_transactions',
        'reconciliations',
        'bank_statement_import_batches',
        'bank_accounts',
        'quotation_lines',
        'quotations',
        'quotation_number_sequences',
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

        Storage::fake('local');

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
        $publicRoutes = ['api/v1/register', 'api/v1/login'];
        $tenantlessAuthenticatedRoutes = ['api/v1/logout', 'api/v1/me'];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || in_array($route->uri(), $publicRoutes, true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, $route->uri().' must require authentication.');

            if (! in_array($route->uri(), $tenantlessAuthenticatedRoutes, true)) {
                $this->assertContains('tenant.resolved', $middleware, $route->uri().' must resolve its Tenant.');
            }
        }

        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->getJson('/api/v1/accounts')->assertStatus(401);
        $this->postJson('/api/v1/accounts', [])->assertStatus(401);
        $this->postJson('/api/v1/expenses', [])->assertStatus(401);
        $this->postJson('/api/v1/incomes', [])->assertStatus(401);
        $this->postJson('/api/v1/tasks/task-without-session/approve')->assertStatus(401);
        $this->getJson('/api/v1/dashboard')->assertStatus(401);
        $this->getJson('/api/v1/periods/current')->assertStatus(401);
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
        $response->assertJsonPath('data.0.account_origin', 'UserCreated');
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

        $issued = $this->postJson("/api/v1/invoices/{$invoiceId}/issue", ['issue_date' => now()->toDateString()], ['Idempotency-Key' => 'key-invoice-issue-0001']);
        $issued->assertStatus(201);
        $issued->assertJsonPath('status', 'Issued');
        $issued->assertJsonPath('invoice_number', 'INV-000001');
        $this->assertNotNull($issued->json('journal_id'));

        $trialBalance = $this->getJson('/api/v1/reports/trial-balance?as_of='.now()->toDateString());
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

        $this->postJson("/api/v1/invoices/{$invoiceId}/issue", ['issue_date' => now()->toDateString()])->assertStatus(422);
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

        $this->postJson("/api/v1/invoices/{$invoiceId}/issue", ['issue_date' => now()->toDateString()], ['Idempotency-Key' => 'key-empty-invoice'])
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

        $this->postJson("/api/v1/invoices/{$invoiceId}/issue", ['issue_date' => now()->toDateString()], ['Idempotency-Key' => 'key-delete-issued'])
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

    /**
     * AETS-017: a minimal PDF renders for both a Draft (a preview,
     * labelled "DRAFT") and an Issued Invoice.
     */
    public function test_an_invoice_pdf_can_be_downloaded_for_a_draft_and_an_issued_invoice(): void
    {
        $this->registerAndReturnCredentials('invoice-pdf@example.my');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $draft = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
            'lines' => [['description' => 'Consulting', 'quantity' => 2, 'unit_price' => '150.00']],
        ]);
        $invoiceId = $draft->json('id');

        $draftPdf = $this->get("/api/v1/invoices/{$invoiceId}/pdf");
        $draftPdf->assertStatus(200);
        $draftPdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $draftPdf->getContent());

        $this->postJson("/api/v1/invoices/{$invoiceId}/issue", ['issue_date' => now()->toDateString()], ['Idempotency-Key' => 'key-invoice-pdf-issue'])
            ->assertStatus(201);

        $issuedPdf = $this->get("/api/v1/invoices/{$invoiceId}/pdf");
        $issuedPdf->assertStatus(200);
        $this->assertStringContainsString('attachment; filename="INV-000001.pdf"', $issuedPdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $issuedPdf->getContent());
    }

    public function test_an_invoice_pdf_is_not_downloadable_by_another_tenant(): void
    {
        $this->registerAndReturnCredentials('invoice-pdf-tenant-a@example.my');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Tenant A Customer');
        $draft = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
        ]);
        $invoiceId = $draft->json('id');
        $this->logout();

        $this->registerAndReturnCredentials('invoice-pdf-tenant-b@example.my');
        $this->get("/api/v1/invoices/{$invoiceId}/pdf")->assertStatus(404);
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

    // --- Quotations (AETS-016) -------------------------------------------

    public function test_a_tenant_can_draft_send_accept_and_convert_a_quotation_into_an_invoice_via_the_api(): void
    {
        $this->registerAndReturnCredentials('quotations@example.my');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $draft = $this->postJson('/api/v1/quotations', [
            'customer_id' => $customerId,
            'valid_until' => '2026-12-31',
            'lines' => [
                ['description' => 'Consulting', 'quantity' => 2, 'unit_price' => '150.00'],
            ],
        ]);
        $draft->assertStatus(201);
        $draft->assertJsonPath('status', 'Draft');
        $draft->assertJsonPath('total_amount', '300.00');
        $quotationId = $draft->json('id');

        $sent = $this->postJson("/api/v1/quotations/{$quotationId}/send", ['issue_date' => now()->toDateString()]);
        $sent->assertStatus(200);
        $sent->assertJsonPath('status', 'Sent');
        $sent->assertJsonPath('quotation_number', 'QUO-000001');

        $accepted = $this->postJson("/api/v1/quotations/{$quotationId}/accept");
        $accepted->assertStatus(200);
        $accepted->assertJsonPath('status', 'Accepted');

        $converted = $this->postJson("/api/v1/quotations/{$quotationId}/convert-to-invoice", [
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
            'due_date' => '2026-12-31',
        ]);
        $converted->assertStatus(201);
        $converted->assertJsonPath('status', 'Draft');
        $converted->assertJsonPath('total_amount', '300.00');
        $invoiceId = $converted->json('id');
        $this->assertNotNull($invoiceId);

        $reloadedQuotation = $this->getJson("/api/v1/quotations/{$quotationId}");
        $reloadedQuotation->assertJsonPath('status', 'Converted');
        $reloadedQuotation->assertJsonPath('converted_invoice_id', $invoiceId);

        // The resulting Invoice is fully ordinary from here on — it
        // issues exactly like any other Invoice.
        $issued = $this->postJson("/api/v1/invoices/{$invoiceId}/issue", ['issue_date' => now()->toDateString()], ['Idempotency-Key' => 'key-quotation-convert-issue']);
        $issued->assertStatus(201);
        $issued->assertJsonPath('status', 'Issued');
    }

    public function test_converting_a_quotation_that_has_not_been_accepted_is_rejected(): void
    {
        $this->registerAndReturnCredentials('quotation-not-accepted@example.my');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $draft = $this->postJson('/api/v1/quotations', [
            'customer_id' => $customerId,
            'valid_until' => '2026-12-31',
            'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => '10.00']],
        ]);
        $quotationId = $draft->json('id');

        $this->postJson("/api/v1/quotations/{$quotationId}/convert-to-invoice", [
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
            'due_date' => '2026-12-31',
        ])->assertStatus(422);
    }

    public function test_sending_an_empty_quotation_is_rejected_via_the_api(): void
    {
        $this->registerAndReturnCredentials('quotation-empty@example.my');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $draft = $this->postJson('/api/v1/quotations', [
            'customer_id' => $customerId,
            'valid_until' => '2026-12-31',
        ]);
        $draft->assertStatus(201);
        $quotationId = $draft->json('id');

        $this->postJson("/api/v1/quotations/{$quotationId}/send", ['issue_date' => now()->toDateString()])
            ->assertStatus(422);
    }

    public function test_rejecting_a_sent_quotation_via_the_api(): void
    {
        $this->registerAndReturnCredentials('quotation-reject@example.my');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $draft = $this->postJson('/api/v1/quotations', [
            'customer_id' => $customerId,
            'valid_until' => '2026-12-31',
            'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => '10.00']],
        ]);
        $quotationId = $draft->json('id');
        $this->postJson("/api/v1/quotations/{$quotationId}/send", ['issue_date' => now()->toDateString()])->assertStatus(200);

        $rejected = $this->postJson("/api/v1/quotations/{$quotationId}/reject");
        $rejected->assertStatus(200);
        $rejected->assertJsonPath('status', 'Rejected');
    }

    public function test_deleting_a_sent_quotation_is_rejected(): void
    {
        $this->registerAndReturnCredentials('quotation-delete-sent@example.my');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $draft = $this->postJson('/api/v1/quotations', [
            'customer_id' => $customerId,
            'valid_until' => '2026-12-31',
            'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => '10.00']],
        ]);
        $quotationId = $draft->json('id');
        $this->postJson("/api/v1/quotations/{$quotationId}/send", ['issue_date' => now()->toDateString()])->assertStatus(200);

        $this->deleteJson("/api/v1/quotations/{$quotationId}")->assertStatus(409);
    }

    public function test_a_draft_quotation_can_be_deleted(): void
    {
        $this->registerAndReturnCredentials('quotation-delete-draft@example.my');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $draft = $this->postJson('/api/v1/quotations', [
            'customer_id' => $customerId,
            'valid_until' => '2026-12-31',
        ]);
        $quotationId = $draft->json('id');

        $this->deleteJson("/api/v1/quotations/{$quotationId}")->assertStatus(204);
        $this->getJson("/api/v1/quotations/{$quotationId}")->assertStatus(404);
    }

    /**
     * AETS-017: a minimal PDF renders for both a Draft (labelled
     * "DRAFT") and a Sent Quotation.
     */
    public function test_a_quotation_pdf_can_be_downloaded_for_a_draft_and_a_sent_quotation(): void
    {
        $this->registerAndReturnCredentials('quotation-pdf@example.my');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $draft = $this->postJson('/api/v1/quotations', [
            'customer_id' => $customerId,
            'valid_until' => '2026-12-31',
            'lines' => [['description' => 'Consulting', 'quantity' => 2, 'unit_price' => '150.00']],
        ]);
        $quotationId = $draft->json('id');

        $draftPdf = $this->get("/api/v1/quotations/{$quotationId}/pdf");
        $draftPdf->assertStatus(200);
        $draftPdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $draftPdf->getContent());

        $this->postJson("/api/v1/quotations/{$quotationId}/send", ['issue_date' => now()->toDateString()])->assertStatus(200);

        $sentPdf = $this->get("/api/v1/quotations/{$quotationId}/pdf");
        $sentPdf->assertStatus(200);
        $this->assertStringContainsString('attachment; filename="QUO-000001.pdf"', $sentPdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $sentPdf->getContent());
    }

    public function test_a_quotation_pdf_is_not_downloadable_by_another_tenant(): void
    {
        $this->registerAndReturnCredentials('quotation-pdf-tenant-a@example.my');
        $customerId = $this->createCustomer('Tenant A Customer');
        $draft = $this->postJson('/api/v1/quotations', [
            'customer_id' => $customerId,
            'valid_until' => '2026-12-31',
        ]);
        $quotationId = $draft->json('id');
        $this->logout();

        $this->registerAndReturnCredentials('quotation-pdf-tenant-b@example.my');
        $this->get("/api/v1/quotations/{$quotationId}/pdf")->assertStatus(404);
    }

    public function test_a_tenants_quotations_are_never_visible_to_another_tenant(): void
    {
        $this->registerAndReturnCredentials('quotation-tenant-a@example.my');
        $customerAId = $this->createCustomer('Tenant A Customer');
        $this->postJson('/api/v1/quotations', [
            'customer_id' => $customerAId,
            'valid_until' => '2026-12-31',
        ])->assertStatus(201);
        $this->logout();

        $this->registerAndReturnCredentials('quotation-tenant-b@example.my');
        $response = $this->getJson('/api/v1/quotations');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    // --- Payments & Allocation (M21, Modul 7 phase 3) -------------------

    public function test_a_tenant_can_record_a_payment_and_allocate_it_to_an_issued_invoice(): void
    {
        $this->registerAndReturnCredentials('payments@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $invoiceId = $this->issueInvoice($customerId, $receivableId, $revenueId, '300.00');

        $paymentResponse = $this->postJson('/api/v1/payments', [
            'customer_id' => $customerId,
            'amount' => '300.00',
            'payment_date' => '2026-09-08',
            'deposit_account_id' => $bankId,
            'receivable_account_id' => $receivableId,
            'reference' => 'REF-001',
        ], ['Idempotency-Key' => 'key-payment-0001']);
        $paymentResponse->assertStatus(201);
        $paymentResponse->assertJsonPath('unallocated_amount', '300.00');
        $paymentId = $paymentResponse->json('id');

        $allocationResponse = $this->postJson("/api/v1/payments/{$paymentId}/allocations", [
            'invoice_id' => $invoiceId,
            'amount' => '300.00',
        ]);
        $allocationResponse->assertStatus(201);

        $paymentAfter = $this->getJson("/api/v1/payments/{$paymentId}");
        $paymentAfter->assertJsonPath('unallocated_amount', '0.00');

        $outstanding = $this->getJson('/api/v1/outstanding-invoices');
        $outstanding->assertStatus(200);
        $outstanding->assertJsonCount(0, 'data');
    }

    public function test_a_payment_receipt_pdf_can_be_downloaded(): void
    {
        $this->registerAndReturnCredentials('payment-pdf@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $paymentResponse = $this->postJson('/api/v1/payments', [
            'customer_id' => $customerId,
            'amount' => '150.00',
            'payment_date' => '2026-09-17',
            'deposit_account_id' => $bankId,
            'receivable_account_id' => $receivableId,
            'reference' => 'REF-RECEIPT-001',
        ], ['Idempotency-Key' => 'key-payment-pdf-0001']);
        $paymentResponse->assertStatus(201);
        $paymentId = $paymentResponse->json('id');

        $pdf = $this->get("/api/v1/payments/{$paymentId}/pdf");
        $pdf->assertStatus(200);
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $pdf->getContent());
    }

    public function test_a_payment_receipt_pdf_is_not_downloadable_by_another_tenant(): void
    {
        $this->registerAndReturnCredentials('payment-pdf-tenant-a@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $customerId = $this->createCustomer('Tenant A Customer');

        $paymentResponse = $this->postJson('/api/v1/payments', [
            'customer_id' => $customerId,
            'amount' => '75.00',
            'payment_date' => '2026-09-17',
            'deposit_account_id' => $bankId,
            'receivable_account_id' => $receivableId,
        ], ['Idempotency-Key' => 'key-payment-pdf-tenant-a']);
        $paymentId = $paymentResponse->json('id');
        $this->logout();

        $this->registerAndReturnCredentials('payment-pdf-tenant-b@example.my');
        $this->get("/api/v1/payments/{$paymentId}/pdf")->assertStatus(404);
    }

    public function test_listing_allocations_for_a_nonexistent_payment_returns_404(): void
    {
        $this->registerAndReturnCredentials('payment-allocations-404@example.my');

        $response = $this->getJson('/api/v1/payments/00000000-0000-0000-0000-000000000000/allocations');

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Payment not found.');
    }

    public function test_a_payment_can_be_split_across_two_invoices(): void
    {
        $this->registerAndReturnCredentials('payment-split@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $invoiceAId = $this->issueInvoice($customerId, $receivableId, $revenueId, '100.00');
        $invoiceBId = $this->issueInvoice($customerId, $receivableId, $revenueId, '150.00');

        $paymentResponse = $this->postJson('/api/v1/payments', [
            'customer_id' => $customerId,
            'amount' => '250.00',
            'payment_date' => '2026-09-08',
            'deposit_account_id' => $bankId,
            'receivable_account_id' => $receivableId,
        ], ['Idempotency-Key' => 'key-payment-split']);
        $paymentId = $paymentResponse->json('id');

        $this->postJson("/api/v1/payments/{$paymentId}/allocations", ['invoice_id' => $invoiceAId, 'amount' => '100.00'])->assertStatus(201);
        $this->postJson("/api/v1/payments/{$paymentId}/allocations", ['invoice_id' => $invoiceBId, 'amount' => '150.00'])->assertStatus(201);

        $paymentAfter = $this->getJson("/api/v1/payments/{$paymentId}");
        $paymentAfter->assertJsonPath('unallocated_amount', '0.00');
    }

    public function test_allocating_more_than_the_invoice_balance_is_rejected_via_the_api(): void
    {
        $this->registerAndReturnCredentials('payment-over-invoice@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $invoiceId = $this->issueInvoice($customerId, $receivableId, $revenueId, '100.00');

        $paymentResponse = $this->postJson('/api/v1/payments', [
            'customer_id' => $customerId,
            'amount' => '500.00',
            'payment_date' => '2026-09-08',
            'deposit_account_id' => $bankId,
            'receivable_account_id' => $receivableId,
        ], ['Idempotency-Key' => 'key-payment-over']);
        $paymentId = $paymentResponse->json('id');

        $this->postJson("/api/v1/payments/{$paymentId}/allocations", ['invoice_id' => $invoiceId, 'amount' => '150.00'])
            ->assertStatus(422);
    }

    public function test_allocating_against_a_draft_invoice_is_rejected_via_the_api(): void
    {
        $this->registerAndReturnCredentials('payment-draft-invoice@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $draft = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
            'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => '100.00']],
        ]);
        $draftInvoiceId = $draft->json('id');

        $paymentResponse = $this->postJson('/api/v1/payments', [
            'customer_id' => $customerId,
            'amount' => '100.00',
            'payment_date' => '2026-09-08',
            'deposit_account_id' => $bankId,
            'receivable_account_id' => $receivableId,
        ], ['Idempotency-Key' => 'key-payment-draft']);
        $paymentId = $paymentResponse->json('id');

        $this->postJson("/api/v1/payments/{$paymentId}/allocations", ['invoice_id' => $draftInvoiceId, 'amount' => '50.00'])
            ->assertStatus(422);
    }

    public function test_an_allocation_can_be_removed_and_frees_the_invoice_balance(): void
    {
        $this->registerAndReturnCredentials('payment-deallocate@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $invoiceId = $this->issueInvoice($customerId, $receivableId, $revenueId, '300.00');

        $paymentResponse = $this->postJson('/api/v1/payments', [
            'customer_id' => $customerId,
            'amount' => '300.00',
            'payment_date' => '2026-09-08',
            'deposit_account_id' => $bankId,
            'receivable_account_id' => $receivableId,
        ], ['Idempotency-Key' => 'key-payment-deallocate']);
        $paymentId = $paymentResponse->json('id');

        $allocationResponse = $this->postJson("/api/v1/payments/{$paymentId}/allocations", ['invoice_id' => $invoiceId, 'amount' => '300.00']);
        $allocationId = $allocationResponse->json('id');

        $this->deleteJson("/api/v1/payment-allocations/{$allocationId}")->assertStatus(204);

        $outstanding = $this->getJson('/api/v1/outstanding-invoices');
        $outstanding->assertJsonCount(1, 'data');
        $outstanding->assertJsonPath('data.0.outstanding_balance', '300.00');
    }

    public function test_recording_a_payment_without_an_idempotency_key_is_rejected(): void
    {
        $this->registerAndReturnCredentials('payment-no-key@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $this->postJson('/api/v1/payments', [
            'customer_id' => $customerId,
            'amount' => '100.00',
            'payment_date' => '2026-09-08',
            'deposit_account_id' => $bankId,
            'receivable_account_id' => $receivableId,
        ])->assertStatus(422);
    }

    // --- Aging Report (M22) ---------------------------------------------

    public function test_the_aging_report_reflects_outstanding_invoices_and_excludes_paid_ones(): void
    {
        $this->registerAndReturnCredentials('aging-report@example.my');
        $bankId = $this->createAccount('1000', 'Bank', 'Asset');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');

        $unpaidInvoiceId = $this->issueInvoice($customerId, $receivableId, $revenueId, '100.00');
        $paidInvoiceId = $this->issueInvoice($customerId, $receivableId, $revenueId, '50.00');

        $paymentResponse = $this->postJson('/api/v1/payments', [
            'customer_id' => $customerId,
            'amount' => '50.00',
            'payment_date' => now()->toDateString(),
            'deposit_account_id' => $bankId,
            'receivable_account_id' => $receivableId,
        ], ['Idempotency-Key' => 'key-aging-payment']);
        $paymentId = $paymentResponse->json('id');
        $this->postJson("/api/v1/payments/{$paymentId}/allocations", ['invoice_id' => $paidInvoiceId, 'amount' => '50.00'])
            ->assertStatus(201);

        $response = $this->getJson('/api/v1/reports/aging?as_of='.now()->toDateString());

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'lines');
        $response->assertJsonPath('lines.0.invoice_id', $unpaidInvoiceId);
        $response->assertJsonPath('grand_total', '100.00');
    }

    // --- Report CSV export (M23, Hasil MVP item 8) ----------------------

    public function test_the_trial_balance_can_be_exported_as_csv(): void
    {
        $this->registerAndReturnCredentials('csv-trial-balance@example.my');
        $cashId = $this->createAccount('1000', 'Cash', 'Asset');
        $revenueId = $this->createAccount('4000', 'Sales Revenue', 'Revenue');
        $this->postJson('/api/v1/incomes', [
            'amount' => '100.00',
            'transaction_date' => '2026-09-08',
            'income_account_id' => $revenueId,
            'deposit_account_id' => $cashId,
            'description' => 'Sales',
        ], ['Idempotency-Key' => 'key-csv-income'])->assertStatus(201);

        $response = $this->get('/api/v1/reports/trial-balance?as_of=2026-09-08&format=csv');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename="trial-balance-2026-09-08.csv"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString($cashId, $response->getContent());
        $this->assertStringContainsString($revenueId, $response->getContent());
    }

    public function test_the_aging_report_can_be_exported_as_csv(): void
    {
        $this->registerAndReturnCredentials('csv-aging@example.my');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $revenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');
        $customerId = $this->createCustomer('Kedai Runcit Aminah');
        $invoiceId = $this->issueInvoice($customerId, $receivableId, $revenueId, '100.00');

        $response = $this->get('/api/v1/reports/aging?as_of='.now()->toDateString().'&format=csv');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString($invoiceId, $response->getContent());
        $this->assertStringContainsString('Bucket', $response->getContent());
    }

    /**
     * AETS-009 §19: the Compliance Pack bundles Trial Balance,
     * Profit & Loss, Balance Sheet, Aging, and Evidence Index as CSV
     * entries, plus (as of v1.7.0) Profit & Loss and Balance Sheet as
     * "loan-ready" PDF entries too, inside one downloadable ZIP — this
     * proves the real HTTP boundary end to end, not just that
     * `ZipResponseBuilder` itself produces a valid archive (already
     * proven at the unit level).
     */
    public function test_the_compliance_pack_can_be_downloaded_as_a_zip_of_csvs_and_pdfs(): void
    {
        $this->registerAndReturnCredentials('compliance-pack@example.my');
        $cashId = $this->createAccount('1000', 'Cash', 'Asset');
        $revenueId = $this->createAccount('4000', 'Sales Revenue', 'Revenue');
        $this->postJson('/api/v1/incomes', [
            'amount' => '100.00',
            'transaction_date' => '2026-09-08',
            'income_account_id' => $revenueId,
            'deposit_account_id' => $cashId,
            'description' => 'Sales',
        ], ['Idempotency-Key' => 'key-compliance-pack-income'])->assertStatus(201);

        $response = $this->get('/api/v1/reports/compliance-pack?period_start=2026-09-01&period_end=2026-09-30');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('attachment; filename="compliance-pack-2026-09-01-to-2026-09-30.zip"', $response->headers->get('Content-Disposition'));

        $tempPath = tempnam(sys_get_temp_dir(), 'compliance-pack-http-test-');
        file_put_contents($tempPath, $response->getContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tempPath) === true);

        $this->assertSame(9, $zip->numFiles);
        $this->assertStringContainsString($cashId, (string) $zip->getFromName('trial-balance.csv'));
        $this->assertStringContainsString($revenueId, (string) $zip->getFromName('profit-and-loss.csv'));
        $this->assertNotFalse($zip->getFromName('balance-sheet.csv'));
        $this->assertNotFalse($zip->getFromName('aging-report.csv'));
        $this->assertNotFalse($zip->getFromName('evidence-index.csv'));
        $this->assertNotFalse($zip->getFromName('cash-flow.csv'));
        $this->assertStringStartsWith('%PDF-', (string) $zip->getFromName('profit-and-loss.pdf'));
        $this->assertStringStartsWith('%PDF-', (string) $zip->getFromName('balance-sheet.pdf'));
        $this->assertStringStartsWith('%PDF-', (string) $zip->getFromName('cash-flow.pdf'));

        $zip->close();
        unlink($tempPath);
    }

    /**
     * AETS-009 §20: the same Trial Balance data, resolved via
     * `?format=xlsx` instead of `?format=csv` — proves the real HTTP
     * boundary returns a genuinely valid XLSX file, not just that
     * `XlsxResponseBuilder` itself is correct (already proven at the
     * unit level).
     */
    public function test_the_trial_balance_can_be_exported_as_xlsx(): void
    {
        $this->registerAndReturnCredentials('xlsx-trial-balance@example.my');
        $cashId = $this->createAccount('1000', 'Cash', 'Asset');
        $revenueId = $this->createAccount('4000', 'Sales Revenue', 'Revenue');
        $this->postJson('/api/v1/incomes', [
            'amount' => '100.00',
            'transaction_date' => '2026-09-08',
            'income_account_id' => $revenueId,
            'deposit_account_id' => $cashId,
            'description' => 'Sales',
        ], ['Idempotency-Key' => 'key-xlsx-income'])->assertStatus(201);

        $response = $this->get('/api/v1/reports/trial-balance?as_of=2026-09-08&format=xlsx');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('attachment; filename="trial-balance-2026-09-08.xlsx"', $response->headers->get('Content-Disposition'));

        $tempPath = tempnam(sys_get_temp_dir(), 'xlsx-http-test-');
        file_put_contents($tempPath, $response->getContent());
        $sheet = IOFactory::load($tempPath)->getActiveSheet();
        unlink($tempPath);

        $values = [];
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $values[] = (string) $cell->getValue();
            }
        }

        $this->assertContains($cashId, $values);
        $this->assertContains($revenueId, $values);
    }

    public function test_an_invalid_export_format_is_rejected(): void
    {
        $this->registerAndReturnCredentials('csv-invalid-format@example.my');

        $this->get('/api/v1/reports/trial-balance?as_of=2026-09-08&format=xml')->assertStatus(422);
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

    // --- Workspace / Task (ADR-0009, WTS-001) --------------------------

    public function test_a_task_cannot_submit_another_tenants_account_references(): void
    {
        $this->registerAndReturnCredentials('workspace-tenant-a@example.my');
        $expenseAccountId = $this->createAccount('5000', 'Tenant A Expense', 'Expense');
        $cashAccountId = $this->createAccount('1000', 'Tenant A Cash', 'Asset');
        $this->logout();

        $this->registerAndReturnCredentials('workspace-tenant-b@example.my');

        $response = $this->postJson('/api/v1/tasks', [
            'command_type' => 'Expense',
            'amount' => '50.00',
            'transaction_date' => '2026-09-08',
            'primary_account_id' => $expenseAccountId,
            'secondary_account_id' => $cashAccountId,
            'description' => 'Cross-Tenant Task attempt',
        ], ['Idempotency-Key' => 'task-cross-tenant-account-0001']);

        $response->assertStatus(422);
        $this->assertSame(0, DB::connection('pgsql')->table('tasks')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('proposals')->count());
    }

    public function test_a_task_rejects_malformed_money_before_creating_a_proposal(): void
    {
        $this->registerAndReturnCredentials('workspace-invalid-money@example.my');
        $expenseAccountId = $this->createAccount('5000', 'Expense', 'Expense');
        $cashAccountId = $this->createAccount('1000', 'Cash', 'Asset');

        $response = $this->postJson('/api/v1/tasks', [
            'command_type' => 'Expense',
            'amount' => '12.3',
            'transaction_date' => '2026-09-08',
            'primary_account_id' => $expenseAccountId,
            'secondary_account_id' => $cashAccountId,
            'description' => 'Malformed Money attempt',
        ], ['Idempotency-Key' => 'task-invalid-money-0001']);

        $response->assertStatus(422);
        $this->assertSame(0, DB::connection('pgsql')->table('tasks')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('proposals')->count());
    }

    public function test_a_task_rejects_an_unsupported_command_type(): void
    {
        $this->registerAndReturnCredentials('workspace-unsupported-command@example.my');
        $expenseAccountId = $this->createAccount('5000', 'Expense', 'Expense');
        $cashAccountId = $this->createAccount('1000', 'Cash', 'Asset');

        $response = $this->postJson('/api/v1/tasks', [
            'command_type' => 'Invoice',
            'amount' => '12.30',
            'transaction_date' => '2026-09-08',
            'primary_account_id' => $expenseAccountId,
            'secondary_account_id' => $cashAccountId,
            'description' => 'Unsupported Command attempt',
        ], ['Idempotency-Key' => 'task-unsupported-command-0001']);

        $response->assertStatus(422);
        $this->assertSame(0, DB::connection('pgsql')->table('tasks')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('proposals')->count());
    }

    public function test_a_task_submitted_and_approved_over_http_posts_a_journal(): void
    {
        $this->registerAndReturnCredentials('workspace-owner@example.my');
        $expenseAccountId = $this->createAccount('5000', 'Office Supplies', 'Expense');
        $cashAccountId = $this->createAccount('1000', 'Cash', 'Asset');

        $submitResponse = $this->postJson('/api/v1/tasks', [
            'command_type' => 'Expense',
            'amount' => '75.00',
            'transaction_date' => '2026-09-08',
            'primary_account_id' => $expenseAccountId,
            'secondary_account_id' => $cashAccountId,
            'description' => 'Printer paper',
        ], ['Idempotency-Key' => 'task-key-0001']);

        $submitResponse->assertStatus(201);
        $submitResponse->assertJsonPath('state', 'NeedsReview');
        $taskId = $submitResponse->json('id');

        $approveResponse = $this->postJson('/api/v1/tasks/'.$taskId.'/approve');

        $approveResponse->assertStatus(200);
        $approveResponse->assertJsonPath('state', 'Completed');
        $this->assertNotNull($approveResponse->json('result_journal_id'));

        $showResponse = $this->getJson('/api/v1/tasks/'.$taskId);
        $showResponse->assertStatus(200);
        $showResponse->assertJsonPath('proposal.command_type', 'Expense');
        $transitions = $showResponse->json('transitions');
        $this->assertCount(6, $transitions);
    }

    public function test_a_task_can_be_rejected_with_a_reason(): void
    {
        $this->registerAndReturnCredentials('workspace-reject@example.my');
        $expenseAccountId = $this->createAccount('5000', 'Office Supplies', 'Expense');
        $cashAccountId = $this->createAccount('1000', 'Cash', 'Asset');

        $submitResponse = $this->postJson('/api/v1/tasks', [
            'command_type' => 'Expense',
            'amount' => '20.00',
            'transaction_date' => '2026-09-08',
            'primary_account_id' => $expenseAccountId,
            'secondary_account_id' => $cashAccountId,
            'description' => 'Wrong entry',
        ], ['Idempotency-Key' => 'task-key-0002']);
        $taskId = $submitResponse->json('id');

        $rejectResponse = $this->postJson('/api/v1/tasks/'.$taskId.'/reject', ['reason' => 'Wrong account.']);

        $rejectResponse->assertStatus(200);
        $rejectResponse->assertJsonPath('state', 'Rejected');
    }

    /**
     * TSK-013 (WTS-001 v3.0.0): omitting both Account references defers
     * the decision to `NeedsInformation`; supplying them later via
     * `provide-information` completes the Task into `NeedsReview` with
     * a real Proposal, reachable end to end over HTTP.
     */
    public function test_a_task_can_defer_the_account_decision_and_later_provide_information_over_http(): void
    {
        $this->registerAndReturnCredentials('workspace-defer@example.my');
        $expenseAccountId = $this->createAccount('5000', 'Office Supplies', 'Expense');
        $cashAccountId = $this->createAccount('1000', 'Cash', 'Asset');

        $submitResponse = $this->postJson('/api/v1/tasks', [
            'command_type' => 'Expense',
            'amount' => '30.00',
            'transaction_date' => '2026-09-16',
            'description' => 'Not sure which account yet',
        ], ['Idempotency-Key' => 'task-key-defer-0001']);

        $submitResponse->assertStatus(201);
        $submitResponse->assertJsonPath('state', 'NeedsInformation');
        $taskId = $submitResponse->json('id');

        $showResponse = $this->getJson('/api/v1/tasks/'.$taskId);
        $showResponse->assertStatus(200);
        $showResponse->assertJsonPath('proposal', null);
        $showResponse->assertJsonPath('draft.command_type', 'Expense');
        $showResponse->assertJsonPath('draft.amount', '30.00');

        $provideResponse = $this->postJson('/api/v1/tasks/'.$taskId.'/provide-information', [
            'primary_account_id' => $expenseAccountId,
            'secondary_account_id' => $cashAccountId,
        ]);

        $provideResponse->assertStatus(200);
        $provideResponse->assertJsonPath('state', 'NeedsReview');
        $provideResponse->assertJsonPath('proposal.primary_account_id', $expenseAccountId);

        $approveResponse = $this->postJson('/api/v1/tasks/'.$taskId.'/approve');
        $approveResponse->assertStatus(200);
        $approveResponse->assertJsonPath('state', 'Completed');
    }

    public function test_submitting_a_task_with_exactly_one_account_reference_is_rejected(): void
    {
        $this->registerAndReturnCredentials('workspace-defer-invalid@example.my');
        $expenseAccountId = $this->createAccount('5000', 'Office Supplies', 'Expense');

        $submitResponse = $this->postJson('/api/v1/tasks', [
            'command_type' => 'Expense',
            'amount' => '30.00',
            'transaction_date' => '2026-09-16',
            'primary_account_id' => $expenseAccountId,
            'description' => 'Only one account supplied',
        ], ['Idempotency-Key' => 'task-key-defer-invalid-0001']);

        $submitResponse->assertStatus(422);
    }

    /**
     * TSK-014 (WTS-001 v3.0.0): a Task under review can be edited by
     * superseding it and submitting a correction, reachable end to end
     * over HTTP. The correction carries the opaque `supersedes_task_id`
     * link, and the original is left `Superseded`.
     */
    public function test_a_task_under_review_can_be_edited_via_supersede_over_http(): void
    {
        $this->registerAndReturnCredentials('workspace-supersede@example.my');
        $expenseAccountId = $this->createAccount('5000', 'Office Supplies', 'Expense');
        $cashAccountId = $this->createAccount('1000', 'Cash', 'Asset');
        $savingsAccountId = $this->createAccount('1010', 'Savings', 'Asset');

        $submitResponse = $this->postJson('/api/v1/tasks', [
            'command_type' => 'Expense',
            'amount' => '40.00',
            'transaction_date' => '2026-09-16',
            'primary_account_id' => $expenseAccountId,
            'secondary_account_id' => $cashAccountId,
            'description' => 'Wrong payment account',
        ], ['Idempotency-Key' => 'task-key-supersede-0001']);
        $originalTaskId = $submitResponse->json('id');

        $supersedeResponse = $this->postJson('/api/v1/tasks/'.$originalTaskId.'/supersede', [
            'command_type' => 'Expense',
            'amount' => '40.00',
            'transaction_date' => '2026-09-16',
            'primary_account_id' => $expenseAccountId,
            'secondary_account_id' => $savingsAccountId,
            'description' => 'Corrected payment account',
            'reason' => 'Paid from savings, not cash.',
        ], ['Idempotency-Key' => 'task-key-supersede-correction-0001']);

        $supersedeResponse->assertStatus(201);
        $supersedeResponse->assertJsonPath('state', 'NeedsReview');
        $supersedeResponse->assertJsonPath('supersedes_task_id', $originalTaskId);
        $correctionTaskId = $supersedeResponse->json('id');

        $originalShowResponse = $this->getJson('/api/v1/tasks/'.$originalTaskId);
        $originalShowResponse->assertJsonPath('state', 'Superseded');

        $approveResponse = $this->postJson('/api/v1/tasks/'.$correctionTaskId.'/approve');
        $approveResponse->assertStatus(200);
        $approveResponse->assertJsonPath('state', 'Completed');
    }

    public function test_a_tenants_tasks_are_never_visible_to_another_tenant(): void
    {
        $this->registerAndReturnCredentials('workspace-tenant-a@example.my');
        $accountAId = $this->createAccount('5000', 'Office Supplies', 'Expense');
        $cashAId = $this->createAccount('1000', 'Cash', 'Asset');
        $submitResponse = $this->postJson('/api/v1/tasks', [
            'command_type' => 'Expense',
            'amount' => '10.00',
            'transaction_date' => '2026-09-08',
            'primary_account_id' => $accountAId,
            'secondary_account_id' => $cashAId,
            'description' => 'Tenant A task',
        ], ['Idempotency-Key' => 'task-key-cross-tenant']);
        $taskId = $submitResponse->json('id');
        $this->logout();

        $this->registerAndReturnCredentials('workspace-tenant-b@example.my');

        $this->getJson('/api/v1/tasks/'.$taskId)->assertStatus(404);
        $this->postJson('/api/v1/tasks/'.$taskId.'/approve')->assertStatus(404);
        $this->assertSame(1, DB::connection('pgsql')->table('tasks')->count());
    }

    /**
     * TSK-005: a Task's Proposal is re-validated against the current
     * closed-Period watermark at `Executing`, not only at submission.
     * Mirrors `test_a_transfer_backdated_into_a_closed_period_is_rejected`'s
     * own established pattern (search this file) exactly, applied to
     * the Task/Proposal flow: the Task is *submitted* while its
     * transaction date is still open, the Period closes afterward
     * (never touching the Task itself), and only then is it approved
     * — proving the check happens at Command-execution time, not
     * merely inherited from validation Accounting Core already ran
     * once, earlier, against a different state of the world.
     */
    public function test_a_task_approved_after_its_period_closed_fails_without_posting(): void
    {
        $this->registerAndReturnCredentials('workspace-period-lock@example.my');
        $officeSuppliesId = $this->createAccount('5000', 'Office Supplies', 'Expense');
        $cashId = $this->createAccount('1000', 'Cash', 'Asset');
        $retainedEarningsId = $this->createAccount('3900', 'Retained Earnings', 'Equity');

        $submitResponse = $this->postJson('/api/v1/tasks', [
            'command_type' => 'Expense',
            'amount' => '25.00',
            'transaction_date' => '2026-07-10',
            'primary_account_id' => $officeSuppliesId,
            'secondary_account_id' => $cashId,
            'description' => 'Backdated into a period that closes after submission',
        ], ['Idempotency-Key' => 'task-key-period-lock-0001']);
        $submitResponse->assertStatus(201);
        $taskId = $submitResponse->json('id');

        // PeriodClosingService rejects closing a period with zero
        // Revenue/Expense activity (nothing to close) — post a real,
        // separate Expense directly so the period has something to
        // close. This must never be the Task under test itself: this
        // Task is still only submitted (NeedsReview), not posted, per
        // the whole point of TSK-005.
        $this->postJson('/api/v1/expenses', [
            'amount' => '5.00',
            'transaction_date' => '2026-07-05',
            'expense_account_id' => $officeSuppliesId,
            'payment_account_id' => $cashId,
            'description' => 'Unrelated activity so the period has something to close',
        ], ['Idempotency-Key' => 'task-key-period-lock-seed-0001'])->assertStatus(201);

        $this->postJson('/api/v1/periods/close', [
            'closed_through_date' => '2026-07-31',
            'retained_earnings_account_id' => $retainedEarningsId,
        ], ['Idempotency-Key' => 'task-key-period-lock-close-0001'])->assertStatus(201);

        $approveResponse = $this->postJson('/api/v1/tasks/'.$taskId.'/approve');

        $approveResponse->assertStatus(200);
        $approveResponse->assertJsonPath('state', 'Failed');
        $this->assertNotNull($approveResponse->json('failure_reason'));
        $this->assertNull($approveResponse->json('result_journal_id'));
        // Exactly the one seed Expense posted above to give the period
        // something to close — never the Task under test's own.
        $this->assertSame(1, DB::connection('pgsql')->table('expenses')->count());
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

    public function test_dashboard_uses_exact_malaysia_calendar_month_boundaries(): void
    {
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-09-17 12:00:00', 'Asia/Kuala_Lumpur'));

        try {
            $this->registerAndReturnCredentials('dashboard-boundaries@example.my');
            $cashId = $this->createAccount('1000', 'Cash', 'Asset');
            $revenueId = $this->createAccount('4000', 'Sales Revenue', 'Revenue');
            $this->registerBankAccount($cashId, 'Dashboard Test Bank');

            $this->postJson('/api/v1/incomes', [
                'amount' => '90.00',
                'transaction_date' => '2026-08-31',
                'income_account_id' => $revenueId,
                'deposit_account_id' => $cashId,
                'description' => 'August boundary sale',
            ], ['Idempotency-Key' => 'key-dashboard-august-boundary'])->assertStatus(201);

            $this->postJson('/api/v1/incomes', [
                'amount' => '100.00',
                'transaction_date' => '2026-09-01',
                'income_account_id' => $revenueId,
                'deposit_account_id' => $cashId,
                'description' => 'September boundary sale',
            ], ['Idempotency-Key' => 'key-dashboard-september-boundary'])->assertStatus(201);

            $journalCountBefore = DB::connection('pgsql')->table('journals')->count();
            $response = $this->getJson('/api/v1/dashboard');

            $response->assertOk();
            $response->assertJsonPath('as_of', '2026-09-17');
            $response->assertJsonPath('generated_at', '2026-09-17T12:00:00+08:00');
            $response->assertJsonPath('trend.4.period_start', '2026-08-01');
            $response->assertJsonPath('trend.4.period_end', '2026-08-31');
            $response->assertJsonPath('trend.4.total_revenue', '90.00');
            $response->assertJsonPath('trend.4.cash_flow.operating.amount', '90.00');
            $response->assertJsonPath('trend.4.cash_flow.operating.direction', 'Debit');
            $response->assertJsonPath('trend.4.cash_flow.investing.amount', '0.00');
            $response->assertJsonPath('trend.4.cash_flow.investing.direction', null);
            $response->assertJsonPath('trend.4.cash_flow.financing.amount', '0.00');
            $response->assertJsonPath('trend.4.cash_flow.financing.direction', null);
            $response->assertJsonPath('trend.4.cash_flow.net_change.amount', '90.00');
            $response->assertJsonPath('trend.4.cash_flow.net_change.direction', 'Debit');
            $response->assertJsonPath('current_period.period_start', '2026-09-01');
            $response->assertJsonPath('current_period.period_end', '2026-09-17');
            $response->assertJsonPath('current_period.total_revenue', '100.00');
            $response->assertJsonPath('current_period.cash_flow.operating.amount', '100.00');
            $response->assertJsonPath('current_period.cash_flow.operating.direction', 'Debit');
            $response->assertJsonPath('current_period.cash_flow.net_change.amount', '100.00');
            $response->assertJsonPath('current_period.cash_flow.net_change.direction', 'Debit');
            $response->assertJsonPath('financial_position.total_assets', '190.00');
            $response->assertJsonPath('attention.task_count', 0);
            $response->assertJsonPath('attention.overdue_invoice_count', 0);
            $response->assertJsonPath('attention.overdue_invoice_total', '0.00');
            $this->assertSame($journalCountBefore, DB::connection('pgsql')->table('journals')->count());

            $this->registerAndReturnCredentials('dashboard-boundaries-tenant-b@example.my');
            $tenantBResponse = $this->getJson('/api/v1/dashboard');
            $tenantBResponse->assertOk();
            $tenantBResponse->assertJsonPath('current_period.total_revenue', '0.00');
            $tenantBResponse->assertJsonPath('financial_position.total_assets', '0.00');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_profit_and_loss_and_balance_sheet_can_be_downloaded_as_a_loan_ready_pdf(): void
    {
        $this->registerAndReturnCredentials('loan-ready-pdf@example.my');

        $cashId = $this->createAccount('1000', 'Cash', 'Asset');
        $officeSuppliesId = $this->createAccount('5000', 'Office Supplies', 'Expense');
        $revenueId = $this->createAccount('4000', 'Consulting Revenue', 'Revenue');

        $this->postJson('/api/v1/expenses', [
            'amount' => '50.00',
            'transaction_date' => '2026-08-10',
            'expense_account_id' => $officeSuppliesId,
            'payment_account_id' => $cashId,
            'description' => 'Office supplies',
        ], ['Idempotency-Key' => 'key-loan-ready-expense-0001'])->assertStatus(201);

        $this->postJson('/api/v1/incomes', [
            'amount' => '200.00',
            'transaction_date' => '2026-08-15',
            'income_account_id' => $revenueId,
            'deposit_account_id' => $cashId,
            'description' => 'Consulting revenue',
        ], ['Idempotency-Key' => 'key-loan-ready-income-0001'])->assertStatus(201);

        $pnlPdf = $this->get('/api/v1/reports/profit-and-loss?period_start=2026-08-01&period_end=2026-08-31&format=pdf');
        $pnlPdf->assertStatus(200);
        $pnlPdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $pnlPdf->getContent());

        $balanceSheetPdf = $this->get('/api/v1/reports/balance-sheet?as_of=2026-08-31&format=pdf');
        $balanceSheetPdf->assertStatus(200);
        $balanceSheetPdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $balanceSheetPdf->getContent());
    }

    /**
     * AETS-009 §21 (extended v1.9.0) — Trial Balance, General Ledger,
     * Aging Report, and Evidence Index each gain a real PDF, resolving
     * the deferral §2.2 previously stated for these four specifically.
     */
    public function test_trial_balance_general_ledger_aging_report_and_evidence_index_can_be_downloaded_as_pdf(): void
    {
        $this->registerAndReturnCredentials('report-pdf-extended@example.my');

        $cashId = $this->createAccount('1000', 'Cash', 'Asset');
        $officeSuppliesId = $this->createAccount('5000', 'Office Supplies', 'Expense');
        $revenueId = $this->createAccount('4000', 'Consulting Revenue', 'Revenue');
        $receivableId = $this->createAccount('1100', 'Accounts Receivable', 'Asset');
        $serviceRevenueId = $this->createAccount('4100', 'Service Revenue', 'Revenue');

        $this->postJson('/api/v1/expenses', [
            'amount' => '50.00',
            'transaction_date' => '2026-08-10',
            'expense_account_id' => $officeSuppliesId,
            'payment_account_id' => $cashId,
            'description' => 'Office supplies',
        ], ['Idempotency-Key' => 'key-report-pdf-extended-expense-0001'])->assertStatus(201);

        $this->postJson('/api/v1/incomes', [
            'amount' => '200.00',
            'transaction_date' => '2026-08-15',
            'income_account_id' => $revenueId,
            'deposit_account_id' => $cashId,
            'description' => 'Consulting revenue',
        ], ['Idempotency-Key' => 'key-report-pdf-extended-income-0001'])->assertStatus(201);

        $customerId = $this->createCustomer('Kedai Ah Chong');
        $draftInvoice = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'due_date' => '2026-08-20',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $serviceRevenueId,
            'lines' => [
                ['description' => 'Tudung', 'quantity' => 10, 'unit_price' => '26.90'],
            ],
        ]);
        $draftInvoice->assertStatus(201);
        $invoiceId = $draftInvoice->json('id');
        $this->postJson("/api/v1/invoices/{$invoiceId}/issue", ['issue_date' => '2026-08-20'], ['Idempotency-Key' => 'key-report-pdf-extended-issue-0001'])
            ->assertStatus(201);

        $trialBalancePdf = $this->get('/api/v1/reports/trial-balance?as_of=2026-08-31&format=pdf');
        $trialBalancePdf->assertStatus(200);
        $trialBalancePdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $trialBalancePdf->getContent());

        $generalLedgerPdf = $this->get("/api/v1/reports/general-ledger?account_id={$cashId}&period_start=2026-08-01&period_end=2026-08-31&format=pdf");
        $generalLedgerPdf->assertStatus(200);
        $generalLedgerPdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $generalLedgerPdf->getContent());

        $agingReportPdf = $this->get('/api/v1/reports/aging?as_of=2026-08-31&format=pdf');
        $agingReportPdf->assertStatus(200);
        $agingReportPdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $agingReportPdf->getContent());

        $evidenceIndexPdf = $this->get('/api/v1/reports/evidence-index?period_start=2026-08-01&period_end=2026-08-31&format=pdf');
        $evidenceIndexPdf->assertStatus(200);
        $evidenceIndexPdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $evidenceIndexPdf->getContent());
    }

    // --- Cash Flow Statement (AETS-009 §22) ---------------------------------

    private function registerBankAccount(string $linkedAccountId, string $bankName): string
    {
        $response = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $linkedAccountId,
            'bank_name' => $bankName,
        ]);
        $response->assertStatus(201);

        /** @var string $id */
        $id = $response->json('id');

        return $id;
    }

    /**
     * The golden-dataset proof: every transaction type this codebase
     * actually supports today (Income, Expense, a loan received and
     * repaid via Transfer, a Capital Contribution, an Owner Drawing,
     * and a pure Cash↔Bank transfer) is classified into the correct
     * Cash Flow activity, and the whole Statement ties out exactly to
     * the Tenant's own real Cash+Bank balance change (`RPT-018`).
     */
    public function test_the_cash_flow_statement_classifies_every_supported_transaction_type_correctly(): void
    {
        $this->registerAndReturnCredentials('cash-flow@example.my');

        $cashId = $this->createAccount('1000', 'Cash', 'Asset');
        $bankId = $this->createAccount('1010', 'Bank', 'Asset');
        $revenueId = $this->createAccount('4000', 'Sales Revenue', 'Revenue');
        $expenseId = $this->createAccount('5000', 'Rent Expense', 'Expense');
        $loanId = $this->createAccount('2000', 'Loans Payable', 'Liability');
        $capitalId = $this->createAccount('3000', "Owner's Capital", 'Equity');
        $drawingsId = $this->createAccount('3100', "Owner's Drawings", 'Equity');

        $this->registerBankAccount($cashId, 'Cash Till');
        $this->registerBankAccount($bankId, 'Maybank');

        // Operating: Income (+500) and Expense (-100).
        $this->postJson('/api/v1/incomes', [
            'amount' => '500.00', 'transaction_date' => '2026-09-05',
            'income_account_id' => $revenueId, 'deposit_account_id' => $cashId,
            'description' => 'Sales',
        ], ['Idempotency-Key' => 'cf-income-0001'])->assertStatus(201);

        $this->postJson('/api/v1/expenses', [
            'amount' => '100.00', 'transaction_date' => '2026-09-06',
            'expense_account_id' => $expenseId, 'payment_account_id' => $cashId,
            'description' => 'Rent',
        ], ['Idempotency-Key' => 'cf-expense-0001'])->assertStatus(201);

        // Financing: loan received (+1000), loan repaid (-200), capital
        // contribution (+300), owner drawing (-50).
        $this->postJson('/api/v1/transfers', [
            'amount' => '1000.00', 'transaction_date' => '2026-09-07',
            'source_account_id' => $loanId, 'destination_account_id' => $cashId,
            'description' => 'Loan received',
        ], ['Idempotency-Key' => 'cf-loan-received-0001'])->assertStatus(201);

        $this->postJson('/api/v1/transfers', [
            'amount' => '200.00', 'transaction_date' => '2026-09-08',
            'source_account_id' => $cashId, 'destination_account_id' => $loanId,
            'description' => 'Loan repayment',
        ], ['Idempotency-Key' => 'cf-loan-repay-0001'])->assertStatus(201);

        $this->postJson('/api/v1/capital-contributions', [
            'amount' => '300.00', 'transaction_date' => '2026-09-09',
            'cash_account_id' => $cashId, 'equity_account_id' => $capitalId,
            'description' => 'Owner injection',
        ], ['Idempotency-Key' => 'cf-capital-0001'])->assertStatus(201);

        $this->postJson('/api/v1/owner-drawings', [
            'amount' => '50.00', 'transaction_date' => '2026-09-10',
            'cash_account_id' => $cashId, 'equity_account_id' => $drawingsId,
            'description' => 'Owner drawing',
        ], ['Idempotency-Key' => 'cf-drawing-0001'])->assertStatus(201);

        // Excluded entirely: a pure Cash↔Bank transfer — both sides are
        // cash-equivalent, so it never appears in any activity section,
        // yet the Tenant's total cash position still reflects it.
        $this->postJson('/api/v1/transfers', [
            'amount' => '150.00', 'transaction_date' => '2026-09-11',
            'source_account_id' => $cashId, 'destination_account_id' => $bankId,
            'description' => 'Move to bank',
        ], ['Idempotency-Key' => 'cf-cash-to-bank-0001'])->assertStatus(201);

        $response = $this->getJson('/api/v1/reports/cash-flow?period_start=2026-09-01&period_end=2026-09-30');
        $response->assertStatus(200);

        $response->assertJsonCount(2, 'operating_lines');
        $response->assertJsonCount(0, 'investing_lines');
        $response->assertJsonCount(3, 'financing_lines');

        $operatingLines = collect($response->json('operating_lines'))->keyBy('account_id');
        $this->assertSame('500.00', $operatingLines[$revenueId]['net_cash_flow']['amount']);
        $this->assertSame('Debit', $operatingLines[$revenueId]['net_cash_flow']['direction']);
        $this->assertSame('100.00', $operatingLines[$expenseId]['net_cash_flow']['amount']);
        $this->assertSame('Credit', $operatingLines[$expenseId]['net_cash_flow']['direction']);

        $financingLines = collect($response->json('financing_lines'))->keyBy('account_id');
        $this->assertSame('800.00', $financingLines[$loanId]['net_cash_flow']['amount']);
        $this->assertSame('Debit', $financingLines[$loanId]['net_cash_flow']['direction']);
        $this->assertSame('300.00', $financingLines[$capitalId]['net_cash_flow']['amount']);
        $this->assertSame('Debit', $financingLines[$capitalId]['net_cash_flow']['direction']);
        $this->assertSame('50.00', $financingLines[$drawingsId]['net_cash_flow']['amount']);
        $this->assertSame('Credit', $financingLines[$drawingsId]['net_cash_flow']['direction']);

        $response->assertJsonPath('operating_total.amount', '400.00');
        $response->assertJsonPath('operating_total.direction', 'Debit');
        $response->assertJsonPath('investing_total.amount', '0.00');
        $response->assertJsonPath('financing_total.amount', '1050.00');
        $response->assertJsonPath('financing_total.direction', 'Debit');

        // RPT-018: net change in cash MUST equal the Tenant's own real
        // Cash+Bank balance change — 500-100+1000-200+300-50 = 1450,
        // the Cash↔Bank transfer contributing zero net (it only moves
        // money between the two cash-equivalent Accounts).
        $response->assertJsonPath('net_change_in_cash.amount', '1450.00');
        $response->assertJsonPath('net_change_in_cash.direction', 'Debit');
        $response->assertJsonPath('cash_at_period_start.amount', '0.00');
        $response->assertJsonPath('cash_at_period_end.amount', '1450.00');
        $response->assertJsonPath('cash_at_period_end.direction', 'Debit');
    }

    public function test_the_cash_flow_statement_can_be_downloaded_as_csv_xlsx_and_pdf(): void
    {
        $this->registerAndReturnCredentials('cash-flow-export@example.my');

        $cashId = $this->createAccount('1000', 'Cash', 'Asset');
        $revenueId = $this->createAccount('4000', 'Sales Revenue', 'Revenue');
        $this->registerBankAccount($cashId, 'Cash Till');

        $this->postJson('/api/v1/incomes', [
            'amount' => '250.00', 'transaction_date' => '2026-09-05',
            'income_account_id' => $revenueId, 'deposit_account_id' => $cashId,
            'description' => 'Sales',
        ], ['Idempotency-Key' => 'cf-export-income-0001'])->assertStatus(201);

        $csv = $this->get('/api/v1/reports/cash-flow?period_start=2026-09-01&period_end=2026-09-30&format=csv');
        $csv->assertStatus(200);
        $this->assertStringContainsString($revenueId, (string) $csv->getContent());

        $xlsx = $this->get('/api/v1/reports/cash-flow?period_start=2026-09-01&period_end=2026-09-30&format=xlsx');
        $xlsx->assertStatus(200);
        $xlsx->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $pdf = $this->get('/api/v1/reports/cash-flow?period_start=2026-09-01&period_end=2026-09-30&format=pdf');
        $pdf->assertStatus(200);
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $pdf->getContent());
    }

    public function test_a_tenants_cash_flow_statement_is_never_visible_to_another_tenant(): void
    {
        $this->registerAndReturnCredentials('cash-flow-tenant-a@example.my');
        $cashIdA = $this->createAccount('1000', 'Cash', 'Asset');
        $revenueIdA = $this->createAccount('4000', 'Sales Revenue', 'Revenue');
        $this->registerBankAccount($cashIdA, 'Cash Till');
        $this->postJson('/api/v1/incomes', [
            'amount' => '900.00', 'transaction_date' => '2026-09-05',
            'income_account_id' => $revenueIdA, 'deposit_account_id' => $cashIdA,
            'description' => 'Sales',
        ], ['Idempotency-Key' => 'cf-tenant-a-income'])->assertStatus(201);
        $this->logout();

        $this->registerAndReturnCredentials('cash-flow-tenant-b@example.my');

        $response = $this->getJson('/api/v1/reports/cash-flow?period_start=2026-09-01&period_end=2026-09-30');
        $response->assertStatus(200);
        $response->assertJsonCount(0, 'operating_lines');
        $response->assertJsonPath('cash_at_period_end.amount', '0.00');
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

    /**
     * Import & Export (AETS-008 §5.1): the identical end-to-end import
     * flow, resolved via a real XLSX workbook instead of CSV —
     * `BankStatementImportController` picks
     * {@see XlsxBankStatementParser} from the
     * uploaded file's own `.xlsx` extension.
     */
    public function test_registering_a_bank_account_and_importing_an_xlsx_statement_end_to_end(): void
    {
        $this->registerAndReturnCredentials('bank-import-xlsx@example.my');
        $bankAccountId = $this->createAccount('1010', 'Bank', 'Asset');

        $registeredBankAccountId = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $bankAccountId,
            'bank_name' => 'Maybank',
            'account_number_last4' => '1234',
        ])->json('id');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [
            ['date', 'description', 'amount', 'direction', 'balance', 'reference'],
            ['2026-08-01', 'Salary credit', '3000.00', 'IN', '3000.00', 'REF001'],
            ['2026-08-02', 'Rent payment', '1200.00', 'OUT', '1800.00', 'REF002'],
        ];
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 1], $value, DataType::TYPE_STRING);
            }
        }
        $tempPath = tempnam(sys_get_temp_dir(), 'bank-import-http-test-');
        (new Xlsx($spreadsheet))->save($tempPath);
        $xlsxBytes = file_get_contents($tempPath);
        unlink($tempPath);

        $file = UploadedFile::fake()->createWithContent('statement.xlsx', (string) $xlsxBytes);

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

    /**
     * AETS-008 §12.10 (decided 2026-09-19): the identical end-to-end
     * import flow, resolved via a synthetic PDF built to exercise
     * Maybank's own real statement layout (`BEGINNING BALANCE`,
     * `DD/MM/YY` dates, an amount-plus-trailing-sign column, narrative
     * continuation lines, and a closing `ENDING BALANCE`/`TOTAL
     * CREDIT`/`TOTAL DEBIT` summary this parser cross-validates
     * against) — never real bank data. `BankStatementImportController`
     * picks {@see MaybankPdfBankStatementParser}
     * from the uploaded file's own `.pdf` extension.
     */
    public function test_registering_a_bank_account_and_importing_a_maybank_pdf_statement_end_to_end(): void
    {
        $this->registerAndReturnCredentials('bank-import-maybank-pdf@example.my');
        $bankAccountId = $this->createAccount('1010', 'Bank', 'Asset');

        $registeredBankAccountId = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $bankAccountId,
            'bank_name' => 'Maybank',
            'account_number_last4' => '1234',
        ])->json('id');

        $html = '<html><body style="font-family: sans-serif; font-size: 10px;">'
            .'<table style="width:100%; border-collapse: collapse;">'
            .'<tr><td colspan="4">URUSNIAGA AKAUN/ 戶口進支項 /ACCOUNT TRANSACTIONS</td></tr>'
            .'<tr><td>TARIKH MASUK</td><td>BUTIR URUSNIAGA</td><td>JUMLAH URUSNIAGA</td><td>BAKI PENYATA</td></tr>'
            .'<tr><td colspan="4">進支日期 進支項說明 银碼 結單存餘</td></tr>'
            .'<tr><td>ENTRY DATE</td><td>TRANSACTION DESCRIPTION</td><td>TRANSACTION AMOUNT</td><td>STATEMENT BALANCE</td></tr>'
            .'<tr><td></td><td>BEGINNING BALANCE</td><td></td><td>3,000.00</td></tr>'
            .'<tr><td>01/08/26</td><td>Salary credit</td><td>3000.00+</td><td>6,000.00</td></tr>'
            .'<tr><td>02/08/26</td><td>Rent payment</td><td>1200.00-</td><td>4,800.00</td></tr>'
            .'</table>'
            .'<p>Maybank Islamic Berhad (787435-M)</p>'
            .'<p>ENDING BALANCE : 4,800.00</p>'
            .'<p>TOTAL CREDIT : 3000.00</p>'
            .'<p>TOTAL DEBIT : 1200.00</p>'
            .'</body></html>';

        $dompdf = new Dompdf;
        $dompdf->loadHtml($html);
        $dompdf->render();
        $pdfBytes = $dompdf->output();

        $file = UploadedFile::fake()->createWithContent('statement.pdf', $pdfBytes);

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
        $transactions->assertJsonPath('data.1.description', 'Rent payment');
        $transactions->assertJsonPath('data.1.direction', 'MoneyOut');
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
        $suggestions->assertJsonPath('data.0.confidence', 'Exact');

        $bankTransactionId = $suggestions->json('data.0.bank_transaction_id');
        $journalId = $suggestions->json('data.0.journal_id');

        // Before confirmation, the transaction list itself already says
        // this row is unmatched — the signal the Work Queue's own
        // "record directly and auto-match" shortcut relies on to know
        // which rows still need one.
        $beforeConfirm = $this->getJson("/api/v1/bank-accounts/{$bankAccountId}/transactions");
        $beforeConfirm->assertJsonPath('data.0.id', $bankTransactionId);
        $beforeConfirm->assertJsonPath('data.0.matched', false);

        $confirm = $this->postJson("/api/v1/bank-transactions/{$bankTransactionId}/confirm-match", [
            'journal_id' => $journalId,
        ]);
        $confirm->assertStatus(201);
        $confirm->assertJsonPath('confidence', 'Exact');

        $this->assertSame(1, DB::connection('pgsql')->table('matches')->count());

        // Confirmed matches never resurface as suggestions.
        $this->getJson("/api/v1/bank-accounts/{$bankAccountId}/match-suggestions")->assertJsonCount(0, 'data');

        // ...and the transaction list itself now reflects it too.
        $afterConfirm = $this->getJson("/api/v1/bank-accounts/{$bankAccountId}/transactions");
        $afterConfirm->assertJsonPath('data.0.matched', true);
    }

    /**
     * BNK-018 (AETS-008 §12.6): re-confirming the exact same
     * (BankTransaction, Journal) pair deterministically replays the
     * existing Match (200, same Match ID, no second row) rather than
     * erroring; confirming a *different*, equally-eligible Journal
     * against an already-matched BankTransaction is an explicit 409
     * conflict, not a silent second Match or an ambiguous 422.
     */
    public function test_confirming_an_already_matched_bank_transaction_replays_or_conflicts_via_the_api(): void
    {
        $this->registerAndReturnCredentials('bank-match-dup@example.my');
        $bankLinkedAccountId = $this->createAccount('1010', 'Bank', 'Asset');
        $officeSuppliesId = $this->createAccount('5000', 'Office Supplies', 'Expense');
        $travelId = $this->createAccount('5010', 'Travel', 'Expense');

        $this->postJson('/api/v1/expenses', [
            'amount' => '50.00',
            'transaction_date' => '2026-08-05',
            'expense_account_id' => $officeSuppliesId,
            'payment_account_id' => $bankLinkedAccountId,
            'description' => 'Office supplies',
        ], ['Idempotency-Key' => 'key-match-expense-0002'])->assertStatus(201);

        $this->postJson('/api/v1/expenses', [
            'amount' => '50.00',
            'transaction_date' => '2026-08-05',
            'expense_account_id' => $travelId,
            'payment_account_id' => $bankLinkedAccountId,
            'description' => 'Travel',
        ], ['Idempotency-Key' => 'key-match-expense-0003'])->assertStatus(201);

        $bankAccountId = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $bankLinkedAccountId,
            'bank_name' => 'Maybank',
        ])->json('id');

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-05,Card payment,50.00,OUT,,\n";
        $this->post("/api/v1/bank-accounts/{$bankAccountId}/import", [
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
        ])->assertStatus(201);

        $suggestions = $this->getJson("/api/v1/bank-accounts/{$bankAccountId}/match-suggestions")->json('data');
        $this->assertCount(2, $suggestions);
        $bankTransactionId = $suggestions[0]['bank_transaction_id'];
        [$journalA, $journalB] = [$suggestions[0]['journal_id'], $suggestions[1]['journal_id']];

        $first = $this->postJson("/api/v1/bank-transactions/{$bankTransactionId}/confirm-match", [
            'journal_id' => $journalA,
        ]);
        $first->assertStatus(201);
        $first->assertJsonPath('is_new_match', true);
        $matchId = $first->json('id');

        $replay = $this->postJson("/api/v1/bank-transactions/{$bankTransactionId}/confirm-match", [
            'journal_id' => $journalA,
        ]);
        $replay->assertStatus(200);
        $replay->assertJsonPath('is_new_match', false);
        $replay->assertJsonPath('id', $matchId);
        $this->assertSame(1, DB::connection('pgsql')->table('matches')->count());

        $conflict = $this->postJson("/api/v1/bank-transactions/{$bankTransactionId}/confirm-match", [
            'journal_id' => $journalB,
        ]);
        $conflict->assertStatus(409);
        $this->assertSame(1, DB::connection('pgsql')->table('matches')->count());
    }

    public function test_reconciliation_full_lifecycle_via_the_api(): void
    {
        $this->registerAndReturnCredentials('reconciliation-lifecycle@example.my');
        $bankLinkedAccountId = $this->createAccount('1010', 'Bank', 'Asset');
        $revenueId = $this->createAccount('4000', 'Consulting Revenue', 'Revenue');

        $this->postJson('/api/v1/incomes', [
            'amount' => '500.00',
            'transaction_date' => '2026-08-01',
            'income_account_id' => $revenueId,
            'deposit_account_id' => $bankLinkedAccountId,
            'description' => 'Consulting revenue',
        ], ['Idempotency-Key' => 'key-reconciliation-lifecycle-income-0001'])->assertStatus(201);

        $bankAccountId = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $bankLinkedAccountId,
            'bank_name' => 'Maybank',
        ])->json('id');

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Deposit,500.00,IN,,\n";
        $this->post("/api/v1/bank-accounts/{$bankAccountId}/import", [
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
        ])->assertStatus(201);

        // BNK-016 (AETS-008 §12.1): completion requires every in-period
        // BankTransaction to hold a confirmed Match, not only a zero
        // arithmetic difference.
        $suggestion = $this->getJson("/api/v1/bank-accounts/{$bankAccountId}/match-suggestions")->json('data.0');
        $this->postJson("/api/v1/bank-transactions/{$suggestion['bank_transaction_id']}/confirm-match", [
            'journal_id' => $suggestion['journal_id'],
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
        $complete->assertJsonPath('late_unreconciled_transaction_ids', []);
        $this->assertNotNull($complete->json('completed_at'));

        // BNK-017 (AETS-008 §12.3): a late import inside the already-
        // Completed period surfaces via late_unreconciled_transaction_ids
        // without silently altering the Completed state above.
        $this->post("/api/v1/bank-accounts/{$bankAccountId}/import", [
            'statement' => UploadedFile::fake()->createWithContent('late-statement.csv', "date,description,amount,direction,balance,reference\n2026-08-20,Late bank fee,15.00,OUT,,\n"),
        ])->assertStatus(201);
        $lateBankTransactionId = DB::connection('pgsql')->table('bank_transactions')->where('description', 'Late bank fee')->value('id');

        $afterLateImport = $this->getJson("/api/v1/reconciliations/{$reconciliationId}");
        $afterLateImport->assertJsonPath('state', 'Completed');
        $afterLateImport->assertJsonPath('late_unreconciled_transaction_ids', [$lateBankTransactionId]);

        $reopen = $this->postJson("/api/v1/reconciliations/{$reconciliationId}/reopen", [
            'reason' => 'Found a missing bank fee',
        ]);
        $reopen->assertStatus(200);
        $reopen->assertJsonPath('state', 'Draft');
        $reopen->assertJsonPath('late_unreconciled_transaction_ids', []);
        $this->assertSame(1, DB::connection('pgsql')->table('reconciliation_reopenings')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('reconciliation_completion_snapshots')->count());
    }

    /**
     * BNK-020 (AETS-008 §12.9): a negative opening or closing balance
     * describes an overdraft, out of scope for this milestone — the
     * request-validation boundary fails closed with 422 before Money is
     * ever constructed, rather than accepting it and failing later,
     * confusingly, deep inside difference computation.
     */
    public function test_opening_a_reconciliation_with_a_negative_balance_is_rejected_via_the_api(): void
    {
        $this->registerAndReturnCredentials('reconciliation-negative-balance@example.my');
        $bankLinkedAccountId = $this->createAccount('1010', 'Bank', 'Asset');

        $bankAccountId = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $bankLinkedAccountId,
            'bank_name' => 'Maybank',
        ])->json('id');

        $this->postJson("/api/v1/bank-accounts/{$bankAccountId}/reconciliations", [
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'opening_balance' => '-100.00',
            'closing_balance' => '1500.00',
        ])->assertStatus(422);

        $this->postJson("/api/v1/bank-accounts/{$bankAccountId}/reconciliations", [
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'opening_balance' => '1000.00',
            'closing_balance' => '-1.00',
        ])->assertStatus(422);

        $this->assertSame(0, DB::connection('pgsql')->table('reconciliations')->count());
    }

    /**
     * BNK-015 (AETS-008 §12.4): opening a Reconciliation whose period
     * overlaps an existing one for the same Bank Account is rejected
     * with 422, not silently allowed.
     */
    public function test_opening_an_overlapping_reconciliation_period_is_rejected_via_the_api(): void
    {
        $this->registerAndReturnCredentials('reconciliation-overlap@example.my');
        $bankLinkedAccountId = $this->createAccount('1010', 'Bank', 'Asset');

        $bankAccountId = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $bankLinkedAccountId,
            'bank_name' => 'Maybank',
        ])->json('id');

        $this->postJson("/api/v1/bank-accounts/{$bankAccountId}/reconciliations", [
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'opening_balance' => '1000.00',
            'closing_balance' => '1000.00',
        ])->assertStatus(201);

        $overlapping = $this->postJson("/api/v1/bank-accounts/{$bankAccountId}/reconciliations", [
            'period_start' => '2026-08-15',
            'period_end' => '2026-09-15',
            'opening_balance' => '1000.00',
            'closing_balance' => '1000.00',
        ]);
        $overlapping->assertStatus(422);
        $this->assertSame(1, DB::connection('pgsql')->table('reconciliations')->count());
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

    public function test_banking_endpoints_do_not_expose_or_accept_another_tenants_records(): void
    {
        $this->registerAndReturnCredentials('banking-isolation-a@example.my');
        $linkedAccountId = $this->createAccount('1010', 'Tenant A Bank', 'Asset');
        $bankAccountId = $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $linkedAccountId,
            'bank_name' => 'Tenant A Bank',
        ])->json('id');

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Deposit,500.00,IN,,TENANT-A-001\n";
        $this->post("/api/v1/bank-accounts/{$bankAccountId}/import", [
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
        ])->assertStatus(201);

        $bankTransactionId = DB::connection('pgsql')->table('bank_transactions')->value('id');
        $reconciliationId = $this->postJson("/api/v1/bank-accounts/{$bankAccountId}/reconciliations", [
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'opening_balance' => '1000.00',
            'closing_balance' => '1500.00',
        ])->json('id');

        $this->logout();
        $this->registerAndReturnCredentials('banking-isolation-b@example.my');

        $this->getJson("/api/v1/bank-accounts/{$bankAccountId}/transactions")->assertStatus(404);
        $this->post("/api/v1/bank-accounts/{$bankAccountId}/import", [
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
        ])->assertStatus(404);
        $this->getJson("/api/v1/bank-accounts/{$bankAccountId}/match-suggestions")->assertStatus(404);
        $this->getJson("/api/v1/bank-accounts/{$bankAccountId}/reconciliations")->assertStatus(404);
        $this->postJson("/api/v1/bank-accounts/{$bankAccountId}/reconciliations", [
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'opening_balance' => '1000.00',
            'closing_balance' => '1500.00',
        ])->assertStatus(404);
        $this->getJson("/api/v1/reconciliations/{$reconciliationId}")->assertStatus(404);
        $this->postJson("/api/v1/reconciliations/{$reconciliationId}/start-review")->assertStatus(404);
        $this->postJson("/api/v1/bank-transactions/{$bankTransactionId}/confirm-match", [
            'journal_id' => 'journal-not-visible',
        ])->assertStatus(422);

        $this->assertSame(1, DB::connection('pgsql')->table('bank_transactions')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('reconciliations')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('matches')->count());
    }

    public function test_banking_routes_fail_deterministically_for_malformed_and_missing_identifiers(): void
    {
        $this->registerAndReturnCredentials('banking-http-boundaries@example.my');

        $malformedId = str_repeat('x', 65);
        $validStatement = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Deposit,10.00,IN,,BOUNDARY-001\n";
        $openPayload = [
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'opening_balance' => '1000.00',
            'closing_balance' => '1010.00',
        ];

        $this->getJson("/api/v1/bank-accounts/{$malformedId}/transactions")->assertStatus(422);
        $this->post("/api/v1/bank-accounts/{$malformedId}/import", [
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', $validStatement),
        ])->assertStatus(422);
        $this->getJson("/api/v1/bank-accounts/{$malformedId}/match-suggestions")->assertStatus(422);
        $this->getJson("/api/v1/bank-accounts/{$malformedId}/reconciliations")->assertStatus(422);
        $this->postJson("/api/v1/bank-accounts/{$malformedId}/reconciliations", $openPayload)->assertStatus(422);
        $this->postJson("/api/v1/bank-transactions/{$malformedId}/confirm-match", [
            'journal_id' => 'journal-canonical-but-missing',
        ])->assertStatus(422);
        $this->getJson("/api/v1/reconciliations/{$malformedId}")->assertStatus(422);
        $this->postJson("/api/v1/reconciliations/{$malformedId}/start-review")->assertStatus(422);
        $this->postJson("/api/v1/reconciliations/{$malformedId}/mark-balanced")->assertStatus(422);
        $this->postJson("/api/v1/reconciliations/{$malformedId}/complete")->assertStatus(422);
        $this->postJson("/api/v1/reconciliations/{$malformedId}/reopen", [
            'reason' => 'Boundary proof',
        ])->assertStatus(422);

        $missingBankAccountId = 'bank-account-canonical-but-missing';
        $missingReconciliationId = 'reconciliation-canonical-but-missing';

        $this->getJson("/api/v1/bank-accounts/{$missingBankAccountId}/transactions")->assertStatus(404);
        $this->post("/api/v1/bank-accounts/{$missingBankAccountId}/import", [
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', $validStatement),
        ])->assertStatus(404);
        $this->getJson("/api/v1/bank-accounts/{$missingBankAccountId}/match-suggestions")->assertStatus(404);
        $this->getJson("/api/v1/bank-accounts/{$missingBankAccountId}/reconciliations")->assertStatus(404);
        $this->postJson("/api/v1/bank-accounts/{$missingBankAccountId}/reconciliations", $openPayload)->assertStatus(404);
        $this->getJson("/api/v1/reconciliations/{$missingReconciliationId}")->assertStatus(404);
        $this->postJson("/api/v1/reconciliations/{$missingReconciliationId}/start-review")->assertStatus(404);
        $this->postJson("/api/v1/reconciliations/{$missingReconciliationId}/mark-balanced")->assertStatus(404);
        $this->postJson("/api/v1/reconciliations/{$missingReconciliationId}/complete")->assertStatus(404);
        $this->postJson("/api/v1/reconciliations/{$missingReconciliationId}/reopen", [
            'reason' => 'Boundary proof',
        ])->assertStatus(404);

        $this->assertSame(0, DB::connection('pgsql')->table('bank_statement_import_batches')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('bank_transactions')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('matches')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('reconciliations')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('reconciliation_reopenings')->count());
    }

    public function test_every_banking_route_rejects_unauthenticated_access(): void
    {
        $bankAccountId = 'bank-account-without-session';
        $bankTransactionId = 'bank-transaction-without-session';
        $reconciliationId = 'reconciliation-without-session';

        $this->getJson('/api/v1/bank-accounts')->assertStatus(401);
        $this->postJson('/api/v1/bank-accounts')->assertStatus(401);
        $this->getJson("/api/v1/bank-accounts/{$bankAccountId}/transactions")->assertStatus(401);
        $this->postJson("/api/v1/bank-accounts/{$bankAccountId}/import")->assertStatus(401);
        $this->getJson("/api/v1/bank-accounts/{$bankAccountId}/match-suggestions")->assertStatus(401);
        $this->postJson("/api/v1/bank-transactions/{$bankTransactionId}/confirm-match")->assertStatus(401);
        $this->getJson("/api/v1/bank-accounts/{$bankAccountId}/reconciliations")->assertStatus(401);
        $this->postJson("/api/v1/bank-accounts/{$bankAccountId}/reconciliations")->assertStatus(401);
        $this->getJson("/api/v1/reconciliations/{$reconciliationId}")->assertStatus(401);
        $this->postJson("/api/v1/reconciliations/{$reconciliationId}/start-review")->assertStatus(401);
        $this->postJson("/api/v1/reconciliations/{$reconciliationId}/mark-balanced")->assertStatus(401);
        $this->postJson("/api/v1/reconciliations/{$reconciliationId}/complete")->assertStatus(401);
        $this->postJson("/api/v1/reconciliations/{$reconciliationId}/reopen")->assertStatus(401);
    }

    public function test_banking_write_routes_reject_invalid_payloads_without_persistence(): void
    {
        $this->registerAndReturnCredentials('banking-validation@example.my');
        $linkedAccountId = $this->createAccount('1010', 'Bank', 'Asset');

        $this->postJson('/api/v1/bank-accounts')->assertStatus(422);
        $this->postJson('/api/v1/bank-accounts', [
            'linked_account_id' => $linkedAccountId,
            'bank_name' => 'Maybank',
            'account_number_last4' => '123',
        ])->assertStatus(422);

        $this->postJson('/api/v1/bank-accounts/bank-account-missing/import')->assertStatus(422);
        $this->post('/api/v1/bank-accounts/bank-account-missing/import', [
            'statement' => UploadedFile::fake()->create('statement.exe', 1, 'application/octet-stream'),
        ])->assertStatus(422);

        $this->postJson('/api/v1/bank-transactions/bank-transaction-missing/confirm-match')->assertStatus(422);
        $this->postJson('/api/v1/bank-transactions/bank-transaction-missing/confirm-match', [
            'journal_id' => str_repeat('j', 65),
        ])->assertStatus(422);

        $this->postJson('/api/v1/bank-accounts/bank-account-missing/reconciliations')->assertStatus(422);
        $this->postJson('/api/v1/bank-accounts/bank-account-missing/reconciliations', [
            'period_start' => '2026-08-31',
            'period_end' => '2026-08-01',
            'opening_balance' => '1000',
            'closing_balance' => '-10.00',
        ])->assertStatus(422);

        $this->postJson('/api/v1/reconciliations/reconciliation-missing/reopen')->assertStatus(422);
        $this->postJson('/api/v1/reconciliations/reconciliation-missing/reopen', [
            'reason' => str_repeat('r', 501),
        ])->assertStatus(422);

        $this->assertSame(0, DB::connection('pgsql')->table('bank_accounts')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('bank_statement_import_batches')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('matches')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('reconciliations')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('reconciliation_reopenings')->count());
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

        // The preset explicitly names every category Master Context §7
        // requires an expense be trackable by — bahan mentah, sewa,
        // utiliti, gaji.
        $presetNames = DB::connection('pgsql')->table('accounts')->pluck('account_name')->all();
        foreach (['Raw Materials & Supplies', 'Rent Expense', 'Utilities Expense', 'Salaries and Wages'] as $expected) {
            $this->assertContains($expected, $presetNames);
        }
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

    public function test_the_current_period_watermark_reflects_the_latest_closure_and_is_tenant_isolated(): void
    {
        $this->registerAndReturnCredentials('period-watermark@example.my');
        $cashId = $this->createAccount('1000', 'Cash', 'Asset');
        $revenueId = $this->createAccount('4000', 'Consulting Revenue', 'Revenue');
        $retainedEarningsId = $this->createAccount('3900', 'Retained Earnings', 'Equity');

        $before = $this->getJson('/api/v1/periods/current');
        $before->assertStatus(200);
        $before->assertJsonPath('closed_through_date', null);
        $before->assertJsonPath('closing_journal_id', null);

        $this->postJson('/api/v1/incomes', [
            'amount' => '200.00',
            'transaction_date' => '2026-08-15',
            'income_account_id' => $revenueId,
            'deposit_account_id' => $cashId,
            'description' => 'Consulting revenue',
        ], ['Idempotency-Key' => 'key-watermark-income-0001'])->assertStatus(201);

        $close = $this->postJson('/api/v1/periods/close', [
            'closed_through_date' => '2026-08-31',
            'retained_earnings_account_id' => $retainedEarningsId,
        ], ['Idempotency-Key' => 'key-watermark-close-0001']);
        $close->assertStatus(201);

        $after = $this->getJson('/api/v1/periods/current');
        $after->assertStatus(200);
        $after->assertJsonPath('closed_through_date', '2026-08-31');
        $after->assertJsonPath('closing_journal_id', $close->json('closing_journal_id'));

        $this->registerAndReturnCredentials('period-watermark-tenant-b@example.my');
        $tenantB = $this->getJson('/api/v1/periods/current');
        $tenantB->assertStatus(200);
        $tenantB->assertJsonPath('closed_through_date', null);
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

    // --- Evidence (AETS-015) --------------------------------------------

    public function test_uploading_and_downloading_evidence_via_the_api(): void
    {
        $this->registerAndReturnCredentials('evidence-upload@example.my');

        $contents = 'this is a test receipt fixture, not a real accounting oracle';
        $upload = $this->post('/api/v1/evidence', [
            'file' => UploadedFile::fake()->createWithContent('receipt.jpg', $contents),
        ]);

        $upload->assertStatus(201);
        $upload->assertJsonPath('original_filename', 'receipt.jpg');
        $upload->assertJsonPath('sha256_digest', hash('sha256', $contents));
        $upload->assertJsonPath('byte_size', strlen($contents));

        $evidenceId = $upload->json('id');

        $download = $this->get("/api/v1/evidence/{$evidenceId}");
        $download->assertStatus(200);
        $download->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame($contents, $download->getContent());
    }

    public function test_uploading_a_disallowed_file_type_is_rejected(): void
    {
        $this->registerAndReturnCredentials('evidence-bad-type@example.my');

        $response = $this->post('/api/v1/evidence', [
            'file' => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::connection('pgsql')->table('evidence')->count());
    }

    public function test_uploading_an_oversized_file_is_rejected(): void
    {
        $this->registerAndReturnCredentials('evidence-too-big@example.my');

        $response = $this->post('/api/v1/evidence', [
            'file' => UploadedFile::fake()->create('receipt.jpg', 10241, 'image/jpeg'),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::connection('pgsql')->table('evidence')->count());
    }

    public function test_evidence_is_tenant_isolated_via_the_api(): void
    {
        $this->registerAndReturnCredentials('evidence-tenant-a@example.my');
        $upload = $this->post('/api/v1/evidence', [
            'file' => UploadedFile::fake()->createWithContent('receipt.jpg', 'tenant A receipt'),
        ]);
        $evidenceId = $upload->json('id');

        $this->logout();
        $this->registerAndReturnCredentials('evidence-tenant-b@example.my');

        $this->getJson("/api/v1/evidence/{$evidenceId}")->assertStatus(404);
    }

    public function test_evidence_upload_requires_authentication(): void
    {
        $this->postJson('/api/v1/evidence', [])->assertStatus(401);
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

    private function issueInvoice(string $customerId, string $receivableAccountId, string $revenueAccountId, string $unitPrice): string
    {
        $draft = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableAccountId,
            'revenue_account_id' => $revenueAccountId,
            'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => $unitPrice]],
        ]);
        $draft->assertStatus(201);

        /** @var string $invoiceId */
        $invoiceId = $draft->json('id');

        $issued = $this->postJson("/api/v1/invoices/{$invoiceId}/issue", ['issue_date' => now()->toDateString()], ['Idempotency-Key' => 'key-issue-'.$invoiceId]);
        $issued->assertStatus(201);

        return $invoiceId;
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
