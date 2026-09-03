<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Money\Exception;

/**
 * Thrown when a Money operation's exact result cannot be represented
 * at the applicable Currency's scale and the supplied RoundingMode
 * does not permit rounding (AETS-003 §13, §17).
 */
final class RoundingRequiredException extends \InvalidArgumentException
{
    public static function forOperation(string $operation): self
    {
        return new self(sprintf(
            'Money operation "%s" requires rounding, which the supplied RoundingMode does not permit.',
            $operation,
        ));
    }
}
