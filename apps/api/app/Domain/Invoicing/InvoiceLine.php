<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Money\RoundingMode;
use App\Domain\Invoicing\Exception\InvalidInvoiceLineException;

/**
 * One line item on an Invoice (M20) — a Value Object, not an entity:
 * it carries no identity of its own beyond its position within its
 * owning Invoice's own `lines()` list.
 *
 * **`quantity` is a positive integer, not a decimal.**
 * {@see Money::multiply()} currently accepts only
 * {@see RoundingMode::Unnecessary} — AETS-003 §13's own rounding
 * policy is still deliberately deferred (see that enum's own
 * docblock). Multiplying `unitPrice` by a whole-number `quantity`
 * never loses precision, so this class deliberately restricts
 * `quantity` to a positive integer for this milestone rather than
 * inventing a rounding policy ahead of that still-open decision.
 * Fractional quantities (hours, weight) are a tracked future gap, not
 * a silent omission.
 */
final class InvoiceLine
{
    private const MAX_DESCRIPTION_LENGTH = 500;

    private function __construct(
        private readonly string $description,
        private readonly int $quantity,
        private readonly Money $unitPrice,
        private readonly Money $lineAmount,
    ) {}

    /**
     * @throws InvalidInvoiceLineException if `$description` is empty
     *                                     or too long, or `$quantity` is not positive.
     */
    public static function of(string $description, int $quantity, Money $unitPrice): self
    {
        if ($description === '') {
            throw InvalidInvoiceLineException::forEmptyDescription();
        }

        if (strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw InvalidInvoiceLineException::forDescriptionExceedingMaxLength(self::MAX_DESCRIPTION_LENGTH);
        }

        if ($quantity < 1) {
            throw InvalidInvoiceLineException::forNonPositiveQuantity($quantity);
        }

        $lineAmount = $unitPrice->multiply((string) $quantity, RoundingMode::Unnecessary);

        return new self($description, $quantity, $unitPrice, $lineAmount);
    }

    /**
     * Reconstruct an already-validated line from persisted state — no
     * validation beyond each field's own bound, mirroring every other
     * `reconstitute()` in this codebase.
     */
    public static function reconstitute(string $description, int $quantity, Money $unitPrice, Money $lineAmount): self
    {
        return new self($description, $quantity, $unitPrice, $lineAmount);
    }

    public function description(): string
    {
        return $this->description;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function unitPrice(): Money
    {
        return $this->unitPrice;
    }

    public function lineAmount(): Money
    {
        return $this->lineAmount;
    }
}
