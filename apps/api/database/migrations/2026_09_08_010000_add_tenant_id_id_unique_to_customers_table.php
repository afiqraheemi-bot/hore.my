<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a `(tenant_id, id)` unique constraint to `customers` (M20) —
 * `id` is already globally unique via its own primary key, but
 * PostgreSQL still requires a unique constraint whose columns exactly
 * match a composite foreign key's target before any other table can
 * declare `foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')`,
 * mirroring the identical `unique(['tenant_id', 'account_id'])` the
 * `accounts` table's own migration already added for the same reason.
 *
 * Not added in M19's original `customers` migration because nothing
 * referenced `customers` via foreign key yet — `invoices` (M20) is the
 * first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'id']);
        });
    }
};
