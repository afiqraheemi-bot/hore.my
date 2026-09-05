<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Exception;

use App\Domain\Accounting\Journal\JournalId;

/**
 * Thrown when a Posting Command referencing an existing Draft Journal
 * supplies a Journal Line list that does not exactly match the
 * persisted Draft's own lines — in content, or in order.
 *
 * This is the Posting-command boundary's own, earlier enforcement of
 * the same settled line-immutability behavior
 * `JournalRepository::save()` already protects at persistence time
 * (M3-T10): the current Journal domain exposes no line-mutation API
 * at all, so no addition, removal, reordering, or per-field change
 * (Account, Money, Currency, Direction) to an existing Draft's lines
 * is ever legitimate. Rejecting here, before persistence is even
 * attempted, does not relax or duplicate that repository-level
 * guarantee — it only surfaces the same fact sooner.
 */
final class RejectedJournalLineMismatchException extends \RuntimeException
{
    public static function forJournalId(JournalId $journalId): self
    {
        return new self(sprintf(
            'The proposed Journal Lines for existing Draft Journal "%s" do not exactly match its persisted lines.',
            $journalId->toString(),
        ));
    }
}
