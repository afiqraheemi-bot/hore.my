<?php

declare(strict_types=1);

use App\Infrastructure\Invoicing\InvoiceRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for an Invoice's own line items (M20) — one row
 * per line, ordered by `line_number` (1-based, gapless within a given
 * Invoice).
 *
 * **`quantity` is a positive integer, not a decimal.**
 * `Money::multiply()` currently only accepts `RoundingMode::Unnecessary`
 * (AETS-003 §13's own rounding policy is still deliberately deferred —
 * see that enum's own docblock) — multiplying `unit_price` by a whole
 * number never loses precision, so `quantity` is deliberately
 * restricted to a positive integer for this milestone rather than
 * inventing a rounding policy ahead of that still-open decision.
 * Fractional quantities (hours, weight) are a tracked future gap, not
 * a silent omission.
 *
 * **Whole-row replace on every Draft edit** — a Draft Invoice's lines
 * are deleted and re-inserted wholesale on every update
 * ({@see InvoiceRepository::replaceLines()}),
 * never diffed line-by-line; safe only because a Draft Invoice has no
 * Payment/Allocation referencing individual lines yet.
 */
return new class extends Migration
{
    private const TABLE = 'invoice_lines';

    private const INVOICE_TABLE = 'invoices';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('invoice_id', 64);
            $table->unsignedInteger('line_number');
            $table->string('description', 500);
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_price');
            $table->bigInteger('line_amount');

            $table->primary('id');
            $table->unique(['invoice_id', 'line_number']);

            $table->foreign(['tenant_id', 'invoice_id'])
                ->references(['tenant_id', 'id'])
                ->on(self::INVOICE_TABLE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
