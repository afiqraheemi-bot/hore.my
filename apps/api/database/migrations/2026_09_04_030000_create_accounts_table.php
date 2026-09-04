<?php

declare(strict_types=1);

use App\Domain\Accounting\ChartOfAccounts\AccountHierarchyPolicy;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountOrigin;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountPersistenceAdapter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for the Account aggregate (AETS-005; ATS-005 §23),
 * mapped exactly to {@see AccountPersistenceAdapter}'s
 * persisted row shape (M2-T6/M2-T7) — no column exists here that adapter
 * does not already read or write, and no column that adapter reads or
 * writes is missing here.
 *
 * Deliberately absent, per AETS-005 §6/§21 (`COA-012`): any monetary
 * balance, debit total, or credit total column. Also absent: a
 * `normal_balance` column — Normal Balance is never persisted
 * independently, only re-derived from Account Type on read (`COA-005`),
 * exactly as the adapter already guarantees.
 *
 * **Identity.** `account_id` — the domain's own opaque {@see AccountId} —
 * is the table's primary key. No surrogate auto-increment identity
 * column is introduced: nothing in this schema needs one (no ORM
 * relationship depends on it, and every reference — including the
 * self-referential parent link below — already has a natural,
 * domain-meaningful key to use).
 *
 * **Tenant-scoped Account Code uniqueness** (`COA-003`) is enforced by
 * `UNIQUE (tenant_id, account_code)` — a real PostgreSQL constraint, not
 * only application-level validation.
 *
 * **Same-tenant parent integrity.** A composite foreign key,
 * `(tenant_id, parent_id) REFERENCES accounts (tenant_id, account_id)`,
 * makes a cross-tenant parent assignment impossible at the database
 * level: the referenced row must exist with the *same* `tenant_id` as
 * the child, or the constraint fails. This requires the composite
 * `UNIQUE (tenant_id, account_id)` index below as the constraint's
 * target — `account_id` is already globally unique on its own via the
 * primary key, but PostgreSQL still requires a unique constraint whose
 * column set exactly matches the foreign key's referenced columns.
 *
 * **Self-parenting** (`parent_id = account_id` on the same row) is
 * rejected by a `CHECK` constraint. Laravel's schema builder has no
 * fluent method for `CHECK` constraints in this framework version, so
 * this one constraint is added via a raw, PostgreSQL-specific
 * statement — one of the few exceptions to "no raw SQL" this migration
 * needs.
 *
 * **Canonical value sets, defense-in-depth.** `account_type` and
 * `account_origin` each carry a `CHECK (... IN (...))` constraint
 * naming exactly the values {@see AccountType}
 * and {@see AccountOrigin} already
 * canonically define — a database-level mirror of an already-settled
 * domain rule, not a new one. Neither enum is converted to a backed
 * enum for this, and no PostgreSQL `ENUM` type is introduced: an
 * ordinary `varchar` column plus `CHECK (... IN (...))` says exactly
 * the same thing with less schema machinery (no separate type to
 * create, alter, or drop), and keeps the adapter's own private
 * case-name translation ({@see AccountPersistenceAdapter})
 * completely unchanged. An unsupported value now fails at the database
 * boundary itself, not only when the adapter later tries to read it
 * back into a domain enum.
 *
 * **Deliberately not attempted here:** transitive/indirect hierarchy
 * cycle detection (A -> B -> C -> A). No ADR or AETS decision requires
 * database-level recursive cycle detection, and PostgreSQL has no
 * portable, simple constraint expressing it — {@see AccountHierarchyPolicy}
 * remains the sole authority for that check, exactly as it already is.
 *
 * **Deliberately not attempted here:** any foreign key or trigger
 * preventing deletion of an Account referenced by a posted Journal Line
 * (AETS-005 §15/§18, `COA-014`). No Journal/Posting schema exists yet
 * (M2-T8.1 is Account-schema-only) — that referential protection can
 * only be added once a Journal Line table exists to reference. Until
 * then, no code path in this codebase performs a hard delete of an
 * `accounts` row at all (no repository, no delete migration path, no
 * delete API on the Account aggregate — see M2-T7), so this is a
 * documented gap, not a silent one.
 *
 * No default Chart of Accounts, System Account bootstrap, or Account
 * Code numbering policy is introduced by this migration — it defines
 * storage shape only.
 */
return new class extends Migration
{
    private const TABLE = 'accounts';

    private const SELF_PARENT_CHECK_CONSTRAINT = 'accounts_parent_id_not_self';

    private const ACCOUNT_TYPE_CHECK_CONSTRAINT = 'accounts_account_type_canonical';

    private const ACCOUNT_ORIGIN_CHECK_CONSTRAINT = 'accounts_account_origin_canonical';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('tenant_id', 64);
            $table->string('account_id', 64)->primary();
            $table->string('account_code', 64);
            $table->string('account_name', 64);
            $table->string('account_type', 32);
            $table->string('account_origin', 32);
            $table->boolean('active');
            $table->boolean('posting_eligible');
            $table->string('parent_id', 64)->nullable();

            $table->unique(['tenant_id', 'account_code']);
            $table->unique(['tenant_id', 'account_id']);

            $table->foreign(['tenant_id', 'parent_id'])
                ->references(['tenant_id', 'account_id'])
                ->on(self::TABLE);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'alter table %s add constraint %s check (parent_id is null or parent_id <> account_id)',
                self::TABLE,
                self::SELF_PARENT_CHECK_CONSTRAINT,
            ));

            DB::statement(sprintf(
                'alter table %s add constraint %s check (account_type in (%s))',
                self::TABLE,
                self::ACCOUNT_TYPE_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (AccountType $type): string => $type->name,
                    AccountType::cases(),
                )),
            ));

            DB::statement(sprintf(
                'alter table %s add constraint %s check (account_origin in (%s))',
                self::TABLE,
                self::ACCOUNT_ORIGIN_CHECK_CONSTRAINT,
                self::sqlStringList(array_map(
                    static fn (AccountOrigin $origin): string => $origin->name,
                    AccountOrigin::cases(),
                )),
            ));
        }
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

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
