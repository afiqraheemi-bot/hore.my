<?php

declare(strict_types=1);

namespace App\Domain\ProofOfAccuracy;

/**
 * The outcome tally of one certification test run (AETS-012 §6.8,
 * §8, `POA-010`). Skipped, incomplete, and risky tests count as
 * failures here, not as a separate lesser category — AETS-012 §6.8
 * is explicit that a skipped required test fails the whole gate.
 */
final class TestRunCounts
{
    public function __construct(
        private readonly int $passed,
        private readonly int $failed,
        private readonly int $errors,
        private readonly int $skipped,
        private readonly int $incomplete,
        private readonly int $risky,
    ) {}

    public function passed(): int
    {
        return $this->passed;
    }

    public function failed(): int
    {
        return $this->failed;
    }

    public function errors(): int
    {
        return $this->errors;
    }

    public function skipped(): int
    {
        return $this->skipped;
    }

    public function incomplete(): int
    {
        return $this->incomplete;
    }

    public function risky(): int
    {
        return $this->risky;
    }

    public function total(): int
    {
        return $this->passed + $this->failed + $this->errors + $this->skipped + $this->incomplete + $this->risky;
    }

    /**
     * True if anything at all is not a clean pass (`POA-010`) — a
     * failure, an error, a skip, an incomplete, or a risky result.
     */
    public function hasAnyFailure(): bool
    {
        return $this->failed > 0 || $this->errors > 0 || $this->skipped > 0 || $this->incomplete > 0 || $this->risky > 0;
    }
}
