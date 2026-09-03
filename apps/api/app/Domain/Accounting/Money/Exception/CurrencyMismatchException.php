<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Money\Exception;

use App\Domain\Accounting\Money\Currency;

/**
 * Thrown when an operation requiring a common Currency is attempted
 * between Money values of different Currency (AETS-003 §6, §11, §12;
 * ATS-003 MON-006).
 */
final class CurrencyMismatchException extends \InvalidArgumentException
{
    public static function forCurrencies(Currency $expected, Currency $actual): self
    {
        return new self(sprintf(
            'Currency mismatch: expected "%s", got "%s".',
            $expected->identifier(),
            $actual->identifier(),
        ));
    }
}
