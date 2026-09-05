<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal\Exception;

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalId;

/**
 * Thrown when {@see Journal::createReplacement()} is given a Journal
 * that is not a legitimate Replacement target (AETS-004 §17; M5
 * architecture decision):
 *
 * - the referenced Journal is not a Reversal (a Replacement MUST
 *   reference the correction chain's Reversal — not the Original
 *   directly, and not another Replacement); or
 * - the referenced Reversal is not currently Posted (it has not
 *   itself neutralized anything yet).
 */
final class InvalidReplacementTargetException extends \LogicException
{
    public static function forNotAReversal(JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" cannot be referenced by a Replacement because it is not a Reversal.',
            $journalId->toString(),
        ));
    }

    public static function forReversalNotPosted(JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" cannot be referenced by a Replacement because it is not Posted.',
            $journalId->toString(),
        ));
    }
}
