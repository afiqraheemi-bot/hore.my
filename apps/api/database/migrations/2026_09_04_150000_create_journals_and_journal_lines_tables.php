<?php

declare(strict_types=1);

use App\Domain\Accounting\ChartOfAccounts\Account;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Journal\JournalState;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Infrastructure\Accounting\Journal\JournalPersistenceAdapter;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for the Journal aggregate and its Journal Lines
 * (AETS-004 §6, §7, §9), mapped exactly to
 * {@see JournalPersistenceAdapter}'s persisted shapes (M3-T8) — no
 * column exists here the adapter does not already read or write, and
 * no column the adapter reads or writes is missing here.
 *
 * Deliberately absent, per AETS-004 §12/§22 (`COA-012`-equivalent for
 * Journal — no invariant permits a stored balance): any
 * `debit_total`/`credit_total`/`balance` column, any signed-amount
 * encoding, and any duplicated header `currency` fact — a Journal's
 * Currency is a fact of its Lines (`JRN-011`), not of the header, and
 * the adapter's own header shape carries none. Also absent: Actor,
 * Source, Evidence, audit metadata, an idempotency key, and any
 * Reversal/Replacement reference — none of these are part of what the
 * current `Journal`/`JournalPersistenceAdapter` contract persists yet
 * (M3-T5–M3-T8); adding them now would be schema ahead of the domain
 * that would use them.
 *
 * **`journals` — identity.** `journal_id` — the domain's own opaque
 * {@see JournalId} — is the table's primary key, exactly mirroring the
 * `account_id`-as-primary-key precedent already established for
 * `accounts` (M2-T8.1): no surrogate auto-increment identity column is
 * introduced, since nothing here needs one.
 *
 * **`journal_lines` — no LineId invented.** {@see JournalLine} carries
 * no identifier of its own (deliberately — M3-T3), and this migration
 * does not invent one either. `(journal_id, line_position)` is unique
 * by construction — it *is* the table's primary key, exactly mirroring
 * the adapter's own `line_position` field (M3-T8), which stands in for
 * order only, not identity. No separate surrogate row id exists.
 *
 * **Same-Tenant Journal/Account integrity, by composite foreign key,
 * not a trigger.** `journal_lines.tenant_id` exists *only* to make this
 * enforcement possible — it is not a new `JournalLine` domain property,
 * and {@see JournalPersistenceAdapter} never reads or writes it as
 * part of a Line's own persisted shape. Two composite foreign keys
 * share that one column:
 *
 * - `(tenant_id, journal_id) REFERENCES journals (tenant_id, journal_id)`
 *   forces a line's `tenant_id` to equal its own Journal's `tenant_id`.
 * - `(tenant_id, account_id) REFERENCES accounts (tenant_id, account_id)`
 *   forces the same `tenant_id` to equal the referenced Account's
 *   `tenant_id`.
 *
 * Together, transitively, a Journal Line's Account can never belong to
 * a different Tenant than its Journal — exactly the same composite-FK
 * technique {@see Account}'s own
 * production schema already uses for same-Tenant parent integrity
 * (M2-T8.1), applied here across two tables instead of one table's
 * self-reference. `accounts` already carries the
 * `UNIQUE (tenant_id, account_id)` index this second foreign key
 * requires (M2-T8.1); this migration adds the equivalent
 * `UNIQUE (tenant_id, journal_id)` on `journals` for the first.
 *
 * **Canonical value sets, defense-in-depth.** `state` and `direction`
 * each carry a `CHECK (... IN (...))` constraint naming exactly the
 * values {@see JournalState} and {@see JournalDirection} already
 * canonically define — the same rationale, and the same technique
 * (an ordinary `varchar` column, not a PostgreSQL `ENUM` type, and
 * neither domain enum converted to a backed enum), already established
 * for `accounts.account_type`/`accounts.account_origin` (M2-T8.1/T8.2).
 *
 * **Money.** `amount` is a `BIGINT` — exact integer minor units, never
 * `NUMERIC`/`DECIMAL`, never a float — exactly mirroring
 * {@see MoneyPersistenceAdapter}'s
 * own signed-64-bit-range contract. `currency` is stored explicitly,
 * NOT NULL, with no `CHECK` restricting it to `MYR` or any other
 * specific value: Currency validation remains entirely owned by the
 * Currency/domain contract ({@see Currency}'s
 * own registry), not duplicated or hard-coded into this schema.
 * `direction` is a wholly separate column from `amount` — never folded
 * into a signed-amount encoding (AETS-004 §8).
 *
 * **Non-negative magnitude, enforced at the database too (M3-T9).**
 * `amount` additionally carries `CHECK (amount >= 0)`: a Journal
 * Line's Money is a non-negative magnitude, and Debit/Credit polarity
 * is represented exclusively by `direction` (AETS-004 §8) — the
 * database itself must not permit a second, competing accounting
 * representation where sign is encoded inside `amount`. Zero is
 * deliberately still permitted: AETS-004 does not prohibit a
 * zero-amount line, and this migration does not invent a minimum
 * monetary value it was never asked to define. This mirrors, at the
 * database layer, the same non-negative-only guarantee
 * {@see Money} and {@see MinorUnits}
 * already enforce at construction (AETS-003 §9) — the domain and the
 * schema agree, neither silently permits what the other forbids.
 *
 * **Deliberately not attempted here:** minimum-two-lines,
 * single-Currency, and exact-Debit-equals-Credit-balance enforcement.
 * These are aggregate/Posting-Engine invariants
 * ({@see Journal::create()}/{@see Journal::reconstitute()} already
 * enforce them at the domain level, `JRN-002`, `JRN-007`, `JRN-011`)
 * that a per-row `CHECK` constraint cannot express — proving them
 * requires seeing every line of a Journal at once, not one row in
 * isolation. No trigger is added for them either; none is currently
 * authorized by any ADR/AETS decision this task is aware of.
 *
 * **Deliberately not attempted here:** any trigger enforcing Posted-
 * Journal immutability. AETS-004 §15/§9 requires it eventually, but
 * nothing in the current `Journal`/`JournalPersistenceAdapter`
 * contract writes to this schema yet (no repository exists — M3-T9 is
 * schema-only), so there is no write path to guard prematurely.
 * Controlled writes remain a future repository/Posting Engine
 * responsibility.
 *
 * **Deletion policy.** Neither foreign key specifies `ON DELETE
 * CASCADE`; PostgreSQL's default (`NO ACTION`) applies to both,
 * deliberately: a `journals` row referenced by any `journal_lines` row
 * cannot be deleted, and an `accounts` row referenced by any
 * `journal_lines` row cannot be deleted either — never silently
 * erasing potential accounting history, and never cascading a deletion
 * into `accounts` (which itself still exposes no delete API at all,
 * M2-T7). A more specific Journal/Journal Line deletion policy (for
 * example, once Posted-immutability enforcement exists) is deferred;
 * this restrictive default is the safe interim choice.
 */
return new class extends Migration
{
    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const STATE_CHECK_CONSTRAINT = 'journals_state_canonical';

    private const LINE_POSITION_CHECK_CONSTRAINT = 'journal_lines_line_position_non_negative';

    private const DIRECTION_CHECK_CONSTRAINT = 'journal_lines_direction_canonical';

    private const AMOUNT_CHECK_CONSTRAINT = 'journal_lines_amount_non_negative';

    public function up(): void
    {
        Schema::create(self::JOURNAL_TABLE, function (Blueprint $table): void {
            $table->string('tenant_id', 64);
            $table->string('journal_id', 64)->primary();
            $table->string('state', 32);

            $table->unique(['tenant_id', 'journal_id']);
        });

        Schema::create(self::LINE_TABLE, function (Blueprint $table): void {
            $table->string('tenant_id', 64);
            $table->string('journal_id', 64);
            $table->integer('line_position');
            $table->string('account_id', 64);
            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->string('direction', 32);

            $table->primary(['journal_id', 'line_position']);

            $table->foreign(['tenant_id', 'journal_id'])
                ->references(['tenant_id', 'journal_id'])
                ->on(self::JOURNAL_TABLE);

            $table->foreign(['tenant_id', 'account_id'])
                ->references(['tenant_id', 'account_id'])
                ->on(self::ACCOUNT_TABLE);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (state in (%s))',
                self::JOURNAL_TABLE,
                self::STATE_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (JournalState $state): string => $state->name,
                    JournalState::cases(),
                )),
            ));

            DB::statement(sprintf(
                'alter table %s add constraint %s check (line_position >= 0)',
                self::LINE_TABLE,
                self::LINE_POSITION_CHECK_CONSTRAINT,
            ));

            DB::statement(sprintf(
                'alter table %s add constraint %s check (direction in (%s))',
                self::LINE_TABLE,
                self::DIRECTION_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (JournalDirection $direction): string => $direction->name,
                    JournalDirection::cases(),
                )),
            ));

            DB::statement(sprintf(
                'alter table %s add constraint %s check (amount >= 0)',
                self::LINE_TABLE,
                self::AMOUNT_CHECK_CONSTRAINT,
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::LINE_TABLE);
        Schema::dropIfExists(self::JOURNAL_TABLE);
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
