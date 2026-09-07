<?php

declare(strict_types=1);

use App\Domain\Payments\AllocationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for PaymentAllocation (M21) — the link between a
 * Payment and an Invoice it (fully or partially) settles.
 *
 * **No ledger effect of its own.** Accounts Receivable is a single
 * shared control account (M20's own design note) — the Payment's own
 * Journal (Debit deposit Account / Credit Receivable Account) already
 * reflects the cash movement; an allocation only records *which*
 * Invoice(s) that already-posted Payment is considered to settle. Two
 * invariants are enforced at the Domain layer, never by a schema-level
 * constraint (both require cross-row aggregation, which a `CHECK`
 * constraint cannot express): the sum of allocations against one
 * Invoice must never exceed that Invoice's own `total_amount`, and the
 * sum of allocations from one Payment must never exceed that Payment's
 * own `amount` ({@see AllocationService}).
 *
 * **Hard-deletable.** Unlike a Posted Journal, an allocation is pure
 * sub-ledger bookkeeping with no ledger effect to reverse — removing a
 * mistaken allocation is a plain delete, mirroring how a Draft Invoice
 * itself may be hard-deleted (M20) for the identical "no ledger effect
 * yet" reason.
 */
return new class extends Migration
{
    private const TABLE = 'payment_allocations';

    private const PAYMENT_TABLE = 'payments';

    private const INVOICE_TABLE = 'invoices';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('payment_id', 64);
            $table->string('invoice_id', 64);
            $table->bigInteger('amount');
            $table->timestamps();

            $table->primary('id');
            $table->index(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'invoice_id']);

            $table->foreign(['tenant_id', 'payment_id'])
                ->references(['tenant_id', 'id'])
                ->on(self::PAYMENT_TABLE);

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
