<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Money\Exception;

/**
 * Thrown when a decimal string is not a canonical, non-negative decimal
 * representation at the target Currency's scale (AETS-003 §9; ATS-003
 * MON-T022–MON-T033).
 */
final class InvalidMoneyAmountException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a canonical decimal string at the target currency\'s scale.',
            $value,
        ));
    }
}
