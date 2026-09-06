<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Shared\Tenancy\Exception\InvalidTenantIdException;

/**
 * Thrown when a value is not a canonical User identifier.
 *
 * Mirrors {@see InvalidTenantIdException}'s
 * scope and reasoning for the same class of concern.
 */
final class InvalidUserIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a canonical User identifier.',
            $value,
        ));
    }
}
