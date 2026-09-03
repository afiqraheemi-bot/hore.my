<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Money\Exception;

/**
 * Thrown when the underlying arithmetic engine reports a failure this
 * wrapper did not already anticipate with a more specific hore.my
 * exception (e.g. {@see CurrencyMismatchException}).
 *
 * Deliberately does not chain the originating vendor exception as its
 * "previous" exception, and never includes a vendor class name in its
 * message — a caller inspecting this exception by any public means
 * (message, `getPrevious()`, or otherwise) must never observe the
 * underlying arithmetic library's own exception type (AETS-003 §16).
 */
final class MoneyArithmeticException extends \RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(sprintf('Money operation "%s" failed unexpectedly.', $operation));
    }
}
