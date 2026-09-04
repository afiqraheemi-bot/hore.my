<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\Account;
use App\Domain\Accounting\ChartOfAccounts\AccountCode;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\ChartOfAccounts\Exception\DuplicateAccountCodeException;
use App\Infrastructure\Accounting\ChartOfAccounts\Exception\ImmutableAccountStateException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * Persists and retrieves the Account aggregate through the production
 * `accounts` table (M2-T8.1) — the first, and so far only, repository
 * this bounded context has. {@see AccountPersistenceAdapter} remains
 * the sole mapping boundary between the Account domain and this row
 * shape: this class never reads or writes an Account field the
 * adapter does not already know about, and never returns a raw
 * database row to a caller — every public method here returns either
 * an {@see Account}, `null`, or a `bool`.
 *
 * **What this repository does not do.** It invents no domain
 * mutation this codebase's Account aggregate does not already expose
 * (AETS-005 §15/§16; M2-T7) — {@see save()} never becomes a backdoor
 * around domain invariants the Account aggregate itself already
 * protects: reusing an existing {@see AccountId} with a different
 * Tenant, Account Code, Account Name, Account Type, or Account Origin
 * is rejected (see `save()`'s own docblock), never silently written.
 * It has no `delete()` method: AETS-005's hard-delete protection
 * (`COA-014`) depends on a future Journal Line reference this
 * bounded context does not implement yet (M2-T8.1's migration
 * docblock). It does not run {@see AccountHierarchyPolicy} on a
 * single read — restoring a stored `parentId` through
 * {@see AccountPersistenceAdapter::fromPersistedRow()} is not a
 * business parent-assignment decision, exactly as that adapter's own
 * docblock already establishes; graph-wide hierarchy loading is
 * deliberately left for a future task.
 *
 * **Uniqueness and concurrency.** Tenant-scoped Account Code
 * uniqueness (`COA-003`) is never re-implemented here as an
 * application-level pre-check race — the real `UNIQUE (tenant_id,
 * account_code)` PostgreSQL constraint on the `accounts` table is the
 * single, race-safe authority. {@see codeExists()} exists only for
 * UX/preflight convenience and MUST NOT be treated as a uniqueness
 * guarantee on its own (a check-then-insert built from it is
 * inherently racy). {@see save()} instead lets the constraint reject
 * a genuine collision and translates that specific failure into
 * {@see DuplicateAccountCodeException} — this rejection is origin-
 * agnostic: a User-Created Account colliding with an existing System
 * Account's Code in the same Tenant is rejected exactly the same way
 * a User-Created/User-Created collision is (`COA-T056`), since the
 * database constraint itself carries no notion of Account Origin. No
 * other persistence failure is translated — every other
 * {@see QueryException} (a foreign key violation on `parent_id`, the
 * self-parenting `CHECK`, or a canonical-value `CHECK` on
 * `account_type`/`account_origin`) propagates unmodified, since
 * translating it would require inventing exception types this task
 * has no concrete need for yet.
 */
final class AccountRepository
{
    private const TABLE = 'accounts';

    private const ACCOUNT_CODE_UNIQUE_CONSTRAINT = 'accounts_tenant_id_account_code_unique';

    private const UNIQUE_VIOLATION_SQLSTATE = '23505';

    private readonly AccountPersistenceAdapter $adapter;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->adapter = new AccountPersistenceAdapter;
    }

    /**
     * Persist an Account: `INSERT` if `$account`'s identifier is new,
     * or update the existing row if it already exists — but only
     * `active`, the configured `posting_eligible` state, and
     * `parent_id`, the three fields the current Account domain
     * actually exposes a valid path to change
     * ({@see Account::deactivate()}, {@see Account::withParent()}).
     *
     * An existing row's Tenant, Account Code, Account Name, Account
     * Type, and Account Origin are verified to still match before any
     * update proceeds. The current Account domain exposes no
     * `rename()`, `changeCode()`, `changeType()`, or `changeTenant()`
     * operation, so a `$account` that disagrees with the persisted row
     * on any of those fields cannot represent a legitimate state
     * transition — it is rejected outright, and the existing row is
     * left completely unchanged. This repository does not decide
     * *how* an Account became inconsistent (a stale in-memory
     * instance, a programming error, two different Accounts colliding
     * on identifier); it only refuses to let persistence become a
     * backdoor around invariants the domain itself already protects.
     *
     * **Concurrency.** The existence check and any resulting update
     * happen inside one database transaction, with the existing row
     * (if any) locked via `SELECT ... FOR UPDATE`. A second concurrent
     * `save()` for the same {@see AccountId} therefore blocks until
     * the first transaction commits or rolls back, rather than reading
     * stale state and silently overwriting it — there is no
     * "check exists, then insert/update" window a race can land in.
     * A brand-new identifier's insert still relies solely on the real
     * `UNIQUE (tenant_id, account_code)` constraint as the final,
     * race-safe authority for Account Code collisions between two
     * concurrent inserts of genuinely different Accounts.
     *
     * @throws DuplicateAccountCodeException if the Account's (Tenant,
     *                                       Account Code) pair already belongs to a different Account —
     *                                       detected via the real database constraint, not an
     *                                       application-level pre-check.
     * @throws ImmutableAccountStateException if an Account already
     *                                        persisted under `$account`'s identifier has a different
     *                                        Tenant, Account Code, Account Name, Account Type, or
     *                                        Account Origin.
     */
    public function save(Account $account): void
    {
        $row = $this->adapter->toPersistedRow($account);

        try {
            $this->connection->transaction(function () use ($account, $row): void {
                /** @var object{tenant_id: string, account_id: string, account_code: string, account_name: string, account_type: string, account_origin: string, active: bool, posting_eligible: bool, parent_id: string|null}|null $existing */
                $existing = $this->connection->table(self::TABLE)
                    ->where('account_id', $row['account_id'])
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    $this->connection->table(self::TABLE)->insert($row);

                    return;
                }

                $this->assertImmutableFieldsUnchanged($account, $existing, $row);

                $this->connection->table(self::TABLE)
                    ->where('account_id', $row['account_id'])
                    ->update([
                        'active' => $row['active'],
                        'posting_eligible' => $row['posting_eligible'],
                        'parent_id' => $row['parent_id'],
                    ]);
            });
        } catch (QueryException $e) {
            if ($this->isAccountCodeUniqueViolation($e)) {
                throw DuplicateAccountCodeException::forCode($account->tenantId(), $account->code());
            }

            throw $e;
        }
    }

    /**
     * Retrieve an Account by its stable identifier, scoped to the
     * given Tenant — an Account belonging to a different Tenant than
     * `$tenantId`, even one with the same `$accountId`, is never
     * returned (`COA-001`).
     */
    public function findById(TenantId $tenantId, AccountId $accountId): ?Account
    {
        /** @var object{tenant_id: string, account_id: string, account_code: string, account_name: string, account_type: string, account_origin: string, active: bool, posting_eligible: bool, parent_id: string|null}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('account_id', $accountId->toString())
            ->first();

        return $row === null ? null : $this->adapter->fromPersistedRow($this->rowToArray($row));
    }

    /**
     * Retrieve an Account by its Account Code, scoped to the given
     * Tenant (`COA-003`) — the same Code in a different Tenant is a
     * different, unrelated Account, and is never returned here.
     */
    public function findByCode(TenantId $tenantId, AccountCode $accountCode): ?Account
    {
        /** @var object{tenant_id: string, account_id: string, account_code: string, account_name: string, account_type: string, account_origin: string, active: bool, posting_eligible: bool, parent_id: string|null}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('account_code', $accountCode->toString())
            ->first();

        return $row === null ? null : $this->adapter->fromPersistedRow($this->rowToArray($row));
    }

    /**
     * Whether an Account Code is already in use within a Tenant.
     *
     * **UX/preflight only.** This is not, and must never be treated
     * as, a uniqueness guarantee: the answer can be stale the instant
     * after it is returned under concurrent writes. {@see save()}'s
     * reliance on the real database constraint is the only race-safe
     * authority (`COA-003`, `COA-T056`).
     */
    public function codeExists(TenantId $tenantId, AccountCode $accountCode): bool
    {
        return $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('account_code', $accountCode->toString())
            ->exists();
    }

    private function isAccountCodeUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === self::UNIQUE_VIOLATION_SQLSTATE
            && str_contains($e->getMessage(), self::ACCOUNT_CODE_UNIQUE_CONSTRAINT);
    }

    /**
     * @param  object{tenant_id: string, account_id: string, account_code: string, account_name: string, account_type: string, account_origin: string, active: bool, posting_eligible: bool, parent_id: string|null}  $existing
     * @param  array{tenant_id: string, account_id: string, account_code: string, account_name: string, account_type: string, account_origin: string, active: bool, posting_eligible: bool, parent_id: string|null}  $row
     *
     * @throws ImmutableAccountStateException
     */
    private function assertImmutableFieldsUnchanged(Account $account, object $existing, array $row): void
    {
        $immutableFields = [
            'tenant_id' => 'TenantId',
            'account_code' => 'AccountCode',
            'account_name' => 'AccountName',
            'account_type' => 'AccountType',
            'account_origin' => 'AccountOrigin',
        ];

        foreach ($immutableFields as $field => $label) {
            if ($row[$field] !== $existing->{$field}) {
                throw ImmutableAccountStateException::forField($account->tenantId(), $account->id(), $label);
            }
        }
    }

    /**
     * @return array{tenant_id: string, account_id: string, account_code: string, account_name: string, account_type: string, account_origin: string, active: bool, posting_eligible: bool, parent_id: string|null}
     */
    private function rowToArray(object $row): array
    {
        /** @var object{tenant_id: string, account_id: string, account_code: string, account_name: string, account_type: string, account_origin: string, active: bool, posting_eligible: bool, parent_id: string|null} $row */
        return [
            'tenant_id' => $row->tenant_id,
            'account_id' => $row->account_id,
            'account_code' => $row->account_code,
            'account_name' => $row->account_name,
            'account_type' => $row->account_type,
            'account_origin' => $row->account_origin,
            'active' => (bool) $row->active,
            'posting_eligible' => (bool) $row->posting_eligible,
            'parent_id' => $row->parent_id,
        ];
    }
}
