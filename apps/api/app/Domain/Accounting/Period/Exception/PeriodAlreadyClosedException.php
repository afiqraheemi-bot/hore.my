<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Period\Exception;

use App\Domain\Accounting\Period\PeriodClosingService;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * Thrown when a `PeriodClosingCommand`'s `closedThroughDate` is not
 * strictly after the Tenant's current closed-period watermark — either
 * it re-closes an already-closed date, or it attempts to close
 * "backwards" to a date before one already closed. Closing a Period is
 * a one-way, ever-advancing commitment (AETS-014); reopening one is a
 * deliberately separate, deferred operation (§2.2), never implied by
 * this exception.
 *
 * Distinct from a same-key, same-date replay: {@see PeriodClosingService}
 * checks the Idempotency Key first, so this is only ever thrown for a
 * genuinely new attempt to close an already-settled date.
 */
final class PeriodAlreadyClosedException extends \RuntimeException
{
    public static function forDateNotAfterWatermark(TenantId $tenantId, \DateTimeImmutable $requestedDate, \DateTimeImmutable $currentWatermark): self
    {
        return new self(sprintf(
            'Tenant "%s" cannot close through "%s" — the books are already closed through "%s", '
            .'and a Period can only be closed forward, never re-closed or closed backwards.',
            $tenantId->toString(),
            $requestedDate->format('Y-m-d'),
            $currentWatermark->format('Y-m-d'),
        ));
    }
}
