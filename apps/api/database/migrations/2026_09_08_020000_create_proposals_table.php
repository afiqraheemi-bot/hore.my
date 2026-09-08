<?php

declare(strict_types=1);

use App\Domain\Workspace\CommandType;
use App\Domain\Workspace\ProposalProducerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for Proposal (ADR-0009, WTS-001 §5) — the
 * structured record a Task carries into `NeedsReview`, awaiting Human
 * Confirmation before it may become an Accounting Command.
 *
 * **The payload columns mirror an existing Accounting Command's shape
 * exactly** (`amount`, `currency`, `transaction_date`, two Account
 * references, `description`) — the same fields `expenses`, `incomes`,
 * `transfers`, and `owner_equity_transactions` already carry, per
 * TSK-003 ("a Proposal's payload must validate against the same
 * schema ... manual entry already validates against"). `command_type`
 * (see {@see CommandType}) selects which of those five existing
 * Accounting Command constructors an approved Proposal is translated
 * into; it never grows a bespoke payload shape of its own.
 *
 * **`evidence_reference` has no foreign key.** Evidence has no
 * persisted, tenant-scoped identity yet (AETS-010 §2.2 defers its
 * schema) — this mirrors every other evidence-reference column already
 * in this schema (`expenses.evidence_reference`,
 * `journal_evidence_links.evidence_reference`), none of which are
 * foreign-keyed for the identical reason.
 *
 * **`confidence` and `producer_type` exist now but are unused by
 * Phase D.** Every Proposal created before AI Orchestration exists has
 * `producer_type = 'Human'` and a `null` confidence — the columns are
 * here so a future AI-produced Proposal (WTS-004) needs no migration
 * of its own, per WTS-000 §5's requirement that this contract be
 * carried from the start.
 *
 * **A Proposal is immutable and append-only.** There is no
 * `updated_at`; a Task that needs a different Proposal gets a new
 * Proposal row via `Superseded` chaining (WTS-001 §5), never an edit
 * to this one.
 */
return new class extends Migration
{
    private const TABLE = 'proposals';

    private const TASK_TABLE = 'tasks';

    private const ACCOUNT_TABLE = 'accounts';

    private const COMMAND_TYPE_CHECK_CONSTRAINT = 'proposals_command_type_canonical';

    private const PRODUCER_TYPE_CHECK_CONSTRAINT = 'proposals_producer_type_canonical';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('tenant_id', 64);
            $table->string('proposal_id', 64);
            $table->string('task_id', 64);
            $table->string('command_type', 32);
            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->date('transaction_date');
            $table->string('primary_account_id', 64);
            $table->string('secondary_account_id', 64);
            $table->string('description', 1000);
            $table->string('evidence_reference', 64)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('producer_reference', 64);
            $table->string('producer_type', 16);
            $table->timestamp('created_at')->useCurrent();

            $table->primary('proposal_id');

            $table->foreign(['tenant_id', 'task_id'])
                ->references(['tenant_id', 'task_id'])
                ->on(self::TASK_TABLE);

            $table->foreign(['tenant_id', 'primary_account_id'])
                ->references(['tenant_id', 'account_id'])
                ->on(self::ACCOUNT_TABLE);

            $table->foreign(['tenant_id', 'secondary_account_id'])
                ->references(['tenant_id', 'account_id'])
                ->on(self::ACCOUNT_TABLE);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (command_type in (%s))',
                self::TABLE,
                self::COMMAND_TYPE_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (CommandType $type): string => $type->name,
                    CommandType::cases(),
                )),
            ));

            DB::statement(sprintf(
                'alter table %s add constraint %s check (producer_type in (%s))',
                self::TABLE,
                self::PRODUCER_TYPE_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (ProposalProducerType $type): string => $type->name,
                    ProposalProducerType::cases(),
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
