<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

/**
 * The pure, storage-free rule that determines whether two
 * `PostingCommand`s represent the same logical Journal request or a
 * materially different one, per AETS-007 §6.1: "a materially
 * different logical request (a different proposed Journal identity,
 * different Journal Lines, different Account references, or a
 * different amount) is a conflicting reuse, not a safe replay."
 *
 * **A prerequisite, not the idempotency mechanism itself.** This
 * class does not detect a replay, does not look up or reserve an
 * Idempotency Key, does not implement duplicate prevention, and does
 * not decide what happens when two commands are or are not
 * equivalent — it answers exactly one question, given two commands a
 * caller has already established share the same idempotency scope.
 * The concrete (Tenant, Idempotency Key) storage/uniqueness schema
 * AETS-007 §6.1/§15/§26 explicitly defer remains entirely
 * undecided and is not touched here.
 *
 * **Idempotency Key is never compared here, deliberately.** Two
 * commands are only ever meaningfully asked "are these the same
 * logical request?" once a caller has already determined they share
 * the same (Tenant, Idempotency Key) pair — that scoping is the
 * future caller's responsibility (a storage-backed lookup this class
 * has no part in), never this comparator's. Comparing Idempotency Key
 * here would conflate two distinct concerns: identifying *which*
 * command execution a key belongs to, and judging whether *two*
 * commands already known to share one key actually agree.
 *
 * **What "materially different" means here, and what it does not.**
 * Per AETS-007 §6.1's own wording, the logical payload is: the
 * proposed Journal identity, and the proposed Journal Lines
 * (Account reference, Money amount, Currency, and Direction — all
 * already covered by `JournalLine`'s own existing value-equality
 * contract, checked pairwise in order). TenantId is compared as the
 * scoping context §6.1 already assumes ("for the same Tenant")
 * before "materially different" is even asked. Actor, Source, Source
 * Fingerprint, and Evidence references are deliberately excluded —
 * AETS-007 §6.1 does not name any of them as part of the logical
 * payload two retries of the same command must agree on; a caller may
 * legitimately resubmit the same accounting request through a
 * different Actor session, a different Source trace, or with a
 * Source Fingerprint or Evidence reference added or changed, without
 * that making it a different logical request.
 */
final class PostingCommandLogicalEquivalence
{
    public function equivalent(PostingCommand $left, PostingCommand $right): bool
    {
        if (! $left->tenantId()->equals($right->tenantId())) {
            return false;
        }

        if (! $left->journalId()->equals($right->journalId())) {
            return false;
        }

        $leftLines = $left->lines();
        $rightLines = $right->lines();

        if (count($leftLines) !== count($rightLines)) {
            return false;
        }

        foreach ($leftLines as $index => $leftLine) {
            if (! $leftLine->equals($rightLines[$index])) {
                return false;
            }
        }

        return true;
    }
}
