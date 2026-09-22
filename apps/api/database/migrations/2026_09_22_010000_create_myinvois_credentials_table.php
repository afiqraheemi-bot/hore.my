<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for `MyInvoisCredential` (AETS-013 v0.1.0) — a
 * Tenant's own LHDN MyInvois client ID/secret, stored separately per
 * Environment so Sandbox and Production credentials are never mixed
 * (`HORE_MY_MASTER_CONTEXT.md` §13, MYI-006).
 *
 * **A surrogate `id`, not a composite primary key on
 * `(tenant_id, environment)`.** Eloquent has no first-class support for
 * composite primary keys, and the natural key here is genuinely two
 * columns — rather than fight the framework, this table takes the same
 * approach `bank_accounts`/`accounts` already do (an opaque `id`) and
 * enforces the actual invariant (at most one row per Tenant per
 * Environment, MYI-001) with a real unique constraint instead of
 * relying on the primary key shape to express it.
 *
 * **`client_secret` is `text`, not `string`, and is application-layer
 * encrypted.** Eloquent's own `encrypted` cast (Laravel's standard
 * AES-256-GCM envelope via `APP_KEY`) is used on the model — its
 * ciphertext is base64-encoded JSON, comfortably wider than a raw
 * secret, hence `text` rather than a fixed-length `string`. This is the
 * first column in this codebase encrypted at rest (AETS-013 §5,
 * MYI-002) — `bank_accounts.account_number_last4` sidesteps the same
 * concern by never storing the sensitive value at all, which doesn't
 * work here since a client secret must be usable, not just referenced.
 */
return new class extends Migration
{
    private const TABLE = 'myinvois_credentials';

    private const TENANT_TABLE = 'tenants';

    private const ENVIRONMENT_CHECK_CONSTRAINT = 'myinvois_credentials_environment_canonical';

    private const TENANT_ENVIRONMENT_UNIQUE_CONSTRAINT = 'myinvois_credentials_tenant_id_environment_unique';

    /**
     * @var list<string>
     */
    private const ENVIRONMENTS = ['Sandbox', 'Production'];

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('tenant_id', 64);
            $table->string('environment', 16);
            $table->string('client_id', 255);
            $table->text('client_secret');
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')->on(self::TENANT_TABLE)
                ->cascadeOnDelete();

            $table->unique(['tenant_id', 'environment'], self::TENANT_ENVIRONMENT_UNIQUE_CONSTRAINT);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (environment in (%s))',
                self::TABLE,
                self::ENVIRONMENT_CHECK_CONSTRAINT,
                implode(', ', array_map(static fn (string $value): string => "'".$value."'", self::ENVIRONMENTS)),
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
