<?php

declare(strict_types=1);

use App\Domain\Banking\ReconciliationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for a Reconciliation's completion snapshot
 * (AETS-008 §12.3, `BNK-017`) — the exact set of Bank Transactions
 * `complete()` verified as fully matched and zero-difference at the
 * instant of completion. A Bank Transaction imported afterward, even
 * one whose Transaction Date falls inside an already-`Completed`
 * period, is never silently absorbed into this recorded result; it
 * surfaces as a late unreconciled transaction instead
 * ({@see ReconciliationService::findLateUnreconciledTransactionIds()}),
 * and only an explicit `reopen()` — which clears this table's rows for
 * that Reconciliation — followed by a new `complete()` can incorporate
 * it.
 *
 * **One row per (Reconciliation, BankTransaction) pair, not one row
 * per Reconciliation with a serialized list** — an explicit relational
 * fact for each included BankTransaction, queryable and
 * foreign-key-constrained like everything else in this schema, never a
 * JSON blob standing in for financial facts.
 *
 * **Cleared, not accumulated, on reopen.** Only the *current*
 * completion's scope is ever represented here; a Reconciliation
 * reopened and later completed again gets a fresh snapshot. The
 * permanent audit trail of *that* transition already lives in
 * `reconciliation_reopenings` (M18) — this table is derived,
 * recomputable state, not itself a historical record.
 */
return new class extends Migration
{
    private const TABLE = 'reconciliation_completion_snapshots';

    private const RECONCILIATION_TABLE = 'reconciliations';

    private const BANK_TRANSACTION_TABLE = 'bank_transactions';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('reconciliation_id', 64);
            $table->string('bank_transaction_id', 64);
            $table->timestamp('completed_at');

            $table->primary('id');

            $table->unique(
                ['tenant_id', 'reconciliation_id', 'bank_transaction_id'],
                'recon_completion_snapshots_tenant_recon_txn_unique',
            );

            $table->foreign(['tenant_id', 'reconciliation_id'], 'recon_completion_snapshots_reconciliation_foreign')
                ->references(['tenant_id', 'id'])->on(self::RECONCILIATION_TABLE);

            $table->foreign('bank_transaction_id', 'recon_completion_snapshots_bank_transaction_foreign')
                ->references('id')->on(self::BANK_TRANSACTION_TABLE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
