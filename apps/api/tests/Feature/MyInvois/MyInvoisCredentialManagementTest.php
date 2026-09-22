<?php

declare(strict_types=1);

namespace Tests\Feature\MyInvois;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Http\Api\IdentityAndAccountingApiTest;
use Tests\TestCase;

/**
 * Real-HTTP proof of `MyInvoisCredentialController` (AETS-013 v0.1.0
 * §5, ATS-013 §4.1) — persistence, per-Environment uniqueness, secret
 * encryption, and Tenant isolation, against a real PostgreSQL
 * instance, never mocked.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason (mirrors the established convention,
 * {@see IdentityAndAccountingApiTest}).
 */
final class MyInvoisCredentialManagementTest extends TestCase
{
    private const TABLES_TO_CLEAN = [
        'myinvois_credentials',
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

        if (! Schema::connection('pgsql')->hasTable('myinvois_credentials')) {
            $this->markTestSkipped('The "myinvois_credentials" table does not exist — run `php artisan migrate` against the "pgsql" connection.');
        }

        foreach (self::TABLES_TO_CLEAN as $table) {
            DB::connection('pgsql')->table($table)->delete();
        }

        $this->withHeaders(['Origin' => 'http://localhost:3000']);
        config(['sanctum.middleware.validate_csrf_token' => null]);
    }

    // MYI-T001, MYI-T006
    public function test_saving_a_sandbox_credential_persists_a_row_scoped_to_the_tenant_and_environment(): void
    {
        $this->registerTenant('sandbox-save@example.my');

        $response = $this->putJson('/api/v1/myinvois/credentials', [
            'environment' => 'Sandbox',
            'client_id' => 'sandbox-client-id',
            'client_secret' => 'sandbox-client-secret',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.environment', 'Sandbox');
        $response->assertJsonPath('data.configured', true);
        $response->assertJsonPath('data.client_id', 'sandbox-client-id');
        $this->assertArrayNotHasKey('client_secret', $response->json('data'));

        $this->assertSame(1, DB::connection('pgsql')->table('myinvois_credentials')->count());
    }

    // MYI-T002 (MYI-001)
    public function test_saving_again_for_the_same_environment_replaces_the_prior_row_instead_of_duplicating(): void
    {
        $this->registerTenant('sandbox-replace@example.my');

        $this->putJson('/api/v1/myinvois/credentials', [
            'environment' => 'Sandbox',
            'client_id' => 'first-client-id',
            'client_secret' => 'first-secret',
        ])->assertStatus(201);

        $response = $this->putJson('/api/v1/myinvois/credentials', [
            'environment' => 'Sandbox',
            'client_id' => 'second-client-id',
            'client_secret' => 'second-secret',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.client_id', 'second-client-id');

        $this->assertSame(1, DB::connection('pgsql')->table('myinvois_credentials')->count());
        $this->assertSame(
            'second-client-id',
            DB::connection('pgsql')->table('myinvois_credentials')->value('client_id'),
        );
    }

    // MYI-T003 (MYI-002)
    public function test_the_stored_client_secret_is_not_plaintext(): void
    {
        $this->registerTenant('secret-encrypted@example.my');

        $this->putJson('/api/v1/myinvois/credentials', [
            'environment' => 'Sandbox',
            'client_id' => 'client-id',
            'client_secret' => 'super-secret-value',
        ])->assertStatus(201);

        $rawSecret = DB::connection('pgsql')->table('myinvois_credentials')->value('client_secret');

        $this->assertNotSame('super-secret-value', $rawSecret);
        $this->assertStringNotContainsString('super-secret-value', (string) $rawSecret);
    }

    // MYI-T004 (MYI-002)
    public function test_reading_credentials_never_exposes_the_full_secret(): void
    {
        $this->registerTenant('secret-masked@example.my');

        $this->putJson('/api/v1/myinvois/credentials', [
            'environment' => 'Production',
            'client_id' => 'prod-client-id',
            'client_secret' => 'do-not-leak-me',
        ])->assertStatus(201);

        $response = $this->getJson('/api/v1/myinvois/credentials');

        $response->assertStatus(200);
        $body = json_encode($response->json());
        $this->assertIsString($body);
        $this->assertStringNotContainsString('do-not-leak-me', $body);
    }

    // MYI-T005 (MYI-006)
    public function test_saving_a_sandbox_credential_does_not_alter_an_existing_production_credential(): void
    {
        $this->registerTenant('env-isolated@example.my');

        $this->putJson('/api/v1/myinvois/credentials', [
            'environment' => 'Production',
            'client_id' => 'prod-client-id',
            'client_secret' => 'prod-secret',
        ])->assertStatus(201);

        $this->putJson('/api/v1/myinvois/credentials', [
            'environment' => 'Sandbox',
            'client_id' => 'sandbox-client-id',
            'client_secret' => 'sandbox-secret',
        ])->assertStatus(201);

        $response = $this->getJson('/api/v1/myinvois/credentials');

        $byEnvironment = collect($response->json('data'))->keyBy('environment');
        $this->assertSame('prod-client-id', $byEnvironment->get('Production')['client_id']);
        $this->assertSame('sandbox-client-id', $byEnvironment->get('Sandbox')['client_id']);
        $this->assertSame(2, DB::connection('pgsql')->table('myinvois_credentials')->count());
    }

    // MYI-T006 (tenant isolation)
    public function test_a_credential_saved_under_one_tenant_is_never_visible_to_another(): void
    {
        $this->registerTenant('tenant-a-credential@example.my');
        $this->putJson('/api/v1/myinvois/credentials', [
            'environment' => 'Sandbox',
            'client_id' => 'tenant-a-client-id',
            'client_secret' => 'tenant-a-secret',
        ])->assertStatus(201);

        $this->logout();
        $this->registerTenant('tenant-b-credential@example.my');

        $response = $this->getJson('/api/v1/myinvois/credentials');
        $byEnvironment = collect($response->json('data'))->keyBy('environment');

        $this->assertFalse($byEnvironment->get('Sandbox')['configured']);
        $this->assertNull($byEnvironment->get('Sandbox')['client_id']);
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

        $this->forgetCachedAuthGuards();
    }

    private function logout(): void
    {
        $this->postJson('/api/v1/logout')->assertStatus(204);

        $this->forgetCachedAuthGuards();
    }

    /**
     * See {@see IdentityAndAccountingApiTest::forgetCachedAuthGuards()}
     * for why this is needed within a single test method.
     */
    private function forgetCachedAuthGuards(): void
    {
        Auth::forgetGuards();
    }
}
