<?php

declare(strict_types=1);

use App\Infrastructure\Invoicing\InvoiceNumberGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for a Tenant-scoped Invoice numbering sequence
 * (M20) — one row per Tenant, holding the next Invoice number to
 * assign. Advanced atomically via an `INSERT ... ON CONFLICT DO
 * UPDATE ... RETURNING` upsert inside the same transaction as the
 * Invoice it numbers ({@see InvoiceNumberGenerator}),
 * so a rolled-back Issue attempt does not permanently burn a number —
 * though a genuinely concurrent Issue race still can, which is
 * accepted: Malaysian invoicing/MyInvois requires unique, monotonically
 * increasing numbers, not strictly gapless ones.
 */
return new class extends Migration
{
    private const TABLE = 'invoice_number_sequences';

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
