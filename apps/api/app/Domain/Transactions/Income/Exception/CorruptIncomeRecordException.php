<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Income\Exception;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\Exception\CorruptPostingIdempotencyMappingException;
use App\Domain\Transactions\Income\IncomeId;
use App\Domain\Transactions\Income\IncomeRecordingService;

/**
 * Thrown when `PostingCommandTransactionalExecutor::execute()` reports
 * an idempotent replay for an Income's underlying Journal, but no
 * matching Income record can be found (M9).
 *
 * **This is not "first submission" wearing a different name.** An
 * Income and its Journal are always inserted together, in the same
 * atomic transaction ({@see IncomeRecordingService}),
 * so a replayed Journal with no corresponding Income row is an
 * impossible state under normal operation — mirroring
 * {@see CorruptPostingIdempotencyMappingException}'s
 * own reasoning exactly. If this exception is ever observed, something
 * has bypassed `IncomeRecordingService` (for example, a caller that
 * posted a Journal directly) or corrupted data outside of it; silently
 * treating it as a fresh Income would hide that fact and risk
 * recording a second Income for a Journal that already has one. This
 * fails loudly instead.
 */
final class CorruptIncomeRecordException extends \RuntimeException
{
    public static function forUnresolvableIncome(IncomeId $incomeId, JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" was reported as an idempotent replay for Income "%s", but no matching Income record could be found.',
            $journalId->toString(),
            $incomeId->toString(),
        ));
    }
}
