<?php

declare(strict_types=1);

namespace Tests\Feature\MyInvois;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Real-HTTP proof that `InvoiceController` correctly persists and
 * returns the optional per-line MyInvois data (AETS-013 v0.1.0 §6.1)
 * — the increment explicitly deferred when AETS-013 v0.1.0 first
 * shipped ("wiring MyInvoisLineDetail into the live Invoice
 * create/update UI"). `InvoiceLine`/`InvoiceRepository` themselves are
 * never touched by this feature — these tests prove the *separate*
 * `myinvois_invoice_line_details` table round-trips correctly through
 * the same HTTP endpoints Invoice create/update already use.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason (mirrors the established convention).
 */
final class MyInvoisInvoiceLineDetailManagementTest extends TestCase
{
    private const TABLES_TO_CLEAN = [
        'myinvois_invoice_line_details',
        'invoice_lines',
        'invoices',
        'invoice_number_sequences',
        'customers',
        'accounts',
        'business_profiles',
        'consents',
        'users',
        'tenants',
    ];

    private static ?string $skipReason = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason === null) {
            try {
                DB::connection('pgsql')->select('select 1');
            } catch (\Throwable $e) {
                self::$skipReason = sprintf(
                    'A real PostgreSQL instance is not reachable via the "pgsql" connection (%s). '
                    .'Run `docker compose up -d postgres` (see docker-compose.yml) to enable this integration test.',
                    $e->getMessage(),
                );
            }
        }

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        if (! Schema::connection('pgsql')->hasTable('myinvois_invoice_line_details')) {
            $this->markTestSkipped('The "myinvois_invoice_line_details" table does not exist — run `php artisan migrate` against the "pgsql" connection.');
        }

        foreach (self::TABLES_TO_CLEAN as $table) {
            DB::connection('pgsql')->table($table)->delete();
        }

        $this->withHeaders(['Origin' => 'http://localhost:3000']);
        config(['sanctum.middleware.validate_csrf_token' => null]);
    }

    public function test_creating_an_invoice_with_myinvois_line_data_persists_and_returns_it(): void
    {
        $this->registerTenant('myinvois-lines-create@example.my');
        [$receivableId, $revenueId, $customerId] = $this->fixtures();

        $response = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
            'lines' => [
                [
                    'description' => 'Consulting',
                    'quantity' => 2,
                    'unit_price' => '150.00',
                    'myinvois_tax_type' => '02',
                    'myinvois_tax_rate' => '6.00',
                    'myinvois_tax_amount' => '18.00',
                    'myinvois_classification_code' => '9309000000',
                    'myinvois_unit_of_measure' => 'DAY',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('lines.0.myinvois_tax_type', '02');
        $response->assertJsonPath('lines.0.myinvois_tax_rate', '6.00');
        $response->assertJsonPath('lines.0.myinvois_tax_amount', '18.00');
        $response->assertJsonPath('lines.0.myinvois_classification_code', '9309000000');
        $response->assertJsonPath('lines.0.myinvois_unit_of_measure', 'DAY');

        $this->assertSame(1, DB::connection('pgsql')->table('myinvois_invoice_line_details')->count());
    }

    public function test_creating_an_invoice_without_myinvois_data_persists_no_row(): void
    {
        $this->registerTenant('myinvois-lines-absent@example.my');
        [$receivableId, $revenueId, $customerId] = $this->fixtures();

        $response = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
            'lines' => [
                ['description' => 'Consulting', 'quantity' => 1, 'unit_price' => '100.00'],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('lines.0.myinvois_tax_type'));
        $this->assertSame(0, DB::connection('pgsql')->table('myinvois_invoice_line_details')->count());
    }

    public function test_a_line_with_some_but_not_all_myinvois_fields_is_rejected(): void
    {
        $this->registerTenant('myinvois-lines-partial@example.my');
        [$receivableId, $revenueId, $customerId] = $this->fixtures();

        $response = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
            'lines' => [
                [
                    'description' => 'Consulting',
                    'quantity' => 1,
                    'unit_price' => '100.00',
                    'myinvois_tax_type' => '02',
                    // tax_rate, tax_amount, classification_code, unit_of_measure all missing.
                ],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::connection('pgsql')->table('invoices')->count());
    }

    public function test_updating_an_invoice_replaces_myinvois_line_data_wholesale(): void
    {
        $this->registerTenant('myinvois-lines-update@example.my');
        [$receivableId, $revenueId, $customerId] = $this->fixtures();

        $draft = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
            'lines' => [
                [
                    'description' => 'Consulting',
                    'quantity' => 1,
                    'unit_price' => '100.00',
                    'myinvois_tax_type' => '02',
                    'myinvois_tax_rate' => '6.00',
                    'myinvois_tax_amount' => '6.00',
                    'myinvois_classification_code' => '9309000000',
                    'myinvois_unit_of_measure' => 'DAY',
                ],
            ],
        ]);
        $draft->assertStatus(201);
        $invoiceId = $draft->json('id');

        $updated = $this->putJson("/api/v1/invoices/{$invoiceId}", [
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
            'lines' => [
                ['description' => 'Hardware', 'quantity' => 1, 'unit_price' => '50.00'],
            ],
        ]);

        $updated->assertStatus(200);
        $this->assertNull($updated->json('lines.0.myinvois_tax_type'));
        $this->assertSame(0, DB::connection('pgsql')->table('myinvois_invoice_line_details')->count());
    }

    public function test_show_returns_persisted_myinvois_line_data(): void
    {
        $this->registerTenant('myinvois-lines-show@example.my');
        [$receivableId, $revenueId, $customerId] = $this->fixtures();

        $draft = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'due_date' => '2026-12-31',
            'receivable_account_id' => $receivableId,
            'revenue_account_id' => $revenueId,
            'lines' => [
                [
                    'description' => 'Consulting',
                    'quantity' => 1,
                    'unit_price' => '100.00',
                    'myinvois_tax_type' => '06',
                    'myinvois_tax_rate' => '0.00',
                    'myinvois_tax_amount' => '0.00',
                    'myinvois_classification_code' => '9309000000',
                    'myinvois_unit_of_measure' => 'DAY',
                ],
            ],
        ]);
        $invoiceId = $draft->json('id');

        $show = $this->getJson("/api/v1/invoices/{$invoiceId}");

        $show->assertStatus(200);
        $show->assertJsonPath('lines.0.myinvois_tax_type', '06');
        $show->assertJsonPath('lines.0.myinvois_classification_code', '9309000000');
    }

    /**
     * @return array{0: string, 1: string, 2: string} [receivableAccountId, revenueAccountId, customerId]
     */
    private function fixtures(): array
    {
        $receivable = $this->postJson('/api/v1/accounts', [
            'account_code' => '1100',
            'account_name' => 'Accounts Receivable',
            'account_type' => 'Asset',
        ]);
        $receivable->assertStatus(201);

        $revenue = $this->postJson('/api/v1/accounts', [
            'account_code' => '4100',
            'account_name' => 'Service Revenue',
            'account_type' => 'Revenue',
        ]);
        $revenue->assertStatus(201);

        $customer = $this->postJson('/api/v1/customers', ['name' => 'Kedai Runcit Aminah']);
        $customer->assertStatus(201);

        return [$receivable->json('id'), $revenue->json('id'), $customer->json('id')];
    }

    private function registerTenant(string $email): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
        ])->assertStatus(201);

        Auth::forgetGuards();
    }
}
