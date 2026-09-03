<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Money;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Exception\InvalidCurrencyException;
use App\Domain\Accounting\Money\Exception\InvalidMinorUnitsException;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Infrastructure\Accounting\Money\Exception\MoneyOutOfPersistenceRangeException;

/**
 * Maps hore.my's Money to and from its canonical PostgreSQL `BIGINT`
 * persistence representation (AETS-003 §15; ADR-0007's amendment).
 *
 * This is the only code with knowledge of both the Money domain
 * contract and the `BIGINT` column it maps to — Money, Currency, and
 * MinorUnits themselves remain entirely unaware of persistence
 * (AETS-003 §15). It performs no I/O of its own: it produces and
 * consumes plain primitive values, leaving the actual read/write
 * (a future repository, over an as-yet-undesigned monetary table) to
 * its caller. No business table exists yet — this adapter defines the
 * mapping contract those tables will eventually rely on.
 *
 * Sign policy is not broadened here: Money and MinorUnits can
 * currently only ever hold a non-negative exact value (their own
 * construction paths reject negative input), so a signed 64-bit
 * BIGINT's negative half is never actually reachable from
 * {@see toPersistedAmount()} today. This adapter does not interpret
 * that unused negative range as authorization for negative Money —
 * it is simply headroom the column type provides, unclaimed until a
 * future Money sign policy decision resolves it.
 */
final class MoneyPersistenceAdapter
{
    /**
     * The maximum value a signed 64-bit `BIGINT` column can hold.
     * Compared against as a numeral string, never as a native PHP
     * int, so the check is exact at any magnitude MinorUnits itself
     * supports — never bounded by, or silently coerced through,
     * native int range.
     */
    private const BIGINT_MAX = '9223372036854775807';

    /**
     * Extracts the exact MinorUnits amount a Money value must persist
     * as, validated against the signed 64-bit `BIGINT` range before
     * any write is attempted.
     *
     * @throws MoneyOutOfPersistenceRangeException if the amount does
     *                                             not fit within the signed 64-bit range.
     */
    public function toPersistedAmount(Money $money): string
    {
        $minorUnits = $money->toMinorUnits()->toString();

        if (self::exceedsBigIntMax($minorUnits)) {
            throw MoneyOutOfPersistenceRangeException::forAmount($minorUnits);
        }

        return $minorUnits;
    }

    /**
     * The canonical, uppercase ISO currency identifier a Money value
     * must be persisted alongside — explicitly, on every row, never
     * inferred from a tenant or another aggregate. Never a scale.
     */
    public function toPersistedCurrency(Money $money): string
    {
        return $money->currency()->identifier();
    }

    /**
     * Reconstructs a Money value from a persisted `(amount, currency)`
     * pair, deterministically, through Money's own validated
     * construction path — a persisted row is never treated as
     * pre-trusted raw data. Currency scale is not read from storage;
     * it is derived from the reconstructed Currency, as it always is.
     *
     * @throws InvalidMinorUnitsException if the
     *                                    persisted amount is not a canonical non-negative integer numeral.
     * @throws InvalidCurrencyException if the
     *                                  persisted currency identifier is not currently supported.
     */
    public function fromPersisted(string $amount, string $currencyIdentifier): Money
    {
        $currency = Currency::of($currencyIdentifier);
        $minorUnits = MinorUnits::of($amount);

        return Money::fromMinorUnits($minorUnits, $currency);
    }

    private static function exceedsBigIntMax(string $digits): bool
    {
        if (strlen($digits) !== strlen(self::BIGINT_MAX)) {
            return strlen($digits) > strlen(self::BIGINT_MAX);
        }

        return strcmp($digits, self::BIGINT_MAX) > 0;
    }
}
