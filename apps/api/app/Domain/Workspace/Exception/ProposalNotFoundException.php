<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Exception;

use App\Domain\Workspace\TaskId;

/**
 * Thrown when a Task has no Proposal where one is required (for
 * example, at the `Approved` transition) — WTS-001 §5.
 */
final class ProposalNotFoundException extends \RuntimeException
{
    public static function forTask(TaskId $taskId): self
    {
        return new self(sprintf('Task "%s" has no Proposal.', $taskId->toString()));
    }
}
