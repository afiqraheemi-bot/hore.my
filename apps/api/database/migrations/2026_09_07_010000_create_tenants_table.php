<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0008: a Tenant owns a reference to its single owning User
 * (`owner_user_id`), not the reverse — `users` carries no `tenant_id`
 * column. `owner_user_id` is unique, expressing "one owner per Tenant"
 * at the schema level, matching MVP's single-owner scope
 * (`HORE_MY_MASTER_CONTEXT.md` §9: no multi-user approval).
 */
return new class extends Migration
{
    private const TABLE = 'tenants';

    private const USER_TABLE = 'users';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('owner_user_id', 64)->unique();
            $table->timestamps();

            $table->foreign('owner_user_id')
                ->references('id')->on(self::USER_TABLE)
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
