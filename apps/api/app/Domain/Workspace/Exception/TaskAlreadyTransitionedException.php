<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Exception;

use App\Domain\Workspace\TaskId;
use App\Infrastructure\Workspace\TaskRepository;

/**
 * Thrown when an `Approved`-transition attempt loses a concurrency
 * race (WTS-001 §6, TSK-004): the atomic `UPDATE ... WHERE state =
 * 'NeedsReview'` compare-and-swap in
 * {@see TaskRepository::transitionIfInState()}
 * affected zero rows, meaning another concurrent request already moved
 * this Task out of `NeedsReview` first. Mirrors this codebase's own
 * established concurrency-safety pattern (the P1-3 PaymentAllocation
 * soft-delete race fix, and P0-1's concurrent-issue guard) —
 * PostgreSQL's own MVCC serializes the two concurrent `UPDATE`s to the
 * same row, and only one caller ever observes an affected-row count of
 * 1.
 */
final class TaskAlreadyTransitionedException extends \RuntimeException
{
    public static function forId(TaskId $id): self
    {
        return new self(sprintf(
            'Task "%s" was already transitioned by a concurrent request.',
            $id->toString(),
        ));
    }
}
