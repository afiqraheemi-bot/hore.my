<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exception;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceIssuingService;
use App\Domain\Transactions\Income\Exception\CorruptIncomeRecordException;

/**
 * Thrown when `PostingCommandTransactionalExecutor::execute()` reports
 * an idempotent replay for an Invoice's underlying Journal, but the
 * Invoice itself is still `Draft` (M20).
 *
 * **This is not "first submission" wearing a different name.** An
 * Invoice's Journal is only ever posted from inside
 * {@see InvoiceIssuingService::issue()}, which
 * marks the Invoice `Issued` in the same atomic transaction as posting
 * its Journal — mirroring
 * {@see CorruptIncomeRecordException}'s
 * own reasoning exactly. A replayed Journal against a still-Draft
 * Invoice is an impossible state under normal operation; silently
 * treating it as a fresh Issue would risk posting a second Journal
 * against an Invoice that already has one. This fails loudly instead.
 */
final class CorruptInvoiceRecordException extends \RuntimeException
{
    public static function forDraftInvoiceWithReplayedJournal(InvoiceId $invoiceId, JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" was reported as an idempotent replay for Invoice "%s", but that Invoice is still Draft.',
            $journalId->toString(),
            $invoiceId->toString(),
        ));
    }
}
