<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal\Exception;

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalId;

/**
 * Thrown when {@see Journal::reverse()} is called on a Journal that is
 * not a legitimate Reversal target (AETS-004 §16; M5 architecture
 * decision):
 *
 * - the Journal is not currently Posted (a Draft Journal has no
 *   ledger effect yet — AETS-004 §9 — so there is nothing to
 *   neutralize); or
 * - the Journal is itself already a correction (a Reversal or a
 *   Replacement) — reversing a correction is not a case AETS-004 §16
 *   defines, and is rejected outright rather than silently allowed.
 */
final class InvalidReversalTargetException extends \LogicException
{
    public static function forNotPosted(JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" cannot be reversed because it is not Posted.',
            $journalId->toString(),
        ));
    }

    public static function forAlreadyACorrection(JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" cannot be reversed because it is itself a correction Journal.',
            $journalId->toString(),
        ));
    }
}
