<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Transfer\Exception;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\Exception\CorruptPostingIdempotencyMappingException;
use App\Domain\Transactions\Transfer\TransferId;
use App\Domain\Transactions\Transfer\TransferRecordingService;

/**
 * Thrown when `PostingCommandTransactionalExecutor::execute()` reports
 * an idempotent replay for a Transfer's underlying Journal, but no
 * matching Transfer record can be found (M14).
 *
 * **This is not "first submission" wearing a different name.** A
 * Transfer and its Journal are always inserted together, in the same
 * atomic transaction ({@see TransferRecordingService}), so a replayed
 * Journal with no corresponding Transfer row is an impossible state
 * under normal operation — mirroring
 * {@see CorruptPostingIdempotencyMappingException}'s own reasoning
 * exactly. If this exception is ever observed, something has bypassed
 * `TransferRecordingService` (for example, a caller that posted a
 * Journal directly) or corrupted data outside of it; silently treating
 * it as a fresh Transfer would hide that fact and risk recording a
 * second Transfer for a Journal that already has one. This fails loudly
 * instead.
 */
final class CorruptTransferRecordException extends \RuntimeException
{
    public static function forUnresolvableTransfer(TransferId $transferId, JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" was reported as an idempotent replay for Transfer "%s", but no matching Transfer record could be found.',
            $journalId->toString(),
            $transferId->toString(),
        ));
    }
}
