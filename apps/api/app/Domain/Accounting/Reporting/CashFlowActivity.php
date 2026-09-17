<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reporting;

use App\Infrastructure\Accounting\Reporting\CashFlowStatementQuery;

/**
 * The three classic Cash Flow Statement sections (AETS-009 §22, SRS
 * RPT-003) — Operating, Investing, Financing — resolved from the
 * *counterparty* Account Type on the other side of a Posted Journal
 * that also touches a cash-equivalent Account (§22's own §"Derivation
 * rule"), never invented per-transaction-type. See
 * {@see CashFlowStatementQuery}
 * for exactly how each case is chosen.
 */
enum CashFlowActivity
{
    case Operating;
    case Investing;
    case Financing;
}
