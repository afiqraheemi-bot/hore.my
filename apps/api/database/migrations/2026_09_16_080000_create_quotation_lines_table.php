<?php

declare(strict_types=1);

use App\Infrastructure\Quotations\QuotationRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for a Quotation's own line items (AETS-016) —
 * one row per line, ordered by `line_number` (1-based, gapless
 * within a given Quotation). Mirrors `invoice_lines` exactly,
 * including its own "quantity is a positive integer" and
 * "whole-row replace on every Draft edit" reasoning
 * ({@see QuotationRepository::replaceLines()}).
 */
return new class extends Migration
{
    private const TABLE = 'quotation_lines';

    private const QUOTATION_TABLE = 'quotations';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('quotation_id', 64);
            $table->unsignedInteger('line_number');
            $table->string('description', 500);
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_price');
            $table->bigInteger('line_amount');

            $table->primary('id');
            $table->unique(['quotation_id', 'line_number']);

            $table->foreign(['tenant_id', 'quotation_id'])
                ->references(['tenant_id', 'id'])
                ->on(self::QUOTATION_TABLE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
