<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal\Exception;

use App\Domain\Accounting\Journal\Journal;

/**
 * Thrown when {@see Journal::post()}
 * is called on a Journal that is already Posted (AETS-004 §9,
 * `JRN-T024`) — the typed "already posted" failure AETS-004 requires:
 * a re-posting attempt is rejected outright, never silently re-posted
 * and never silently accepted as a no-op. A Posted Journal is
 * terminal — it MUST NOT transition back to Draft, or to Posted
 * again, or to any other state.
 */
final class JournalAlreadyPostedException extends \LogicException
{
    public static function forJournal(): self
    {
        return new self('This Journal is already Posted and cannot be posted again.');
    }
}
