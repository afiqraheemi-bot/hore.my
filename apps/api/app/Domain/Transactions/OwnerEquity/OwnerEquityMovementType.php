<?php

declare(strict_types=1);

namespace App\Domain\Transactions\OwnerEquity;

use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Http\Controllers\Api\CapitalContributionController;
use App\Http\Controllers\Api\OwnerDrawingController;
use App\Infrastructure\Transactions\OwnerEquity\OwnerEquityTransactionRepository;

/**
 * The two Owner Equity movements M15 supports: a Capital Contribution
 * (the owner injects personal funds into the business) and a Drawing
 * (the owner withdraws business funds for personal use).
 *
 * **Why one domain concept, not two.** Unlike Income/Expense (M7/M9),
 * whose credit and debit sides each require a genuinely different
 * Account Type, a Contribution and a Drawing require exactly the same
 * two Account Types (Asset, Equity) — only which side is debited and
 * which is credited flips. {@see OwnerEquityTransactionToPostingCommandTranslator}
 * is the only place that branches on this enum; every other class in
 * this namespace (validation, persistence, identity) is identical
 * regardless of movement type. Two separate HTTP endpoints
 * ({@see CapitalContributionController},
 * {@see OwnerDrawingController}) each fix
 * this value so a caller never has to pass it explicitly — the API
 * contract stays as explicit as Income/Expense's own, only the domain
 * layer is shared.
 *
 * Deliberately unbacked, mirroring {@see AccountType}'s
 * own reasoning: no canonical persisted string representation is
 * invented here — {@see OwnerEquityTransactionRepository}
 * owns that mapping decision.
 */
enum OwnerEquityMovementType
{
    case Contribution;
    case Drawing;
}
