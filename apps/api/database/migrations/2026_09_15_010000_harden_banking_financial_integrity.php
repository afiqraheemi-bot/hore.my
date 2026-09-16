<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds database-level guards for Banking facts whose valid values are
 * already fixed by the active Money contract and Reconciliation state
 * machine. Existing invalid data makes the migration fail; financial
 * facts are never silently repaired or reinterpreted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('alter table bank_statement_import_batches add constraint bank_import_counts_non_negative check (row_count >= 0 and inserted_count >= 0 and duplicate_count >= 0)');
        DB::statement('alter table bank_statement_import_batches add constraint bank_import_counts_reconcile check (row_count = inserted_count + duplicate_count)');
        DB::statement('alter table bank_transactions add constraint bank_transactions_amount_non_negative check (amount >= 0)');
        DB::statement("alter table bank_transactions add constraint bank_transactions_currency_myr check (currency = 'MYR')");
        DB::statement("alter table reconciliations add constraint reconciliations_currency_myr check (currency = 'MYR')");
        DB::statement("alter table reconciliations add constraint reconciliations_completion_consistent check ((state = 'Completed' and completed_at is not null) or (state <> 'Completed' and completed_at is null))");
        DB::statement("alter table reconciliation_reopenings add constraint reconciliation_reopenings_reason_non_blank check (btrim(reason) <> '')");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('alter table reconciliation_reopenings drop constraint if exists reconciliation_reopenings_reason_non_blank');
        DB::statement('alter table reconciliations drop constraint if exists reconciliations_completion_consistent');
        DB::statement('alter table reconciliations drop constraint if exists reconciliations_currency_myr');
        DB::statement('alter table bank_transactions drop constraint if exists bank_transactions_currency_myr');
        DB::statement('alter table bank_transactions drop constraint if exists bank_transactions_amount_non_negative');
        DB::statement('alter table bank_statement_import_batches drop constraint if exists bank_import_counts_reconcile');
        DB::statement('alter table bank_statement_import_batches drop constraint if exists bank_import_counts_non_negative');
    }
};
