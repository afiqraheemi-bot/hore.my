<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for a Tenant-scoped Quotation numbering sequence
 * (AETS-016) — one row per Tenant. Mirrors
 * `invoice_number_sequences` exactly, including its own atomic-upsert
 * and gap-tolerance reasoning ({@see
 * \App\Infrastructure\Quotations\QuotationNumberGenerator}).
 */
return new class extends Migration
{
    private const TABLE = 'quotation_number_sequences';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('tenant_id', 64);
            $table->unsignedBigInteger('next_number')->default(1);

            $table->primary('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
