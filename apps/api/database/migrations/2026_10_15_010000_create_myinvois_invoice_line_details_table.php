<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for `MyInvoisLineDetail` (AETS-013 v0.1.0 §6/§6.1)
 * — the per-Invoice-line MyInvois data (tax type/rate/amount,
 * classification code, unit of measure) kept deliberately outside
 * `invoice_lines` itself, since none of it affects ledger posting and
 * `invoice_lines` flows directly into that pipeline.
 *
 * **Keyed by `(invoice_id, line_number)`, FK onto `invoice_lines`'s own
 * `(invoice_id, line_number)` unique constraint** — not onto
 * `invoice_lines.id`, because that id is regenerated on every Draft
 * edit (`InvoiceRepository::replaceLines()` deletes and re-inserts
 * every line on every save). `(invoice_id, line_number)` is the one
 * stable handle across edits; `ON DELETE CASCADE` means this table
 * never needs its own explicit cleanup — it empties automatically
 * every time `replaceLines()` deletes the `invoice_lines` rows it
 * would otherwise point at.
 *
 * **Every column nullable-by-presence (the row itself is optional),
 * but non-null once a row exists.** A line with no MyInvois detail
 * simply has no row here — this table is populated only for Tenants
 * who have started filling in MyInvois data, never defaulted or
 * guessed (AETS-013 MYI-004's "never a missing field silently
 * substituted" applies to the builder reading this data, not to
 * whether a row exists at all).
 */
return new class extends Migration
{
    private const TABLE = 'myinvois_invoice_line_details';

    private const LINES_TABLE = 'invoice_lines';

    private const TAX_TYPE_CHECK_CONSTRAINT = 'myinvois_invoice_line_details_tax_type_canonical';

    /**
     * @var list<string>
     */
    private const TAX_TYPES = ['01', '02', '03', '04', '05', '06', 'E'];

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('tenant_id', 64);
            $table->string('invoice_id', 64);
            $table->unsignedInteger('line_number');
            $table->string('tax_type', 2);
            $table->string('tax_rate', 16);
            $table->bigInteger('tax_amount');
            $table->string('classification_code', 32);
            $table->string('unit_of_measure', 16);

            $table->unique(['invoice_id', 'line_number']);

            $table->foreign(['invoice_id', 'line_number'])
                ->references(['invoice_id', 'line_number'])
                ->on(self::LINES_TABLE)
                ->cascadeOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (tax_type in (%s))',
                self::TABLE,
                self::TAX_TYPE_CHECK_CONSTRAINT,
                implode(', ', array_map(static fn (string $value): string => "'".$value."'", self::TAX_TYPES)),
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
