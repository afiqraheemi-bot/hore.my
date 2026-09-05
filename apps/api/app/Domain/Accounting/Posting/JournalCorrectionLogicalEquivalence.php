<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\Journal;

/**
 * The pure, storage-free rule that determines whether two Journal
 * Correction candidates represent the same logical correction request
 * or a materially different one (M5 architecture decision — gap A),
 * mirroring {@see PostingCommandLogicalEquivalence}'s own role for
 * ordinary `PostingCommand`s.
 *
 * **Compares assembled Journals, not raw commands.** A Reversal's
 * lines are never caller-supplied — they are mechanically derived from
 * whatever Original the request references (`Journal::reverse()`), so
 * the only way to know what a Reversal request's lines actually are is
 * to assemble the candidate first. Comparing two already-assembled
 * `Journal` candidates therefore needs no branching between Reversal
 * and Replacement: both are compared identically, on the same four
 * facts — TenantId, the new Journal's own identity, CorrectionType,
 * and the correction-chain reference (`correctedJournalId`) — plus the
 * full Journal Line set (Account, Money, Currency, Direction, in
 * order — reusing `JournalLine`'s own value-equality contract exactly
 * as {@see PostingCommandLogicalEquivalence} already does).
 *
 * **What this does not do.** It does not look up or reserve an
 * Idempotency Key, does not decide what happens when two candidates
 * are or are not equivalent, and does not itself detect a replay —
 * {@see JournalCorrectionIdempotencyResolver} owns all of that. It also
 * does not call {@see Journal::equals()}, which is deliberately
 * identity-only (JournalId alone) — a materially different correction
 * request submitted under the same new JournalId is exactly the
 * conflict this class exists to catch, and `equals()` would miss it
 * entirely.
 */
final class JournalCorrectionLogicalEquivalence
{
    public function equivalent(Journal $left, Journal $right): bool
    {
        if (! $left->tenantId()->equals($right->tenantId())) {
            return false;
        }

        if (! $left->id()->equals($right->id())) {
            return false;
        }

        if ($left->correctionType() !== $right->correctionType()) {
            return false;
        }

        $leftCorrectedJournalId = $left->correctedJournalId();
        $rightCorrectedJournalId = $right->correctedJournalId();

        if (($leftCorrectedJournalId === null) !== ($rightCorrectedJournalId === null)) {
            return false;
        }

        if ($leftCorrectedJournalId !== null && $rightCorrectedJournalId !== null
            && ! $leftCorrectedJournalId->equals($rightCorrectedJournalId)) {
            return false;
        }

        $leftLines = $left->lines();
        $rightLines = $right->lines();

        if (count($leftLines) !== count($rightLines)) {
            return false;
        }

        foreach ($leftLines as $index => $leftLine) {
            if (! $leftLine->equals($rightLines[$index])) {
                return false;
            }
        }

        return true;
    }
}
