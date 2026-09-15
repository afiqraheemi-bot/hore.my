<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes tenant ownership part of every Banking foreign-key boundary.
 *
 * The original Banking migrations scoped repository queries by tenant,
 * but several database foreign keys referenced globally unique IDs only.
 * That left the database unable to reject a malformed row whose own
 * `tenant_id` disagreed with its BankAccount, ImportBatch,
 * BankTransaction, or Reconciliation parent. These additive composite
 * constraints make tenant isolation a schema invariant as required by
 * ADR-0004, while retaining the original keys for migration-history
 * compatibility.
 *
 * Existing inconsistent data deliberately makes this migration fail;
 * silently legitimising or rewriting cross-tenant financial data is not
 * an acceptable migration policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'bank_accounts_tenant_id_id_unique');
        });

        Schema::table('bank_statement_import_batches', function (Blueprint $table): void {
            $table->unique(
                ['tenant_id', 'bank_account_id', 'id'],
                'bank_imports_tenant_account_id_unique',
            );

            $table->foreign(
                ['tenant_id', 'bank_account_id'],
                'bank_imports_tenant_bank_account_fk',
            )->references(['tenant_id', 'id'])->on('bank_accounts');
        });

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'bank_transactions_tenant_id_id_unique');

            $table->foreign(
                ['tenant_id', 'bank_account_id'],
                'bank_transactions_tenant_bank_account_fk',
            )->references(['tenant_id', 'id'])->on('bank_accounts');

            $table->foreign(
                ['tenant_id', 'bank_account_id', 'import_batch_id'],
                'bank_transactions_tenant_import_batch_fk',
            )->references(['tenant_id', 'bank_account_id', 'id'])->on('bank_statement_import_batches');
        });

        Schema::table('matches', function (Blueprint $table): void {
            $table->foreign(
                ['tenant_id', 'bank_transaction_id'],
                'matches_tenant_bank_transaction_fk',
            )->references(['tenant_id', 'id'])->on('bank_transactions');
        });

        Schema::table('reconciliations', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'reconciliations_tenant_id_id_unique');

            $table->foreign(
                ['tenant_id', 'bank_account_id'],
                'reconciliations_tenant_bank_account_fk',
            )->references(['tenant_id', 'id'])->on('bank_accounts');
        });

        Schema::table('reconciliation_reopenings', function (Blueprint $table): void {
            $table->foreign(
                ['tenant_id', 'reconciliation_id'],
                'reconciliation_reopenings_tenant_reconciliation_fk',
            )->references(['tenant_id', 'id'])->on('reconciliations');
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_reopenings', function (Blueprint $table): void {
            $table->dropForeign('reconciliation_reopenings_tenant_reconciliation_fk');
        });

        Schema::table('reconciliations', function (Blueprint $table): void {
            $table->dropForeign('reconciliations_tenant_bank_account_fk');
            $table->dropUnique('reconciliations_tenant_id_id_unique');
        });

        Schema::table('matches', function (Blueprint $table): void {
            $table->dropForeign('matches_tenant_bank_transaction_fk');
        });

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropForeign('bank_transactions_tenant_import_batch_fk');
            $table->dropForeign('bank_transactions_tenant_bank_account_fk');
            $table->dropUnique('bank_transactions_tenant_id_id_unique');
        });

        Schema::table('bank_statement_import_batches', function (Blueprint $table): void {
            $table->dropForeign('bank_imports_tenant_bank_account_fk');
            $table->dropUnique('bank_imports_tenant_account_id_unique');
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropUnique('bank_accounts_tenant_id_id_unique');
        });
    }
};
