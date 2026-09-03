<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Money;

/**
 * hore.my's own rounding-mode vocabulary for Money arithmetic.
 *
 * Per AETS-003 §13: a RoundingMode is required whenever an operation
 * could lose precision, and hore.my owns this type so no vendor
 * rounding type ever appears in a public Money signature.
 *
 * Only `Unnecessary` is defined here — the sole mode already required
 * and already relied upon by Money's existing exact arithmetic (`add`,
 * `subtract`): assert the result is exact, and fail loudly if it is
 * not. AETS-003 §13 itself states it "does not enumerate which
 * RoundingMode values exist" — no other standard mathematical
 * rounding strategy (Up, Down, Ceiling, Floor, HalfUp, HalfDown,
 * HalfCeiling, HalfFloor, HalfEven) is named as currently required
 * anywhere in the specification. Defining them here, ahead of the
 * still-deferred tax/business rounding policy, would risk becoming a
 * de facto policy decision by omission — so they are deliberately not
 * added yet. See M1-T4's report for the full reasoning.
 */
enum RoundingMode
{
    case Unnecessary;
}
