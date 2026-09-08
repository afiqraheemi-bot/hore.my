<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Domain\Workspace\Exception\InvalidProposalIdException;
use App\Domain\Workspace\Exception\TaskAlreadyTransitionedException;

/**
 * A Proposal's stable, opaque identifier (ADR-0009, WTS-001 §5) —
 * assigned at creation and immutable for its lifetime. Mirrors
 * {@see TaskId} exactly.
 *
 * Reused, per TSK-004, as the deterministic seed for the resulting
 * Accounting Command's identifiers and Idempotency Key once a Proposal
 * is approved — see {@see TaskService} — so that
 * Accounting Core's own existing idempotency protection ([AETS-007])
 * covers a re-executed Approved transition for free, in addition to
 * this module's own {@see TaskAlreadyTransitionedException}
 * compare-and-swap guard.
 */
final class ProposalId
{
    private const MAX_LENGTH = 64;

    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidProposalIdException if the value is not a
     *                                    canonical Proposal identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidProposalIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidProposalIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidProposalIdException::forValue($value);
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
