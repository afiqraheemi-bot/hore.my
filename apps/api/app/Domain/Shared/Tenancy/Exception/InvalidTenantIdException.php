<?php

declare(strict_types=1);

namespace App\Domain\Shared\Tenancy\Exception;

use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountIdException;

/**
 * Thrown when a value is not a canonical Tenant identifier.
 *
 * The identifier's concrete representation (surrogate key, UUID, or
 * otherwise) is deliberately not decided by this domain — this
 * exception covers only the minimum safe rejections that hold
 * regardless of that representation: empty, whitespace-only,
 * leading/trailing whitespace, a control character, or an
 * adversarially long value. Mirrors
 * {@see InvalidAccountIdException}'s
 * scope and reasoning for the same class of concern.
 */
final class InvalidTenantIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a canonical Tenant identifier.',
            $value,
        ));
    }
}
