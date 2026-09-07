<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exception;

/**
 * Thrown when an Invoice is given more lines than the defensive upper
 * bound (M20) — a basic defense against an adversarially large
 * request, mirroring the bounded-length checks already established
 * throughout this codebase (e.g. `Money`'s own scalar-length bound).
 */
final class TooManyInvoiceLinesException extends \InvalidArgumentException
{
    public static function forCount(int $count, int $maxCount): self
    {
        return new self(sprintf('An Invoice may have at most %d lines, got %d.', $maxCount, $count));
    }
}
