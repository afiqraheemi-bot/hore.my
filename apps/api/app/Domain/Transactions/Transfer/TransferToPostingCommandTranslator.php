<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Transfer;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Transactions\Income\IncomeToPostingCommandTranslator;

/**
 * Translates a `RecordTransferCommand` (Transactions domain) into a
 * `PostingCommand` (Accounting Core, M4) — the *only* bridge between
 * the two (M14). `PostingCommand`'s own shape is never changed to
 * accommodate Transfer; this class produces exactly the two-line,
 * double-entry candidate AETS-004/AETS-007 already require of any
 * Posting Command.
 *
 * **The accounting mapping, and why it is fixed.** A Transfer always
 * produces exactly: a Debit line on the Destination Account, and a
 * Credit line on the Source Account, both for the exact same `Money`
 * amount — value leaves the Source Account and arrives at the
 * Destination Account, the standard double-entry treatment for moving
 * funds between two accounts the business itself controls. This class
 * does not generalize to any other transaction type — mirrors
 * {@see IncomeToPostingCommandTranslator}'s
 * own disclaimer exactly.
 *
 * **Source Fingerprint — never supplied**, for the identical reason
 * {@see IncomeToPostingCommandTranslator}
 * never supplies one.
 *
 * **Source reference — self-referential, not fabricated.** The
 * resulting Journal's Source reference identifies the Transfer record
 * that gave rise to it (`"transfer:{TransferId}"`).
 *
 * **Financial date — Transfer's own `transaction_date`, exactly (M8).**
 */
final class TransferToPostingCommandTranslator
{
    private const SOURCE_REFERENCE_PREFIX = 'transfer:';

    public function translate(RecordTransferCommand $command): PostingCommand
    {
        $lines = [
            JournalLine::create($command->destinationAccountId(), $command->amount(), JournalDirection::Debit),
            JournalLine::create($command->sourceAccountId(), $command->amount(), JournalDirection::Credit),
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

    private function sourceReferenceFor(RecordTransferCommand $command): SourceReference
    {
        return SourceReference::of(self::SOURCE_REFERENCE_PREFIX.$command->transferId()->toString());
    }
}
