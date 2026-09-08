<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Exception;

use App\Domain\Workspace\TaskId;

/**
 * Thrown when a Task identifier does not resolve to an existing
 * record for the given Tenant (ADR-0009).
 */
final class TaskNotFoundException extends \RuntimeException
{
    public static function forId(TaskId $id): self
    {
        return new self(sprintf('Task "%s" was not found.', $id->toString()));
    }
}
