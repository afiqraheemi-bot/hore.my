<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the two Supplier fields AETS-013 v0.1.0 §6 names as mandatory
 * for a MyInvois document that `business_profiles` did not previously
 * need to carry: MSIC code (business-nature classification) and SST
 * registration number. Both nullable, mirroring
 * `business_profiles.registration_number`/`tin`'s own reasoning — a
 * Tenant who has not yet configured MyInvois, or is not SST-registered,
 * still has a valid, saveable Business Profile.
 */
return new class extends Migration
{
    private const TABLE = 'business_profiles';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('msic_code', 5)->nullable()->after('business_type');
            $table->string('sst_registration_number', 64)->nullable()->after('msic_code');
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(['msic_code', 'sst_registration_number']);
        });
    }
};
