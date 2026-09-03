<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Money\Exception;

/**
 * Thrown when a currency identifier is not a supported canonical
 * currency identifier (AETS-003 §7; ATS-003 MON-T010, MON-T092).
 */
final class InvalidCurrencyException extends \InvalidArgumentException
{
    public static function forIdentifier(string $identifier): self
    {
        return new self(sprintf(
            'Currency identifier "%s" is not a supported canonical currency identifier.',
            $identifier,
        ));
    }
}
