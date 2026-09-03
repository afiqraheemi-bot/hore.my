<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Money;

use App\Domain\Accounting\Money\Exception\InvalidMinorUnitsException;

/**
 * An exact, arbitrary-magnitude integer numeral — the count of a
 * currency's minor units.
 *
 * Per AETS-003 §8: MinorUnits carries no Currency and no arithmetic or
 * business behavior of its own; it is a narrow type-safety carrier, not
 * a second Money. It exposes only construction, an exact string
 * representation, and value equality — never a native-int canonical
 * accessor (AETS-003 §14 / MON-014).
 *
 * Construction currently accepts only non-negative numerals. AETS-003
 * does not state whether MinorUnits itself may hold a negative value;
 * the only substantive reason it would ever need to is tied directly to
 * Money's still-deferred sign policy (AETS-003 §25). This class does
 * not decide that question — negative-numeral construction is
 * deliberately not implemented here. See M1-T2's report for the full
 * reasoning.
 */
final class MinorUnits
{
    /**
     * Zero, or a non-zero digit followed by zero or more digits — no
     * leading zeros, no sign, no decimal point, no other character.
     */
    private const PATTERN = '/^(0|[1-9][0-9]*)$/';

    /**
     * Defensive bound against pathologically long input, per AETS-003
     * §18's general input-hygiene requirement. Far beyond any realistic
     * magnitude — not a claim about MinorUnits' own maximum value.
     */
    private const MAX_LENGTH = 1000;

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Construct a MinorUnits from a canonical, non-negative integer
     * numeral string.
     *
     * Rejects malformed strings, decimal strings, scientific notation,
     * whitespace-padded input, and non-canonical forms (leading zeros).
     * No normalization is performed.
     *
     * @throws InvalidMinorUnitsException if the input is not a
     *                                    canonical non-negative integer numeral.
     */
    public static function of(string $value): self
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidMinorUnitsException::forValue($value);
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
