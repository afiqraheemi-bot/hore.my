<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exception;

use App\Domain\Payments\PaymentId;

final class PaymentNotFoundException extends \RuntimeException
{
    public static function forId(PaymentId $paymentId): self
    {
        return new self(sprintf('Payment "%s" was not found.', $paymentId->toString()));
    }
}
