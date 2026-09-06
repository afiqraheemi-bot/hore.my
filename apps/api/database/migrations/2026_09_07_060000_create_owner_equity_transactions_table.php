<?php

declare(strict_types=1);

use App\Domain\Transactions\OwnerEquity\OwnerEquityMovementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for the Owner Equity Transaction record (M15) — the
 * Transactions domain's authoritative business context for a
 * manually-recorded Capital Contribution or Drawing, kept traceable to
 * the Posted Journal it produced.
 *
 * Mirrors `incomes`/`transfers`' own schema shape — see
 * `2026_09_06_235000_create_incomes_table.php`'s own docblock for the
 * full column-shape and composite-FK reasoning, which applies here
 * unchanged — substituting `equity_account_id`/`cash_account_id` for
 * the role-specific account columns, and adding `movement_type`.
 *
 * **`movement_type` carries a `CHECK (... IN (...))` constraint**,
 * mirroring the canonical-value constraint convention
 * `2026_09_04_030000_create_accounts_table.php` already establishes for
 * `account_type`/`account_origin` — an ordinary `varchar` column plus a
 * `CHECK` says exactly what a database-level enum type would, without
 * PostgreSQL's own enum-alteration migration friction.
 */
return new class extends Migration
{
    private const TABLE = 'owner_equity_transactions';

    private const JOURNAL_TABLE = 'journals';

    private const ACCOUNT_TABLE = 'accounts';

    private const MOVEMENT_TYPE_CHECK_CONSTRAINT = 'owner_equity_transactions_movement_type_canonical';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('journal_id', 64);
            $table->string('movement_type', 20);
            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->date('transaction_date');
            $table->string('equity_account_id', 64);
            $table->string('cash_account_id', 64);
            $table->string('description', 1000);
            $table->string('evidence_reference', 64)->nullable();
            $table->timestamp('recorded_at')->useCurrent();

            $table->primary('id');

            $table->foreign(['tenant_id', 'journal_id'])
                ->references(['tenant_id', 'journal_id'])
                ->on(self::JOURNAL_TABLE);

            $table->foreign(['tenant_id', 'equity_account_id'])
                ->references(['tenant_id', 'account_id'])
                ->on(self::ACCOUNT_TABLE);

            $table->foreign(['tenant_id', 'cash_account_id'])
                ->references(['tenant_id', 'account_id'])
                ->on(self::ACCOUNT_TABLE);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (movement_type in (%s))',
                self::TABLE,
                self::MOVEMENT_TYPE_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (OwnerEquityMovementType $type): string => $type->name,
                    OwnerEquityMovementType::cases(),
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
