<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Period\PeriodClosingService;
use App\Domain\Accounting\Posting\Exception\RejectedClosedPeriodPostingException;
use App\Infrastructure\Accounting\Period\PeriodClosureRepository;

/**
 * Rejects a `PostingCommand` whose Financial Date falls on or before
 * the Tenant's current closed-period watermark (AETS-014) — a Period,
 * once closed, accepts no further ordinary postings.
 *
 * **Strictly additive to Account validation**
 * ({@see PostingCommandAccountValidator}), the identical
 * "generic Posting Command concern, not specific to any one Accounting
 * Command" placement that class's own docblock establishes.
 *
 * **Never rejects the Period-closing Journal itself.** The closing
 * Journal is dated exactly at the new watermark being established, and
 * `PeriodClosureRepository::findLatestForTenant()` still returns the
 * *previous* watermark at the point this check runs mid-transaction —
 * the new closure row is written only after the closing Journal is
 * successfully posted (mirroring every other Transactions consumer's
 * own "post first, then record the business-context row" ordering).
 * The new date is always strictly after the old watermark (enforced by
 * {@see PeriodClosingService} before
 * posting is even attempted), so it never collides with its own check.
 */
final class PostingCommandPeriodLockValidator
{
    public function __construct(
        private readonly PeriodClosureRepository $periodClosureRepository,
    ) {}

    /**
     * @throws RejectedClosedPeriodPostingException if the command's
     *                                              Financial Date falls on or before the Tenant's current
     *                                              closed-period watermark.
     */
    public function validate(PostingCommand $command): void
    {
        $watermark = $this->periodClosureRepository->findLatestForTenant($command->tenantId());

        if ($watermark === null) {
            return;
        }

        if ($command->financialDate()->format('Y-m-d') <= $watermark->closedThroughDate()->format('Y-m-d')) {
            throw RejectedClosedPeriodPostingException::forDateWithinClosedPeriod(
                $command->tenantId(),
                $command->financialDate(),
                $watermark->closedThroughDate(),
            );
        }
    }
}
