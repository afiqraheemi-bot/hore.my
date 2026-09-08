<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Domain\Banking\ReconciliationState;

/**
 * A Task's lifecycle state (WTS-001 §3): `Received` -> `Processing` ->
 * (`NeedsInformation` <-> `Processing`) -> `NeedsReview` -> `Approved`
 * -> `Executing` -> `Completed`, with the terminal branches `Rejected`,
 * `Failed`, `Cancelled`, and `Superseded`. See WTS-001 §4 for the exact
 * allowed-transition table {@see Task} enforces — this enum carries no
 * transition logic of its own, mirroring
 * {@see ReconciliationState}'s own convention.
 */
enum TaskState
{
    case Received;
    case Processing;
    case NeedsInformation;
    case NeedsReview;
    case Approved;
    case Executing;
    case Completed;
    case Rejected;
    case Failed;
    case Cancelled;
    case Superseded;

    /**
     * WTS-001 §6, TSK-009: `Cancelled`, `Rejected`, `Failed`, and
     * `Superseded` are terminal — no transition leaves any of these
     * states.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Rejected, self::Failed, self::Superseded, self::Completed => true,
            default => false,
        };
    }
}
