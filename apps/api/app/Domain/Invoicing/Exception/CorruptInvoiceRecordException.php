<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exception;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Money;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceIssuingService;
use App\Domain\Invoicing\InvoiceStatus;
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

    /**
     * Thrown when a persisted `invoices.status` value is neither
     * `Draft` nor `Issued` — the only two values
     * {@see InvoiceStatus} and the table's own
     * `CHECK` constraint permit. Reaching this means the row was
     * written outside `InvoiceRepository`'s own insert/update paths
     * (a manual `UPDATE`, a bypassed migration, or genuine data
     * corruption). Silently reinterpreting an unrecognized value as
     * `Draft` would let a corrupted, possibly-already-Issued row become
     * editable/deletable again through the ordinary Draft-invoice
     * endpoints — this fails loudly instead.
     */
    public static function forUnrecognizedStatus(InvoiceId $invoiceId, string $status): self
    {
        return new self(sprintf(
            'Invoice "%s" has an unrecognized status "%s" — expected "Draft" or "Issued".',
            $invoiceId->toString(),
            $status,
        ));
    }

    /**
     * Thrown when reconstituting an Invoice (P0/P1 audit remediation,
     * 2026-09-11) and one persisted line's own `lineAmount` no longer
     * equals `unitPrice × quantity` — {@see Invoice::draft()} and
     * {@see Invoice::update()} always compute `lineAmount` this way, so
     * this can only mean the row was written outside those two paths
     * (a manual `UPDATE`, a defective import, or a migration bug).
     * Silently trusting a mismatched `lineAmount` would let a
     * corrupted line's amount flow straight into every report and,
     * were it later Issued, into the Journal Accounting Core posts —
     * this fails loudly instead, before any of that can happen.
     */
    public static function forLineAmountMismatch(InvoiceId $invoiceId, int $lineIndex, Money $expected, Money $actual): self
    {
        return new self(sprintf(
            'Invoice "%s" line %d has lineAmount "%s" but quantity × unitPrice computes to "%s" — this line is corrupt.',
            $invoiceId->toString(),
            $lineIndex,
            $actual->toDecimalString(),
            $expected->toDecimalString(),
        ));
    }

    /**
     * Thrown when reconstituting an Invoice and its persisted
     * `totalAmount` no longer equals the sum of its own lines'
     * `lineAmount`s — {@see Invoice::draft()} and
     * {@see Invoice::update()} always compute `totalAmount` this way
     * (via the same `sumLines()`), so this can only mean the row was
     * written outside those two paths. Fails loudly for the identical
     * reason {@see forLineAmountMismatch()} does.
     */
    public static function forTotalAmountMismatch(InvoiceId $invoiceId, Money $expected, Money $actual): self
    {
        return new self(sprintf(
            'Invoice "%s" has totalAmount "%s" but its own lines sum to "%s" — this Invoice is corrupt.',
            $invoiceId->toString(),
            $actual->toDecimalString(),
            $expected->toDecimalString(),
        ));
    }
}
