<?php

declare(strict_types=1);

namespace App\Domain\MyInvois\Exception;

/**
 * Thrown when a `MyInvoisLineDetail` is constructed with an invalid
 * classification code, unit of measure, or tax rate (AETS-013 v0.1.0
 * §6).
 */
final class InvalidMyInvoisLineDetailException extends \InvalidArgumentException
{
    public static function forEmptyClassificationCode(): self
    {
        return new self('A MyInvois line classification code must not be empty.');
    }

    public static function forEmptyUnitOfMeasure(): self
    {
        return new self('A MyInvois line unit of measure must not be empty.');
    }

    public static function forInvalidTaxRate(string $taxRate): self
    {
        return new self(sprintf('Tax rate "%s" is not a non-negative decimal percentage.', $taxRate));
    }
}
