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
 * This revision (M2-T5.3) also adds {@see reconstitute()}: the
 * domain-owned counterpart to {@see create()} a future persistence
 * adapter uses to load an already-existing Account, rather than
 * replaying `create()` → `deactivate()` → `withParent()` transitions
 * to arrive back at previously-persisted state. It restores
 * Active/Inactive state, posting-eligibility, and an optional
 * `parentId` exactly, but performs no hierarchy validation of its own
 * — see {@see reconstitute()}'s own docblock for why that is correct,
 * not a weakening of {@see AccountHierarchyPolicy}.
 *
 * This revision (M2-T7) adds the System-vs-User-Created distinction
 * (§15, §16) as an explicit, required {@see AccountOrigin} — every
 * Account now states its origin, never assumed. A System Account's
 * protected semantics (Code/Type/Normal Balance immutability, no
 * deletion, no cross-Tenant reassignment, §15) are not enforced by new
 * runtime guards here: the current public API already exposes no
 * delete, change-Tenant, change-Type, or change-Code operation for
 * *any* Account, System or otherwise, so those protections hold
 * structurally, by the absence of a violating path, not by an
 * origin-conditional check. Whichever future method introduces such a
 * mutation is responsible for enforcing them at that point — see
 * M2-T7's report.
 *
 * `activate()`/`rename()`/code or type changes, and persistence remain
 * deliberately unimplemented — see M2-T5.1B's, M2-T5.2's, M2-T5.3's,
 * and M2-T7's reports.
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

    private readonly AccountOrigin $origin;

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
        AccountOrigin $origin,
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
        $this->origin = $origin;
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
     *
     * `$origin` (§15, §16) is a required parameter with no default —
     * AETS-005 names two distinct creation paths ("deterministic
     * tenant-setup logic" for a System Account, versus "the ordinary,
     * Tenant-scoped account-creation path" for a User-Created one) but
     * does not state that this single factory method is exclusively
     * either one, so no origin is silently assumed here; the caller
     * must say which this Account is.
     */
    public static function create(
        TenantId $tenantId,
        AccountId $id,
        AccountCode $code,
        AccountName $name,
        AccountType $type,
        bool $isPostingEligible,
        AccountOrigin $origin,
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
            $origin,
            null,
        );
    }

    /**
     * Reconstruct an Account from previously-persisted state (M2-T5.3)
     * — the domain-owned counterpart to {@see create()} a future
     * persistence adapter uses to load an *existing* Account, instead
     * of forcing it to replay `create()` → `deactivate()` →
     * `withParent()` transitions to arrive back at the same state.
     * `create()`'s own semantics are unchanged: it remains the only
     * path to a genuinely *new* Account, always Active, always
     * parentless.
     *
     * Like `create()`, Normal Balance is never a parameter — it is
     * always the single canonical value {@see AccountType::normalBalance()}
     * derives from `$type`, so reconstruction can no more produce an
     * inconsistent Account Type/Normal Balance pairing than `create()`
     * can (AETS-005 §11, `COA-005`).
     *
     * Every other field this method accepts is restored exactly as
     * given — Active/Inactive state, the configured posting-eligibility
     * flag, the {@see AccountOrigin} (§15, §16), and the optional
     * parent identifier — with no further validation, since this
     * method's whole premise is that the supplied state was already
     * validated once, at the point it was originally written (by
     * `create()`/`withParent()`/`deactivate()`'s own checks), not that
     * it is being decided now.
     *
     * **Hierarchy note — read carefully.** Restoring `$parentId` here
     * is not, and cannot be, proof that the wider hierarchy graph is
     * still cycle-free: unlike {@see withParent()}, this method takes
     * no `$knownAccounts` and performs no self-parenting, same-Tenant,
     * or cycle check at all — {@see AccountHierarchyPolicy} is
     * deliberately not consulted here, and none of that validation is
     * weakened or reproduced by this method. That is by design, not an
     * oversight: proving graph-wide cycle-freedom on every single
     * reconstruction would require loading the entire hierarchy on
     * every read, which this domain-only method must not require and
     * has no way to do. Full hierarchy integrity for the reconstructed
     * graph remains the responsibility of whatever repository or
     * orchestration layer reconstructs a whole set of Accounts (a
     * concern this task deliberately does not implement). This method
     * is therefore never a substitute for {@see withParent()} as a
     * business operation — it MUST NOT be used to assign or change a
     * parent as a decision; it only replays an already-decided,
     * already-persisted fact.
     */
    public static function reconstitute(
        TenantId $tenantId,
        AccountId $id,
        AccountCode $code,
        AccountName $name,
        AccountType $type,
        bool $active,
        bool $isPostingEligible,
        AccountOrigin $origin,
        ?AccountId $parentId,
    ): self {
        return new self(
            $tenantId,
            $id,
            $code,
            $name,
            $type,
            $type->normalBalance(),
            $active,
            $isPostingEligible,
            $origin,
            $parentId,
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
     * The posting-eligibility state as configured (§13) — independent
     * of Active state, unlike {@see isPostingAllowed()}, which
     * combines the two into the single effective answer a Posting
     * Command would check. An Inactive Account still reports its
     * originally-configured value here, even though
     * `isPostingAllowed()` always answers `false` for any Inactive
     * Account regardless of it.
     *
     * Added in M2-T6 as a genuine gap fix: a persistence adapter needs
     * this distinct value to round-trip an Account exactly —
     * `isPostingAllowed()`'s combined answer alone cannot be inverted
     * back to the original configuration once Active is `false`, since
     * `active && postingEligible` is `false` whether `postingEligible`
     * was `true` or `false` in that case.
     */
    public function isPostingEligible(): bool
    {
        return $this->postingEligible;
    }

    /**
     * How this Account came to exist (§15, §16) — System or
     * UserCreated, always explicit, never assumed. See this class's
     * own docblock for how System Account protections are guaranteed
     * given the current public API, rather than enforced by a runtime
     * check keyed on this value.
     */
    public function origin(): AccountOrigin
    {
        return $this->origin;
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
     * Type, Normal Balance, Origin, and the underlying
     * posting-eligibility configuration — is preserved unchanged.
     * Naturally idempotent: calling this on an already-Inactive
     * Account deterministically produces another Inactive Account,
     * equal in every observable respect.
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
            $this->origin,
            $this->parentId,
        );
    }

    /**
     * Assign a parent Account (AETS-005 §12), by identifier only —
     * this Account never stores or otherwise holds onto the parent
     * Account object itself. Always returns a new Account instance;
     * every other field — Tenant, identifier, Code, Name, Type, Normal
     * Balance, Origin, Active state, and posting-eligibility
     * configuration — is preserved unchanged.
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
            $this->origin,
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
