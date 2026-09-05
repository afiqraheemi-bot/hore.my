<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal;

/**
 * The kind of correction a Journal represents (AETS-004 §16, §17,
 * `JRN-018`–`JRN-020`) — exactly two cases, Reversal and Replacement.
 * An ordinary Journal carries no `CorrectionType` at all (`null`), not
 * a third case here (M5 architecture decision).
 *
 * - **Reversal** — neutralizes an Original Journal's financial effect
 *   exactly (§16). Its lines are always derived automatically from
 *   the Journal it corrects, never supplied freely by a caller (see
 *   {@see Journal::reverse()}).
 * - **Replacement** — records the corrected accounting effect once a
 *   Reversal has neutralized the original (§17). Its lines are the
 *   caller-supplied corrected effect — this type carries no derivation
 *   rule of its own, unlike Reversal.
 *
 * **Not a JournalState.** {@see JournalState} already documents that
 * a Reversal or Replacement is "an entirely new Journal... never a
 * third JournalState value" — this enum is the separate, additional
 * fact of *why* that new Journal exists, carried alongside its own
 * independent Draft/Posted lifecycle, never instead of it.
 *
 * Deliberately unbacked, for the same reason as {@see JournalState}
 * and {@see JournalDirection}: AETS-004 specifies no canonical
 * persisted representation, so no backing value is invented here.
 */
enum CorrectionType
{
    case Reversal;
    case Replacement;
}
