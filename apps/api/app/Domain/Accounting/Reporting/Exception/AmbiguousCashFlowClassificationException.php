<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reporting\Exception;

use App\Infrastructure\Accounting\Reporting\CashFlowStatementQuery;

/**
 * Thrown when a single Posted Journal that touches a cash-equivalent
 * Account has counterparty lines spanning more than one Account Type
 * (AETS-009 §22) — every Posting Command this codebase currently
 * translates produces a simple two-line Journal (one cash-equivalent
 * line, one counterparty line of a single Account Type), so this
 * exception is not expected to fire against any Journal a real
 * command in this codebase can produce today.
 *
 * **Fails closed, never guesses.** A future command type that posts a
 * genuinely mixed-Account-Type Journal touching cash (for example, one
 * Journal splitting a single cash payment across both an Expense line
 * and a Liability line) needs an explicit classification rule added to
 * {@see CashFlowStatementQuery}
 * before it can be reported correctly — silently picking one Account
 * Type over another would misclassify real cash movements between
 * Operating, Investing, and Financing without any visible error.
 */
final class AmbiguousCashFlowClassificationException extends \RuntimeException
{
    public static function forJournal(string $journalId): self
    {
        return new self(sprintf(
            'Journal %s touches a cash-equivalent Account and counterparty lines spanning more than one Account Type — no classification rule exists for this yet.',
            $journalId,
        ));
    }
}
