<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Transactions\Income\IncomeToPostingCommandTranslator;

/**
 * Translates an about-to-be-Issued Invoice into a `PostingCommand`
 * (Accounting Core, M4) — the *only* bridge between the two (M20),
 * mirroring
 * {@see IncomeToPostingCommandTranslator}'s
 * own reasoning exactly.
 *
 * **The accounting mapping, and why it is fixed.** Issuing an Invoice
 * always produces exactly: a Debit line on the Receivable Account, and
 * a Credit line on the Revenue Account, both for the Invoice's own
 * `totalAmount` — standard accrual-basis revenue recognition at
 * issuance. This class does not generalize to any other Invoicing
 * event (Payment, Credit Note) — each, if ever built, needs its own
 * accounting mapping decided when it is actually built.
 *
 * **Source reference — self-referential, not fabricated.** The
 * resulting Journal's Source reference identifies the Invoice that
 * gave rise to it (`"invoice:{InvoiceId}"`), mirroring Income's own
 * `"income:{IncomeId}"` convention.
 *
 * **No Source Fingerprint.** Per AETS-007 §6.2, an Invoice Issue is
 * authored directly by an Actor confirming its own already-composed
 * Draft, not derived from external/imported source material — the
 * identical reasoning Income's own translator already documents.
 *
 * **Financial date — the Invoice's own issue date, exactly.** The
 * Journal's `financialDate` is always the `$issueDate` passed to
 * {@see InvoiceIssuingService::issue()} — never `created_at` or
 * "today" substituted silently.
 */
final class InvoiceToPostingCommandTranslator
{
    private const SOURCE_REFERENCE_PREFIX = 'invoice:';

    public function translate(
        Invoice $invoice,
        JournalId $journalId,
        IdempotencyKey $idempotencyKey,
        ActorReference $actor,
        \DateTimeImmutable $issueDate,
    ): PostingCommand {
        $lines = [
            JournalLine::create($invoice->receivableAccountId(), $invoice->totalAmount(), JournalDirection::Debit),
            JournalLine::create($invoice->revenueAccountId(), $invoice->totalAmount(), JournalDirection::Credit),
        ];

        return new PostingCommand(
            $idempotencyKey,
            $invoice->tenantId(),
            $actor,
            SourceReference::of(self::SOURCE_REFERENCE_PREFIX.$invoice->id()->toString()),
            $journalId,
            $lines,
            $issueDate,
            null,
            [],
        );
    }
}
