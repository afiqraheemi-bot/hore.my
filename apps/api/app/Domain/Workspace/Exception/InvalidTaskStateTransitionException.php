<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Exception;

use App\Domain\Banking\Exception\InvalidReconciliationStateTransitionException;
use App\Domain\Workspace\TaskId;
use App\Domain\Workspace\TaskState;

/**
 * Thrown when a Task state transition is attempted from a state that
 * does not permit it (WTS-001 §4, TSK-002) — the exact analogue of
 * {@see InvalidReconciliationStateTransitionException}
 * for this module.
 */
final class InvalidTaskStateTransitionException extends \RuntimeException
{
    public static function forTransition(TaskId $id, TaskState $from, string $attemptedTransition): self
    {
        return new self(sprintf(
            'Task "%s" cannot %s from state %s.',
            $id->toString(),
            $attemptedTransition,
            $from->name,
        ));
    }
}
