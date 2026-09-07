<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exception;

use App\Domain\Payments\PaymentAllocationId;

final class PaymentAllocationNotFoundException extends \RuntimeException
{
    public static function forId(PaymentAllocationId $allocationId): self
    {
        return new self(sprintf('PaymentAllocation "%s" was not found.', $allocationId->toString()));
    }
}
