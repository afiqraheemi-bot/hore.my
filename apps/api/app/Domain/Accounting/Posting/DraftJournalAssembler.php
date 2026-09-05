<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\Exception\InsufficientJournalLinesException;
use App\Domain\Accounting\Journal\Exception\MixedCurrencyJournalException;
use App\Domain\Accounting\Journal\Exception\UnbalancedJournalException;
use App\Domain\Accounting\Journal\Journal;

/**
 * Assembles a `PostingCommand` proposing a fresh, not-yet-persisted
 * Journal into a candidate Draft `Journal`, through the Journal
 * domain's own `create()` factory (AETS-004 §6–§8; AETS-007 §11,
 * §14 steps 6–8).
 *
 * **A boundary, not a validator.** This class enforces nothing of its
 * own. It delegates entirely to `Journal::create()`, which already
 * enforces the minimum-two-lines (`JRN-002`), single-Currency
 * (`JRN-011`), and exact-balance (`JRN-007`) invariants — restated at
 * the Posting Command level as `POST-012`, `POST-013`, and `POST-016`.
 * Every typed exception `Journal::create()` throws
 * (`InsufficientJournalLinesException`, `MixedCurrencyJournalException`,
 * `UnbalancedJournalException`) propagates unchanged: this class
 * neither catches nor wraps any of them, so the Journal domain's own
 * already-distinguishable failure categories remain exactly that,
 * with nothing duplicated or re-decided here.
 *
 * **What this does not do.** It performs no I/O, uses no repository,
 * inspects no existing Journal state, and does not decide whether the
 * command's proposed Journal identity is fresh or references an
 * existing Draft Journal (AETS-007 §11) — that decision, and the
 * `Journal::reconstitute()` path an existing-Draft reference would
 * require, is out of scope here. It does not validate Tenant
 * ownership, resolve an Actor, validate Account existence or status,
 * handle idempotency, enforce a Source Fingerprint requirement, or
 * persist anything — every one of those remains the future Posting
 * Engine's responsibility (AETS-007 §14 onward).
 *
 * **Proof, not durable success.** A `Journal` this method returns is
 * a candidate — the same in-memory, unpersisted object
 * `Journal::create()` itself returns. It proves the Journal
 * structural/balance validation pipeline steps succeed for the given
 * command's lines; it does not prove the command "posts successfully"
 * in the full sense AETS-007 §19/§20 and `POST-T065` require (exactly
 * one *authoritative Posted* Journal) — that requires the atomic
 * persistence step this class deliberately does not implement.
 */
final class DraftJournalAssembler
{
    /**
     * @throws InsufficientJournalLinesException
     * @throws MixedCurrencyJournalException
     * @throws UnbalancedJournalException
     */
    public function assemble(PostingCommand $command): Journal
    {
        return Journal::create(
            $command->tenantId(),
            $command->journalId(),
            $command->lines(),
        );
    }
}
