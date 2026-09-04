<?php

declare(strict_types=1);

namespace App\Domain\Accounting\ChartOfAccounts;

use App\Domain\Shared\Tenancy\TenantId;

/**
 * The Account aggregate root (AETS-005 §6): reference/master data
 * within a Tenant's Chart of Accounts, not a transactional aggregate —
 * it carries no lifecycle event stream comparable to Journal's
 * Draft/Posted transition (AETS-004 §9).
 *
 * This revision (M2-T5.1B) adds Tenant ownership (§17) — every Account
 * now belongs to exactly one, explicit {@see TenantId} — and the
 * minimum lifecycle AETS-005 §14 requires: {@see deactivate()}.
 * Hierarchy (§12), the System-vs-User-Created distinction (§15, §16),
 * `activate()`/`rename()`/code or type changes, and persistence remain
 * deliberately unimplemented — see M2-T5.1B's report.
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

    private function __construct(
        TenantId $tenantId,
        AccountId $id,
        AccountCode $code,
        AccountName $name,
        AccountType $type,
        NormalBalance $normalBalance,
        bool $active,
        bool $postingEligible,
    ) {
        $this->tenantId = $tenantId;
        $this->id = $id;
        $this->code = $code;
        $this->name = $name;
        $this->type = $type;
        $this->normalBalance = $normalBalance;
        $this->active = $active;
        $this->postingEligible = $postingEligible;
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
