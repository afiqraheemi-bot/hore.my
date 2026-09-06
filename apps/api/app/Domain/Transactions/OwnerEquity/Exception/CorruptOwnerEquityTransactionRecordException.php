<?php

declare(strict_types=1);

namespace App\Domain\Transactions\OwnerEquity\Exception;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\Exception\CorruptPostingIdempotencyMappingException;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionId;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionRecordingService;

/**
 * Thrown when `PostingCommandTransactionalExecutor::execute()` reports
 * an idempotent replay for an Owner Equity Transaction's underlying
 * Journal, but no matching record can be found (M15).
 *
 * **This is not "first submission" wearing a different name.** An
 * Owner Equity Transaction and its Journal are always inserted
 * together, in the same atomic transaction
 * ({@see OwnerEquityTransactionRecordingService}), so a replayed
 * Journal with no corresponding record is an impossible state under
 * normal operation — mirroring
 * {@see CorruptPostingIdempotencyMappingException}'s own reasoning
 * exactly. If this exception is ever observed, something has bypassed
 * `OwnerEquityTransactionRecordingService` or corrupted data outside of
 * it; silently treating it as a fresh record would hide that fact and
 * risk recording a second one for a Journal that already has one. This
 * fails loudly instead.
 */
final class CorruptOwnerEquityTransactionRecordException extends \RuntimeException
{
    public static function forUnresolvableTransaction(OwnerEquityTransactionId $id, JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" was reported as an idempotent replay for Owner Equity Transaction "%s", but no matching record could be found.',
            $journalId->toString(),
            $id->toString(),
        ));
    }
}
