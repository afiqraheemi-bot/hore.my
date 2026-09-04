<?php

declare(strict_types=1);

namespace App\Domain\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountHierarchyException;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * The Account aggregate root (AETS-005 §6): reference/master data
 * within a Tenant's Chart of Accounts, not a transactional aggregate —
 * it carries no lifecycle event stream comparable to Journal's
 * Draft/Posted transition (AETS-004 §9).
 *
 * This revision (M2-T5.2) adds optional parent/child hierarchy (§12):
 * an Account MAY reference at most one parent, by {@see AccountId}
 * only — never by holding the parent Account object itself, so this
 * aggregate never becomes responsible for loading another Account.
 * {@see withParent()} is the *only* public path that can attach a
 * parent, and it always performs every hierarchy check in full —
 * self-parenting, same-Tenant, and cycle detection (delegated
 * internally to {@see AccountHierarchyPolicy}) — so cycle prevention
 * cannot be bypassed by a caller who forgets a separate validation
 * step; there is no other public API that mutates the parent
 * reference. See M2-T5.2's report.
 *
 * The System-vs-User-Created distinction (§15, §16),
 * `activate()`/`rename()`/code or type changes, and persistence remain
 * deliberately unimplemented — see M2-T5.1B's and M2-T5.2's reports.
 *
 * Equality is identity-based (same {@see AccountId}), not value-based:
 * an Account is an entity with a stable identity Journal Line
 * references by identifier alone (AETS-005 §7, §19), unlike the
 * value-equal {@see AccountCode}/{@see AccountId}/{@see AccountName}
 * it carries.
 */
final class Account
{
    private readonly TenantId $tenantId;

    private readonly AccountId $id;

    private readonly AccountCode $code;

    private readonly AccountName $name;

    private readonly AccountType $type;

    private readonly NormalBalance $normalBalance;

    private readonly bool $active;

    private readonly bool $postingEligible;

    private readonly ?AccountId $parentId;

    private function __construct(
        TenantId $tenantId,
        AccountId $id,
        AccountCode $code,
        AccountName $name,
        AccountType $type,
        NormalBalance $normalBalance,
        bool $active,
        bool $postingEligible,
        ?AccountId $parentId,
    ) {
        $this->tenantId = $tenantId;
        $this->id = $id;
        $this->code = $code;
        $this->name = $name;
        $this->type = $type;
        $this->normalBalance = $normalBalance;
        $this->active = $active;
        $this->postingEligible = $postingEligible;
        $this->parentId = $parentId;
    }

    /**
     * Construct a new Account. Always Active at creation (AETS-005
     * §14) — there is no parameter for it; the only way to an Inactive
     * Account is {@see deactivate()}.
     *
     * Normal Balance is never a parameter: it is always the single
     * canonical value {@see AccountType::normalBalance()} derives, so
     * an inconsistent Account Type/Normal Balance pairing cannot be
     * constructed (AETS-005 §11, `COA-005`).
     *
     * Every input beyond the raw posting-eligibility flag is an
     * already-validated Value Object — this method performs no
     * further validation of its own (Tenant, identifier, code, and
     * name canonicality are each that Value Object's own concern).
     *
     * Always created with no parent (§12) — hierarchy is optional and
     * MUST NOT be required for every Account; the only way to a parent
     * is {@see withParent()}.
     */
    public static function create(
        TenantId $tenantId,
        AccountId $id,
        AccountCode $code,
        AccountName $name,
        AccountType $type,
        bool $isPostingEligible,
    ): self {
        return new self(
            $tenantId,
            $id,
            $code,
            $name,
            $type,
            $type->normalBalance(),
            true,
            $isPostingEligible,
            null,
        );
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function id(): AccountId
    {
        return $this->id;
    }

    public function code(): AccountCode
    {
        return $this->code;
    }

    public function name(): AccountName
    {
        return $this->name;
    }

    public function type(): AccountType
    {
        return $this->type;
    }

    public function normalBalance(): NormalBalance
    {
        return $this->normalBalance;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Whether a Journal Line may currently be posted against this
     * Account: both Active (§14) and posting-eligible (§13) at once
     * (`COA-008`, `COA-009`, `COA-010`) — an Inactive Account reports
     * posting not allowed even while its own posting-eligibility
     * configuration remains unchanged (§13: "even if its
     * posting-eligibility state is otherwise posting-eligible").
     */
    public function isPostingAllowed(): bool
    {
        return $this->active && $this->postingEligible;
    }

    /**
     * This Account's parent, by identifier only — `null` if it has
     * none. Hierarchy is optional (§12); a freshly {@see create()}d
     * Account always starts with no parent.
     */
    public function parentId(): ?AccountId
    {
        return $this->parentId;
    }

    /**
     * Active -> Inactive (AETS-005 §14). Always returns a new Account
     * instance; every other field — Tenant, identifier, Code, Name,
     * Type, Normal Balance, and the underlying posting-eligibility
     * configuration — is preserved unchanged. Naturally idempotent:
     * calling this on an already-Inactive Account deterministically
     * produces another Inactive Account, equal in every observable
     * respect.
     */
    public function deactivate(): self
    {
        return new self(
            $this->tenantId,
            $this->id,
            $this->code,
            $this->name,
            $this->type,
            $this->normalBalance,
            false,
            $this->postingEligible,
            $this->parentId,
        );
    }

    /**
     * Assign a parent Account (AETS-005 §12), by identifier only —
     * this Account never stores or otherwise holds onto the parent
     * Account object itself. Always returns a new Account instance;
     * every other field — Tenant, identifier, Code, Name, Type, Normal
     * Balance, Active state, and posting-eligibility configuration —
     * is preserved unchanged.
     *
     * This is the *only* public path that can attach a parent, and it
     * always performs every hierarchy check in full — there is no way
     * to reach a parent assignment that skips any of them:
     *
     * 1. self-parenting — decidable from `$this` and `$parent` alone;
     * 2. same-Tenant — likewise decidable from the two instances; and
     * 3. cycle detection (direct or transitive) — delegated internally
     *    to {@see AccountHierarchyPolicy}, which walks `$parent`'s
     *    ancestry through `$knownAccounts`. That check fails closed:
     *    if the chain references an Account not present in
     *    `$knownAccounts`, the assignment is rejected as unproven, not
     *    accepted as safe by assumption (see {@see AccountHierarchyPolicy}).
     *
     * @param  list<self>  $knownAccounts  every Account whose parent
     *                                     pointer might need to be walked to prove the assignment
     *                                     cycle-free — supplied by the caller from already-loaded,
     *                                     in-memory Account instances. This method never loads
     *                                     anything itself.
     *
     * @throws InvalidAccountHierarchyException if `$parent` is this
     *                                          same Account (`COA-006` — direct self-cycle), if `$parent`
     *                                          belongs to a different Tenant (`COA-007`), if the
     *                                          assignment would create an indirect/transitive cycle
     *                                          (`COA-006`), or if `$parent`'s ancestry cannot be fully
     *                                          proven cycle-free from `$knownAccounts` alone.
     */
    public function withParent(self $parent, array $knownAccounts): self
    {
        if ($this->id->equals($parent->id)) {
            throw InvalidAccountHierarchyException::forSelfParenting($this->id);
        }

        if (! $this->tenantId->equals($parent->tenantId)) {
            throw InvalidAccountHierarchyException::forCrossTenantParent($this->tenantId, $parent->tenantId);
        }

        AccountHierarchyPolicy::assertParentAssignmentIsCycleFree($this, $parent, $knownAccounts);

        return new self(
            $this->tenantId,
            $this->id,
            $this->code,
            $this->name,
            $this->type,
            $this->normalBalance,
            $this->active,
            $this->postingEligible,
            $parent->id,
        );
    }

    /**
     * Identity equality: true iff both Accounts share the same
     * {@see AccountId}. Not value equality over every field.
     */
    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }
}
