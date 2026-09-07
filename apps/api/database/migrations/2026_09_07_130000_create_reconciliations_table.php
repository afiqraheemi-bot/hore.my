<?php

declare(strict_types=1);

use App\Domain\Banking\BankTransaction;
use App\Domain\Banking\ReconciliationDifference;
use App\Domain\Banking\ReconciliationState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for Reconciliation (M18, SRS BNK-006: "Tempoh,
 * baki buka/tutup, matched items dan perbezaan hendaklah dijejak" —
 * period, opening/closing balance, matched items, and differences
 * shall be tracked) and the SRS §10.4 state machine: Draft -> In
 * Review -> Balanced -> Completed.
 *
 * **`opening_balance`/`closing_balance` are user-entered facts from
 * the real bank statement**, never computed by hore.my — the whole
 * point of reconciliation is comparing them against what hore.my's own
 * imported {@see BankTransaction} rows imply (see
 * {@see ReconciliationDifference}).
 *
 * **`state` carries a `CHECK (... IN (...))` constraint**, mirroring
 * the canonical-value constraint convention already established
 * throughout this schema.
 *
 * **No FK onto `matches` or `bank_transactions`.** A Reconciliation's
 * relationship to specific BankTransaction rows is computed on demand
 * from `bank_account_id` + `period_start`/`period_end`, never stored as
 * a persisted link — mirroring `AccountBalanceAggregator`'s own
 * "Projection, rebuildable from source, never itself authoritative"
 * convention (AETS-009 §3).
 */
return new class extends Migration
{
    private const TABLE = 'reconciliations';

    private const BANK_ACCOUNT_TABLE = 'bank_accounts';

    private const STATE_CHECK_CONSTRAINT = 'reconciliations_state_canonical';

    private const PERIOD_CHECK_CONSTRAINT = 'reconciliations_period_end_not_before_start';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('bank_account_id', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->bigInteger('opening_balance');
            $table->bigInteger('closing_balance');
            $table->string('currency', 8);
            $table->string('state', 16);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->primary('id');

            $table->foreign('bank_account_id')
                ->references('id')->on(self::BANK_ACCOUNT_TABLE);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (state in (%s))',
                self::TABLE,
                self::STATE_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (ReconciliationState $state): string => $state->name,
                    ReconciliationState::cases(),
                )),
            ));

            DB::statement(sprintf(
                'alter table %s add constraint %s check (period_end >= period_start)',
                self::TABLE,
                self::PERIOD_CHECK_CONSTRAINT,
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
