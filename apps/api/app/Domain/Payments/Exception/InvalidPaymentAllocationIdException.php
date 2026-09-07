<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exception;

final class InvalidPaymentAllocationIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Value "%s" is not a canonical PaymentAllocation identifier.', $value));
    }
}
