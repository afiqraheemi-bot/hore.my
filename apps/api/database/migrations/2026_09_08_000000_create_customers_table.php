<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for Customer (M19, Modul 7 foundation — "Pelanggan,
 * sebut harga, invois dan bayaran") — a Tenant's own record of a
 * customer they sell to.
 *
 * **Pure reference data, no FK onto `journals` or `accounts`.**
 * Registering a Customer produces no Journal — Invoicing (a future
 * milestone) is what will eventually post against a Customer's
 * receivable balance. Mirrors `bank_accounts`' own "reference data"
 * shape, minus the linked-Account FK (a Customer has none).
 */
return new class extends Migration
{
    private const TABLE = 'customers';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('name', 255);
            $table->string('email', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('tax_identification_number', 64)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->primary('id');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
