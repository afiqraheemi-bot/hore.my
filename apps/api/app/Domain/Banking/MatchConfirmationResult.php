<?php

declare(strict_types=1);

namespace App\Domain\Banking;

/**
 * The deterministic terminal result of {@see MatchingService::confirm()}
 * (AETS-008 §12.6, `BNK-018`) — either this specific confirmation was
 * newly recorded, or it exactly replays a previous confirmation of the
 * same (BankTransaction, Journal) pair, returning that original
 * {@see BankTransactionMatch} instead of recording a second one.
 * Mirrors {@see BankStatementImportResult}'s own identical reasoning.
 */
final class MatchConfirmationResult
{
    private function __construct(
        private readonly bool $isNewMatch,
        private readonly BankTransactionMatch $match,
    ) {}

    public static function newlyConfirmed(BankTransactionMatch $match): self
    {
        return new self(true, $match);
    }

    public static function replayed(BankTransactionMatch $match): self
    {
        return new self(false, $match);
    }

    public function isNewMatch(): bool
    {
        return $this->isNewMatch;
    }

    public function isReplay(): bool
    {
        return ! $this->isNewMatch;
    }

    public function match(): BankTransactionMatch
    {
        return $this->match;
    }
}
