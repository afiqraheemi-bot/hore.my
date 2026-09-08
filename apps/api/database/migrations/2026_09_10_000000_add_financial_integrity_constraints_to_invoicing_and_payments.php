<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds database-level `CHECK` constraints to `invoices`, `invoice_lines`,
 * `payments`, and `payment_allocations` — defense-in-depth against a
 * corrupted or out-of-band-written row, on top of (never instead of)
 * the Domain-layer validation `Invoice`/`InvoiceLine`/`Payment`/
 * `PaymentAllocation` already perform on every value they construct.
 * Found missing during an external audit of M20/M21: domain validation
 * alone is not a sufficient defense for an accounting database, since
 * it only runs on the path this codebase's own repositories use — a
 * manual `UPDATE`, a bypassed migration, or a future bug in a
 * repository's own `insert()` call would otherwise write an invalid
 * row with nothing at the schema level to reject it.
 *
 * **`invoices_status_metadata_consistency`** is the most load-bearing
 * of these: it makes "Draft with Issued metadata" and "Issued missing
 * its own number/date/journal" both physically unrepresentable, not
 * merely avoided by the application's own code paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('alter table invoices add constraint invoices_total_amount_non_negative check (total_amount >= 0)');
        DB::statement('alter table invoices add constraint invoices_due_date_not_before_issue_date check (issue_date is null or due_date >= issue_date)');
        DB::statement(<<<'SQL'
            alter table invoices add constraint invoices_status_metadata_consistency check (
                (status = 'Draft' and invoice_number is null and issue_date is null and journal_id is null)
                or
                (status = 'Issued' and invoice_number is not null and issue_date is not null and journal_id is not null)
            )
            SQL);

        DB::statement('alter table invoice_lines add constraint invoice_lines_quantity_positive check (quantity > 0)');
        DB::statement('alter table invoice_lines add constraint invoice_lines_line_number_positive check (line_number > 0)');
        DB::statement('alter table invoice_lines add constraint invoice_lines_unit_price_non_negative check (unit_price >= 0)');
        DB::statement('alter table invoice_lines add constraint invoice_lines_line_amount_non_negative check (line_amount >= 0)');

        DB::statement('alter table payments add constraint payments_amount_positive check (amount > 0)');

        DB::statement('alter table payment_allocations add constraint payment_allocations_amount_positive check (amount > 0)');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('alter table invoices drop constraint if exists invoices_total_amount_non_negative');
        DB::statement('alter table invoices drop constraint if exists invoices_due_date_not_before_issue_date');
        DB::statement('alter table invoices drop constraint if exists invoices_status_metadata_consistency');

        DB::statement('alter table invoice_lines drop constraint if exists invoice_lines_quantity_positive');
        DB::statement('alter table invoice_lines drop constraint if exists invoice_lines_line_number_positive');
        DB::statement('alter table invoice_lines drop constraint if exists invoice_lines_unit_price_non_negative');
        DB::statement('alter table invoice_lines drop constraint if exists invoice_lines_line_amount_non_negative');

        DB::statement('alter table payments drop constraint if exists payments_amount_positive');

        DB::statement('alter table payment_allocations drop constraint if exists payment_allocations_amount_positive');
    }
};
