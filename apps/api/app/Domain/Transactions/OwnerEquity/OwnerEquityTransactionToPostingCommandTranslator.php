<?php

declare(strict_types=1);

namespace App\Domain\Transactions\OwnerEquity;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Transactions\Income\IncomeToPostingCommandTranslator;

/**
 * Translates a `RecordOwnerEquityTransactionCommand` (Transactions
 * domain) into a `PostingCommand` (Accounting Core, M4) — the *only*
 * bridge between the two (M15). `PostingCommand`'s own shape is never
 * changed to accommodate this; this class produces exactly the
 * two-line, double-entry candidate AETS-004/AETS-007 already require of
 * any Posting Command.
 *
 * **The accounting mapping, and why it is fixed.** This is the one
 * place {@see OwnerEquityMovementType} matters:
 *
 * - Contribution: Debit the Cash Account, Credit the Equity Account —
 *   the owner's personal funds increase business Cash (Asset, Debit
 *   normal) and increase the owner's stake in the business (Equity,
 *   Credit normal).
 * - Drawing: Debit the Equity Account, Credit the Cash Account — the
 *   owner's stake decreases (a Debit against a Credit-normal Equity
 *   Account) as business Cash decreases (a Credit against a
 *   Debit-normal Asset Account).
 *
 * Both are the reverse of each other by construction, never derived
 * from a Money sign or any other implicit signal.
 *
 * **Source Fingerprint — never supplied**, for the identical reason
 * {@see IncomeToPostingCommandTranslator}
 * never supplies one.
 *
 * **Source reference — self-referential, not fabricated.** The
 * resulting Journal's Source reference identifies the Owner Equity
 * Transaction record that gave rise to it
 * (`"owner-equity:{OwnerEquityTransactionId}"`), regardless of movement
 * type — the record itself, not the Journal, is where the movement
 * type is human-readable.
 *
 * **Financial date — the command's own `transaction_date`, exactly
 * (M8).**
 */
final class OwnerEquityTransactionToPostingCommandTranslator
{
    private const SOURCE_REFERENCE_PREFIX = 'owner-equity:';

    public function translate(RecordOwnerEquityTransactionCommand $command): PostingCommand
    {
        $lines = match ($command->movementType()) {
            OwnerEquityMovementType::Contribution => [
                JournalLine::create($command->cashAccountId(), $command->amount(), JournalDirection::Debit),
                JournalLine::create($command->equityAccountId(), $command->amount(), JournalDirection::Credit),
            ],
            OwnerEquityMovementType::Drawing => [
                JournalLine::create($command->equityAccountId(), $command->amount(), JournalDirection::Debit),
                JournalLine::create($command->cashAccountId(), $command->amount(), JournalDirection::Credit),
            ],
        };

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

    private function sourceReferenceFor(RecordOwnerEquityTransactionCommand $command): SourceReference
    {
        return SourceReference::of(self::SOURCE_REFERENCE_PREFIX.$command->transactionId()->toString());
    }
}
