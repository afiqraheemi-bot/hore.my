<?php

declare(strict_types=1);

use App\Infrastructure\Payments\PaymentAllocationRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a soft-delete audit trail to `payment_allocations` (P1-3,
 * 2026-09-08 audit remediation).
 *
 * The owning migration's own docblock justified a hard `DELETE` on
 * ledger-correctness grounds only ("no ledger effect to reverse") —
 * that reasoning is still correct and unchanged by this migration.
 * What it did not consider is traceability: a hard delete leaves no
 * record of who removed an allocation or when, which an external
 * audit flagged as a real gap, and which AETS-009 §17 separately names
 * as the root cause of a documented Aging Report limitation
 * (historical reproducibility breaks once a contributing allocation
 * is later deallocated, because nothing survives to reconstruct from).
 *
 * `deleted_at`/`deleted_by_actor` turn `AllocationService::deallocate()`
 * from a physical `DELETE` into an `UPDATE` that marks the row deleted
 * without destroying it — every read path
 * ({@see PaymentAllocationRepository})
 * filters `deleted_at IS NULL`, so this is observably identical to a
 * hard delete for every existing behavior (an Invoice's outstanding
 * balance, a Payment's unallocated amount, the Aging Report) while the
 * row itself now survives as its own audit trail. This does not, by
 * itself, make the Aging Report's *historical* as-of-date computation
 * reconstruct a deallocated allocation's past effect — that would
 * additionally require the report's own query to reason about
 * `deleted_at` relative to the requested as-of date, which this
 * migration does not attempt; it closes the audit-trail gap, not the
 * separate reporting-logic gap AETS-009 §17 also names.
 */
return new class extends Migration
{
    private const TABLE = 'payment_allocations';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by_actor', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(['deleted_at', 'deleted_by_actor']);
        });
    }
};
