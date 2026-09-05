<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;

/**
 * Validates every Account a `PostingCommand`'s proposed Journal Lines
 * reference, through exactly the four-step sequence AETS-007 §12
 * reuses from AETS-005 §19: the identifier resolves to an existing
 * Account, that Account belongs to the command's own Tenant, is
 * currently Active, and is currently posting-eligible.
 *
 * **How "exists" and "same Tenant" combine into one check.**
 * `AccountRepository::findById(TenantId, AccountId)` is already
 * tenant-scoped — it returns `null` both when no Account exists under
 * that identifier at all, and when one exists only under a
 * *different* Tenant (`COA-001`; the repository's own docblock is
 * explicit that this is deliberate, not an oversight). This validator
 * relies on that existing behavior exactly as it stands: it calls
 * `findById()` once per line, using the command's own TenantId, and
 * treats a `null` result as a single "unresolved" rejection — it does
 * not, and must not, introduce a second, tenant-unscoped lookup to
 * tell "nonexistent" and "wrong Tenant" apart, since doing so would
 * itself leak cross-tenant Account existence. See
 * {@see RejectedAccountReferenceException}'s own docblock for the
 * full reasoning and the resulting, deliberate deviation from AETS-007
 * §18's literal "each its own category" wording for this one pair.
 *
 * **Rejects on the first invalid line, deterministically.** Lines are
 * checked in order; the first line that fails any of the three
 * observable checks (unresolved, Inactive, non-posting-eligible)
 * throws immediately — there is no partial acceptance, and no
 * collection of every failing line.
 *
 * **What this does not do.** It never consults an Account's Normal
 * Balance (AETS-005 §11) to infer, default, or validate a Journal
 * Line's Direction. It performs no Actor-to-Tenant resolution, no
 * Evidence-ownership validation, no existing-Draft-Journal Tenant
 * validation, no Account creation or mutation, no Source Fingerprint
 * policy, no idempotency/replay handling, no Journal persistence, no
 * transaction orchestration, and no Audit/Outbox work — every one of
 * those remains a separate, future Posting Engine responsibility.
 */
final class PostingCommandAccountValidator
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
    ) {}

    /**
     * @throws RejectedAccountReferenceException if any referenced
     *                                           Account cannot be resolved for the command's Tenant, is
     *                                           Inactive, or is not posting-eligible.
     */
    public function validate(PostingCommand $command): void
    {
        foreach ($command->lines() as $line) {
            $accountId = $line->accountId();
            $account = $this->accountRepository->findById($command->tenantId(), $accountId);

            if ($account === null) {
                throw RejectedAccountReferenceException::forUnresolvedAccount($accountId);
            }

            if (! $account->isActive()) {
                throw RejectedAccountReferenceException::forInactiveAccount($accountId);
            }

            if (! $account->isPostingEligible()) {
                throw RejectedAccountReferenceException::forNonPostingEligibleAccount($accountId);
            }
        }
    }
}
