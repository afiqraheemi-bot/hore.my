<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for ReconciliationReopening (M18, SRS BNK-007:
 * "Rekonsiliasi selesai memerlukan tindakan reopen dan audit event
 * sebelum perubahan" — a completed Reconciliation requires a reopen
 * action and an audit event before any change).
 *
 * **A dedicated, append-only history table, not fields on
 * `reconciliations` itself.** Tracking only the most recent reopen
 * reason on the Reconciliation row would silently lose history if the
 * same Reconciliation is ever reopened more than once — SEC-006/
 * NFR-013's auditability requirements call for a durable record of
 * *every* reopen, not just the latest.
 *
 * **Deliberately not `AuditEvent` (AETS-010).** `AuditEvent` requires a
 * single `subjectJournalId` (AETS-010 §7) and its `AuditAction` enum is
 * explicitly governed to grow only additively, by a future AETS
 * document (AETS-010 §13) — a Reconciliation spans many
 * BankTransactions/Journals at once and is not itself a Journal-scoped
 * action, so it does not fit that contract. This table is Banking's own
 * dedicated audit record for exactly this one action, not a reuse or
 * extension of Accounting Core's.
 */
return new class extends Migration
{
    private const TABLE = 'reconciliation_reopenings';

    private const RECONCILIATION_TABLE = 'reconciliations';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('reconciliation_id', 64);
            $table->string('reason', 500);
            $table->string('reopened_by', 64);
            $table->timestamp('reopened_at')->useCurrent();

            $table->primary('id');

            $table->foreign('reconciliation_id')
                ->references('id')->on(self::RECONCILIATION_TABLE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
