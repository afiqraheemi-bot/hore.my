<?php

declare(strict_types=1);

namespace App\Domain\Banking;

/**
 * Which Transactions-domain record type a Match's Journal was produced
 * by (M18, SRS BNK-005) — see the owning `matches` migration's own
 * docblock for why this is a deliberate v1 subset of BNK-005's full
 * "invois, belanja, pindahan dan dokumen" list, not the complete set.
 */
enum MatchSourceType
{
    case Expense;
    case Income;
    case Transfer;
    case OwnerEquity;
}
