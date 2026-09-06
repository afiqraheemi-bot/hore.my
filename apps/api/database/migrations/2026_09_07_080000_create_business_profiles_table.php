<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for Business Profile (M16, SRS IAM-003) — the
 * per-Tenant record of legal name, registration number, TIN, address,
 * business type, and financial year start month a Malaysian
 * micro-business/SME provides during onboarding.
 *
 * **`tenant_id` is the primary key, not a separate surrogate `id`.** A
 * Business Profile is a strict 1:1 extension of its Tenant (MVP has
 * exactly one owner, one profile, per SRS §9's single-owner scope) —
 * there is never a reason to address a Business Profile independently
 * of the Tenant it describes, so no separate identity is invented for
 * it, mirroring `tenants.id` itself being the Tenant's sole identity.
 *
 * **`timezone` carries a fixed default, not a user-selectable value.**
 * Master Context locks this MVP to Malaysia-only operation — there is
 * exactly one timezone (`Asia/Kuala_Lumpur`) any Tenant could ever
 * have, so SRS IAM-003's "zon masa hendaklah direkod" (timezone shall
 * be recorded) is satisfied by recording the one correct constant, not
 * by building a picker for a choice that cannot meaningfully vary.
 *
 * **`registration_number`/`tin` are nullable.** A newly-registered sole
 * proprietor may not yet have a business registration number or a Tax
 * Identification Number at the moment they start using hore.my —
 * `BusinessProfile::isComplete()` (the Eloquent model) is the single
 * place that decides whether a profile counts as "complete" for SRS
 * IAM-003's "kelengkapan profil dikuatkuasa" (completeness enforced);
 * this schema itself only enforces that a legal name and address exist
 * once a profile row exists at all.
 *
 * **`business_type`, `state` each carry a `CHECK (... IN (...))`
 * constraint**, mirroring the canonical-value constraint convention
 * `2026_09_04_030000_create_accounts_table.php` already establishes.
 */
return new class extends Migration
{
    private const TABLE = 'business_profiles';

    private const TENANT_TABLE = 'tenants';

    private const BUSINESS_TYPE_CHECK_CONSTRAINT = 'business_profiles_business_type_canonical';

    private const STATE_CHECK_CONSTRAINT = 'business_profiles_state_canonical';

    private const FINANCIAL_YEAR_START_MONTH_CHECK_CONSTRAINT = 'business_profiles_financial_year_start_month_range';

    /**
     * @var list<string>
     */
    private const BUSINESS_TYPES = ['Retail', 'FoodAndBeverage', 'Services', 'Trading', 'Other'];

    /**
     * The 13 states and 3 Federal Territories of Malaysia — Master
     * Context locks this MVP to Malaysia-only operation, so this list
     * is exhaustive by design, not a starting subset.
     *
     * @var list<string>
     */
    private const STATES = [
        'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang',
        'Perak', 'Perlis', 'Pulau Pinang', 'Sabah', 'Sarawak', 'Selangor', 'Terengganu',
        'WP Kuala Lumpur', 'WP Labuan', 'WP Putrajaya',
    ];

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('tenant_id', 64)->primary();
            $table->string('legal_name', 255);
            $table->string('registration_number', 64)->nullable();
            $table->string('tin', 32)->nullable();
            $table->string('address_line1', 255);
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 100);
            $table->string('state', 32);
            $table->string('postcode', 5);
            $table->string('business_type', 32);
            $table->unsignedTinyInteger('financial_year_start_month');
            $table->string('timezone', 32)->default('Asia/Kuala_Lumpur');
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')->on(self::TENANT_TABLE)
                ->cascadeOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (business_type in (%s))',
                self::TABLE,
                self::BUSINESS_TYPE_CHECK_CONSTRAINT,
                self::sqlStringList(self::BUSINESS_TYPES),
            ));

            DB::statement(sprintf(
                'alter table %s add constraint %s check (state in (%s))',
                self::TABLE,
                self::STATE_CHECK_CONSTRAINT,
                self::sqlStringList(self::STATES),
            ));

            DB::statement(sprintf(
                'alter table %s add constraint %s check (financial_year_start_month between 1 and 12)',
                self::TABLE,
                self::FINANCIAL_YEAR_START_MONTH_CHECK_CONSTRAINT,
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * @param  list<string>  $values
     */
    private static function sqlStringList(array $values): string
    {
        return implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            $values,
        ));
    }
};
