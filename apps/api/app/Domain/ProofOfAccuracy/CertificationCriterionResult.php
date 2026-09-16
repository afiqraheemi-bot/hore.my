<?php

declare(strict_types=1);

namespace App\Domain\ProofOfAccuracy;

/**
 * One AETS-012 §7 acceptance criterion's (`POA-001`–`POA-012`)
 * outcome within a single certification run — never a numeric score
 * or a partial-credit value, mirroring §7's own "no tolerance band"
 * rule.
 */
final class CertificationCriterionResult
{
    private function __construct(
        private readonly string $criterionId,
        private readonly bool $passed,
        private readonly string $detail,
    ) {}

    public static function passed(string $criterionId, string $detail = ''): self
    {
        return new self($criterionId, true, $detail);
    }

    public static function failed(string $criterionId, string $detail): self
    {
        return new self($criterionId, false, $detail);
    }

    public function criterionId(): string
    {
        return $this->criterionId;
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function detail(): string
    {
        return $this->detail;
    }
}
