<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Period\Exception;

use App\Domain\Shared\Tenancy\TenantId;

/**
 * Thrown when a `PeriodClosingCommand` would produce a closing Journal
 * with fewer than two lines — every Revenue and Expense Account has a
 * zero balance for the period being closed, so there is nothing to
 * roll into Retained Earnings.
 *
 * Accounting Core never fabricates a Journal Line to satisfy the
 * "at least two lines" invariant (AETS-002) — mirroring the same
 * "never fabricate financial truth" principle the Financial Date
 * migration hardening (M8A) already established for a different case.
 * A Tenant with genuinely no Revenue/Expense activity for a period has
 * nothing to close; the Period's watermark simply does not advance.
 */
final class NothingToCloseException extends \RuntimeException
{
    public static function forTenantAndDate(TenantId $tenantId, \DateTimeImmutable $closedThroughDate): self
    {
        return new self(sprintf(
            'Tenant "%s" has no Revenue or Expense activity to close through "%s" — every such Account '
            .'has a zero balance for this period, so no closing Journal is produced.',
            $tenantId->toString(),
            $closedThroughDate->format('Y-m-d'),
        ));
    }
}
