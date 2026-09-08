<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Exception;

/**
 * Thrown when a value is not a canonical Proposal identifier
 * (ADR-0009, WTS-001 §5).
 */
final class InvalidProposalIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Value "%s" is not a canonical Proposal identifier.', $value));
    }
}
