<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for Consent (M16, SRS IAM-001: "merekod
 * persetujuan" — record consent) — an append-only record of a User's
 * acceptance of a versioned policy document (Terms of Service, Privacy
 * Notice) at registration, per SRS §5 Data Requirements naming `Consent`
 * as its own Identity/Tenant entity distinct from `User`.
 *
 * **Append-only, never updated.** A later policy version is a *new*
 * row, never an edit to a previous acceptance — mirroring this
 * codebase's established `audit_events` immutability convention
 * (AETS-010 §9): a User's consent history must remain a truthful record
 * of what was actually accepted and when.
 *
 * **`consent_type`/`version` are plain strings, not foreign keys** — no
 * separate "policy document" table exists yet; the version string is
 * the sole reference to which specific document text was accepted. A
 * future Legal/Compliance module may formalize this further.
 */
return new class extends Migration
{
    private const TABLE = 'consents';

    private const USER_TABLE = 'users';

    private const CONSENT_TYPE_CHECK_CONSTRAINT = 'consents_consent_type_canonical';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('user_id', 64);
            $table->string('consent_type', 32);
            $table->string('version', 16);
            $table->timestamp('accepted_at');

            $table->foreign('user_id')
                ->references('id')->on(self::USER_TABLE)
                ->cascadeOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                "alter table %s add constraint %s check (consent_type in ('terms_of_service', 'privacy_notice'))",
                self::TABLE,
                self::CONSENT_TYPE_CHECK_CONSTRAINT,
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
