<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Expense;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\SourceReference;

/**
 * Translates a `RecordExpenseCommand` (Transactions domain) into a
 * `PostingCommand` (Accounting Core, M4) — the *only* bridge between
 * the two (M7). `PostingCommand`'s own shape is never changed to
 * accommodate Expense; this class produces exactly the two-line,
 * double-entry candidate AETS-004/AETS-007 already require of any
 * Posting Command.
 *
 * **The accounting mapping, and why it is fixed.** An Expense always
 * produces exactly: a Debit line on the Expense Account, and a Credit
 * line on the Payment Account, both for the exact same `Money` amount
 * — standard double-entry treatment for "money left the business for
 * an expense," and the only mapping SRS TRX-001/LED-009 requires this
 * milestone to support. This class does not generalize to any other
 * transaction type (income, transfer, capital, drawings, asset,
 * refund, personal) — each, if ever built, needs its own accounting
 * mapping decided when it is actually built, not guessed here.
 *
 * **Source Fingerprint — never supplied.** Per AETS-007 §6.2, this
 * class never passes a Source Fingerprint: a manually-authored Expense
 * command, even one carrying an Evidence reference, is not "materially
 * derived from external or imported source data" requiring
 * duplicate-source detection — no real file-upload/import pipeline
 * exists yet in this codebase to define what a duplicate would even
 * mean (see the M7 closure report for the full policy reasoning). This
 * class MUST NOT be changed to fabricate one merely to satisfy §6.2's
 * literal wording (`POST-027`).
 *
 * **Source reference — self-referential, not fabricated.** The
 * resulting Journal's Source reference identifies the Expense record
 * that gave rise to it (`"expense:{ExpenseId}"`) — a true, traceable
 * fact (AETS-004 §19), never an invented claim about external material.
 *
 * **Financial date — Expense's own `transaction_date`, exactly (M8).**
 * The Journal's `financialDate` is always `$command->transactionDate()`
 * — Expense's own business-facing accounting date is the ledger's
 * authoritative financial date for this Journal; this class never
 * substitutes `created_at`, "today", or any other value.
 */
final class ExpenseToPostingCommandTranslator
{
    private const SOURCE_REFERENCE_PREFIX = 'expense:';

    public function translate(RecordExpenseCommand $command): PostingCommand
    {
        $lines = [
            JournalLine::create($command->expenseAccountId(), $command->amount(), JournalDirection::Debit),
            JournalLine::create($command->paymentAccountId(), $command->amount(), JournalDirection::Credit),
        ];

        $evidenceReference = $command->evidenceReference();

        return new PostingCommand(
            $command->idempotencyKey(),
            $command->tenantId(),
            $command->actor(),
            $this->sourceReferenceFor($command),
            $command->journalId(),
            $lines,
            $command->transactionDate(),
            null,
            $evidenceReference === null ? [] : [$evidenceReference->toString()],
        );
    }

    private function sourceReferenceFor(RecordExpenseCommand $command): SourceReference
    {
        return SourceReference::of(self::SOURCE_REFERENCE_PREFIX.$command->expenseId()->toString());
    }
}
