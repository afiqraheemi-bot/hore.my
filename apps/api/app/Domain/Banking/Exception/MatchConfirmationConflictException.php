<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

use App\Domain\Banking\BankTransactionId;
use App\Domain\Banking\MatchConfirmationResult;
use App\Domain\Workspace\Exception\TaskSubmissionConflictException;

/**
 * Thrown when a Match-confirmation request loses a genuine race for a
 * BankTransaction that a concurrent winner already confirmed-matched
 * to a *different* Journal (AETS-008 §12.6, `BNK-018`). Mirrors
 * {@see TaskSubmissionConflictException}'s
 * identical reasoning at the Match-confirmation boundary.
 *
 * **Not thrown for a replayed confirmation.** Confirming the same
 * (BankTransaction, Journal) pair a concurrent or later caller already
 * confirmed is a deterministic replay — see
 * {@see MatchConfirmationResult} — never this
 * exception. This is thrown only when the two confirmed Journals
 * genuinely differ.
 */
final class MatchConfirmationConflictException extends \RuntimeException
{
    public static function forBankTransaction(BankTransactionId $bankTransactionId): self
    {
        return new self(sprintf(
            'BankTransaction "%s" was already confirmed-matched to a different Journal by a concurrent request.',
            $bankTransactionId->toString(),
        ));
    }
}
