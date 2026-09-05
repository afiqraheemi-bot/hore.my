<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Audit;

/**
 * The specific domain action an `AuditEvent` records (AETS-010
 * §7, §10) — which material action, out of the currently-defined set,
 * this event is about.
 *
 * **Unbacked, deliberately.** Mirrors `CorrectionType`'s own reasoning:
 * this codebase's established pattern is to keep a closed, small domain
 * enum unbacked and translate to/from its own `->name` at the
 * persistence boundary — the same pattern already used for
 * `JournalState`, `JournalDirection`, and `CorrectionType`.
 *
 * **A fixed set of three, for now.** AETS-010 §7 and §10 define exactly
 * these three cases — one per producer the current system has
 * (ordinary Posting, Reversal, Replacement). AETS-010 §13 governs how a
 * future case is added: only additively, by a future AETS document or a
 * MINOR revision, never by redefining or removing one of these three.
 */
enum AuditAction
{
    case JournalPosted;
    case JournalReversed;
    case JournalReplaced;
}
