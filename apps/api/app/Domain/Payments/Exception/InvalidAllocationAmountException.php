<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exception;

final class InvalidAllocationAmountException extends \InvalidArgumentException
{
    public static function forNonPositiveAmount(): self
    {
        return new self('A PaymentAllocation amount must be greater than zero.');
    }
}
