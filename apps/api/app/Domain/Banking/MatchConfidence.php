<?php

declare(strict_types=1);

namespace App\Domain\Banking;

/**
 * The discrete confidence value a Match is confirmed under (AETS-008
 * §12.7, `BNK-019`) — not a calibrated numeric score. The currently
 * supported matcher ({@see BankTransactionMatchSuggester}) only ever
 * produces exact-criterion candidates (§6), so `Exact` is the only case
 * today; a genuinely fuzzy, weighted, or probability-based confidence
 * is explicitly out of scope pending its own future decision (AETS-008
 * §2.2), and adding it later is additive — a new case here — never a
 * breaking change to this schema.
 */
enum MatchConfidence
{
    case Exact;
}
