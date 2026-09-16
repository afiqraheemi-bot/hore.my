<?php

declare(strict_types=1);

use App\Domain\Quotations\QuotationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for Quotation (AETS-016) — a Tenant's own
 * pre-sale document to a Customer, moving through the five states
 * §4 of that document defines. Deliberately parallel to
 * `invoices`, with the one structural difference AETS-016 §2.2
 * requires: **no Account reference of any kind** — a Quotation
 * never produces a ledger effect, so it carries no
 * receivable/revenue Account, no `journal_id`.
 *
 * **`quotation_number`, `issue_date` are both nullable** — each is
 * assigned exactly once, atomically, at Send time (never while
 * Draft), mirroring `invoices.invoice_number`/`issue_date`.
 *
 * **`converted_invoice_id` is a nullable composite FK onto
 * `invoices`** — set exactly once, atomically, when an `Accepted`
 * Quotation converts (AETS-016 §5); never reassigned.
 *
 * **`total_amount` is a cached, derived value** — mirrors
 * `invoices.total_amount`'s own docblock exactly.
 */
return new class extends Migration
{
    private const TABLE = 'quotations';

    private const CUSTOMER_TABLE = 'customers';

    private const INVOICE_TABLE = 'invoices';

    private const STATUS_CHECK_CONSTRAINT = 'quotations_status_canonical';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('customer_id', 64);
            $table->string('quotation_number', 32)->nullable();
            $table->string('status', 16);
            $table->date('issue_date')->nullable();
            $table->date('valid_until');
            $table->string('converted_invoice_id', 64)->nullable();
            $table->bigInteger('total_amount');
            $table->string('currency', 8);
            $table->timestamps();

            $table->primary('id');
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'quotation_number']);
            $table->index(['tenant_id', 'status']);

            $table->foreign(['tenant_id', 'customer_id'])
                ->references(['tenant_id', 'id'])
                ->on(self::CUSTOMER_TABLE);

            $table->foreign(['tenant_id', 'converted_invoice_id'])
                ->references(['tenant_id', 'id'])
                ->on(self::INVOICE_TABLE);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (status in (%s))',
                self::TABLE,
                self::STATUS_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (QuotationStatus $status): string => $status->name,
                    QuotationStatus::cases(),
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
