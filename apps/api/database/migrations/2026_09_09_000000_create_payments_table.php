<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for Payment (M21, Modul 7 phase 3) — a Tenant's
 * own record of money received from a Customer. Posts exactly one
 * Journal at record time (Debit deposit Account / Credit Receivable
 * Account) — mirrors `incomes`' own shape almost exactly, since a
 * Payment is a one-shot recorded fact with no Draft phase (money
 * either arrived or it did not).
 *
 * **No FK onto `invoices`.** Which Invoice(s) a Payment covers is
 * `payment_allocations`' own concern (M21), entirely separate from the
 * ledger fact this table records — a Payment may be received before
 * ever being allocated to anything (unapplied cash), and a single
 * Payment may eventually cover several Invoices.
 *
 * **`(tenant_id, id)` unique**, for `payment_allocations`' own
 * composite foreign key back onto this table.
 */
return new class extends Migration
{
    private const TABLE = 'payments';

    private const CUSTOMER_TABLE = 'customers';

    private const ACCOUNT_TABLE = 'accounts';

    private const JOURNAL_TABLE = 'journals';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('customer_id', 64);
            $table->date('payment_date');
            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->string('deposit_account_id', 64);
            $table->string('receivable_account_id', 64);
            $table->string('journal_id', 64);
            $table->string('reference', 255)->nullable();
            $table->timestamp('recorded_at')->useCurrent();

            $table->primary('id');
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'customer_id']);

            $table->foreign(['tenant_id', 'customer_id'])
                ->references(['tenant_id', 'id'])
                ->on(self::CUSTOMER_TABLE);

            $table->foreign(['tenant_id', 'deposit_account_id'])
                ->references(['tenant_id', 'account_id'])
                ->on(self::ACCOUNT_TABLE);

            $table->foreign(['tenant_id', 'receivable_account_id'])
                ->references(['tenant_id', 'account_id'])
                ->on(self::ACCOUNT_TABLE);

            $table->foreign(['tenant_id', 'journal_id'])
                ->references(['tenant_id', 'journal_id'])
                ->on(self::JOURNAL_TABLE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
