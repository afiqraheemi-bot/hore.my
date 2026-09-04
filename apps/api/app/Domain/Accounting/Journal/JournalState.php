<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal;

/**
 * A Journal's lifecycle state (AETS-004 §9, `JRN-004`) — exactly two
 * cases, Draft and Posted. A Journal MUST record its current state,
 * and only these two values are valid (`JRN-T015`).
 *
 * - **Draft** — assembled, not yet posted; has no ledger effect and
 *   MUST NOT be treated as authoritative for Balance, Trial Balance,
 *   or any other Projection. It is the only state a Posting Command
 *   may transition to Posted (`JRN-T023`).
 * - **Posted** — the result of a successful Posting Command;
 *   authoritative (AETS-002 invariant 5) and immutable — append-only,
 *   never updated or deleted through the application (§15, `JRN-005`,
 *   `JRN-006`). Terminal: a Posted Journal MUST NOT transition back to
 *   Draft, or to any other state, ever.
 *
 * **Transitions are deliberately not encoded here.** The only
 * permitted transition (Draft → Posted) happens exclusively via a
 * successful Posting Command (§10–§12) — that is behavior belonging to
 * the future Journal aggregate and Posting Engine, not to this state
 * primitive. This enum names the two valid values only; it has no
 * method that inspects, permits, or performs a transition, and no
 * property beyond every PHP enum case's implicit `name`.
 *
 * **No additional lifecycle state exists.** AETS-004 defines exactly
 * two: no Pending, Approved, Processing, Cancelled, Reversed,
 * Replaced, or Deleted state exists at the Journal-state level. A
 * Reversal and a Replacement are each an entirely new Journal (§16,
 * §17) — each with its own independent Draft/Posted lifecycle — never
 * a third JournalState value the original Journal transitions into.
 *
 * Deliberately unbacked, for the same reason as
 * {@see JournalDirection}: AETS-004 specifies no canonical persisted
 * representation yet, so no backing value is invented here.
 */
enum JournalState
{
    case Draft;
    case Posted;
}
