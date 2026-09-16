<?php

declare(strict_types=1);

namespace App\Domain\ProofOfAccuracy;

/**
 * One exact command and its exit status, as AETS-012 §8 requires a
 * {@see CertificationRecord} to cite ("exact commands/status").
 */
final class ExecutedCommandRecord
{
    public function __construct(
        private readonly string $command,
        private readonly int $exitCode,
    ) {}

    public function command(): string
    {
        return $this->command;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }
}
