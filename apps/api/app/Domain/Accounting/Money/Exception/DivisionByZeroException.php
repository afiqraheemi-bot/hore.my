<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Money\Exception;

/**
 * Thrown when a Money division operation's divisor is zero
 * (AETS-003 §10, §17).
 *
 * Extends InvalidArgumentException, not PHP's native
 * \DivisionByZeroException (which is an \Error, not an \Exception) —
 * a zero divisor is an expected, recoverable domain-input failure
 * here, not an unrecoverable programming error, consistent with every
 * other Money exception remaining catchable as \Exception.
 */
final class DivisionByZeroException extends \InvalidArgumentException
{
    public static function forDivisor(): self
    {
        return new self('Cannot divide Money by zero.');
    }
}
