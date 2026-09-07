<?php

declare(strict_types=1);

use App\Domain\Invoicing\InvoiceAccountTypeValidator;
use App\Domain\Invoicing\InvoiceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for Invoice (M20, Modul 7 phase 2) — a Tenant's
 * own bill to a Customer, moving through exactly two states: `Draft`
 * (mutable, no ledger effect) and `Issued` (immutable, produces a
 * Posted Journal: Debit Accounts Receivable / Credit Revenue).
 * Cancelling an already-Issued Invoice and Payment/Allocation are both
 * out of scope for this milestone — see M20's Architecture Review.
 *
 * **`invoice_number`, `issue_date`, and `journal_id` are all
 * nullable** — each is assigned exactly once, atomically, at Issue
 * time (never while Draft). `total_amount` is a cached, derived value
 * (the sum of this Invoice's own `invoice_lines`), recomputed and
 * re-persisted on every Draft edit — never the source of truth on its
 * own, mirroring how `bank_statement_import_batches.row_count` is
 * likewise a cached derived value.
 *
 * **`(tenant_id, invoice_number)` unique** — many Draft rows may share
 * `invoice_number IS NULL` (PostgreSQL never treats NULLs as equal for
 * uniqueness), but once assigned at Issue time a number is unique per
 * Tenant.
 *
 * **`receivable_account_id`/`revenue_account_id`, each a composite FK
 * onto `accounts`**, mirroring the tenant-safe composite-FK convention
 * already established throughout this schema. Their Account Type
 * (`Asset`/`Revenue` respectively) is validated at the Domain layer
 * ({@see InvoiceAccountTypeValidator}), not by a
 * schema-level constraint — the same division of responsibility every
 * other Transactions module's own Account Type check already
 * establishes.
 *
 * **`(tenant_id, id)` unique**, for `invoice_lines`' own composite FK
 * back onto this table.
 */
return new class extends Migration
{
    private const TABLE = 'invoices';

    private const CUSTOMER_TABLE = 'customers';

    private const ACCOUNT_TABLE = 'accounts';

    private const JOURNAL_TABLE = 'journals';

    private const STATUS_CHECK_CONSTRAINT = 'invoices_status_canonical';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('customer_id', 64);
            $table->string('invoice_number', 32)->nullable();
            $table->string('status', 16);
            $table->date('issue_date')->nullable();
            $table->date('due_date');
            $table->string('receivable_account_id', 64);
            $table->string('revenue_account_id', 64);
            $table->string('journal_id', 64)->nullable();
            $table->bigInteger('total_amount');
            $table->string('currency', 8);
            $table->timestamps();

            $table->primary('id');
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'invoice_number']);
            $table->index(['tenant_id', 'status']);

            $table->foreign(['tenant_id', 'customer_id'])
                ->references(['tenant_id', 'id'])
                ->on(self::CUSTOMER_TABLE);

            $table->foreign(['tenant_id', 'receivable_account_id'])
                ->references(['tenant_id', 'account_id'])
                ->on(self::ACCOUNT_TABLE);

            $table->foreign(['tenant_id', 'revenue_account_id'])
                ->references(['tenant_id', 'account_id'])
                ->on(self::ACCOUNT_TABLE);

            $table->foreign(['tenant_id', 'journal_id'])
                ->references(['tenant_id', 'journal_id'])
                ->on(self::JOURNAL_TABLE);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (status in (%s))',
                self::TABLE,
                self::STATUS_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (InvoiceStatus $status): string => $status->name,
                    InvoiceStatus::cases(),
                )),
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * @param  list<string>  $values
     */
    private static function sqlStringList(array $values): string
    {
        return implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            $values,
        ));
    }
};
