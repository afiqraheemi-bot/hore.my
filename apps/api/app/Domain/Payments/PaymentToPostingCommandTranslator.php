<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Transactions\Income\IncomeToPostingCommandTranslator;

/**
 * Translates a `RecordPaymentCommand` into a `PostingCommand` (M21) —
 * mirrors
 * {@see IncomeToPostingCommandTranslator}'s
 * own reasoning exactly.
 *
 * **The accounting mapping, and why it is fixed.** Recording a Payment
 * always produces exactly: a Debit line on the deposit Account, and a
 * Credit line on the Receivable Account, both for the Payment's own
 * amount — cash received reduces what the Customer owes. This class
 * does not itself know or care which Invoice(s) that Payment will be
 * allocated to ({@see PaymentAllocation}'s own separate concern).
 */
final class PaymentToPostingCommandTranslator
{
    private const SOURCE_REFERENCE_PREFIX = 'payment:';

    public function translate(RecordPaymentCommand $command): PostingCommand
    {
        $lines = [
            JournalLine::create($command->depositAccountId(), $command->amount(), JournalDirection::Debit),
            JournalLine::create($command->receivableAccountId(), $command->amount(), JournalDirection::Credit),
        ];

        return new PostingCommand(
            $command->idempotencyKey(),
            $command->tenantId(),
            $command->actor(),
            SourceReference::of(self::SOURCE_REFERENCE_PREFIX.$command->paymentId()->toString()),
            $command->journalId(),
            $lines,
            $command->paymentDate(),
            null,
            [],
        );
    }
}
