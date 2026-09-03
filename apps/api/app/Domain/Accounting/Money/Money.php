<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Money;

use App\Domain\Accounting\Money\Exception\CurrencyMismatchException;
use App\Domain\Accounting\Money\Exception\DivisionByZeroException;
use App\Domain\Accounting\Money\Exception\InvalidMoneyAmountException;
use App\Domain\Accounting\Money\Exception\MoneyArithmeticException;
use App\Domain\Accounting\Money\Exception\RoundingRequiredException;
use App\Domain\Accounting\Money\Exception\UnresolvedMoneySignPolicyException;
use Brick\Math\Exception\DivisionByZeroException as BrickDivisionByZeroException;
use Brick\Math\Exception\MathException as BrickMathException;
use Brick\Math\Exception\RoundingNecessaryException as BrickRoundingNecessaryException;
use Brick\Math\RoundingMode as BrickRoundingMode;
use Brick\Money\Exception\MoneyException as BrickMoneyException;
use Brick\Money\Money as BrickMoney;

/**
 * An exact monetary quantity paired with an explicit Currency.
 *
 * Per AETS-003 §6: Money is persistence-agnostic, contains no
 * formatting or database metadata, and never exposes a native-int
 * canonical amount. Cross-currency operations are guarded (§11, §12).
 *
 * `brick/money` (and its `brick/math` dependency) is the approved
 * internal arithmetic engine, wrapped entirely as a private
 * implementation detail: no `Brick\...` type appears in this class's
 * public API, and every vendor exception is translated into a
 * hore.my-owned one before it can reach a caller (AETS-003 §16).
 *
 * Money sign policy is deferred (AETS-003 §25) and is not resolved
 * here: `fromDecimalString` and `subtract` reject, with a distinct
 * exception, any case that would require a negative Money or
 * MinorUnits value, rather than inventing an answer. `multiply` and
 * `divide` accept only a non-negative exact numeral scalar for the
 * same reason — a negative scalar is rejected the same way a negative
 * decimal string is (M1-T5).
 *
 * `multiply` and `divide` require an explicit {@see RoundingMode}
 * argument at every call site (AETS-003 §13); since only
 * {@see RoundingMode::Unnecessary} currently exists, any operation
 * whose exact result does not fit the Currency's scale fails with
 * {@see RoundingRequiredException}
 * rather than silently rounding.
 */
final class Money
{
    /**
     * Canonical grammar for a `multiply`/`divide` scalar operand: an
     * optional leading minus sign (permitted by the grammar, but
     * rejected separately — see {@see guardedScalar()} — because sign
     * policy is deferred, not because it is malformed); digits with no
     * redundant leading zero; and, optionally, a decimal point followed
     * by one or more digits. Unlike Money's own {@see grammarFor()},
     * this is not tied to any Currency scale — a scalar is not itself a
     * Money value (AETS-003 §10).
     */
    private const SCALAR_PATTERN = '/^(?<sign>-)?(?<int>0|[1-9][0-9]*)(\.(?<frac>[0-9]+))?$/';

    /**
     * A bounded input-length check, mirroring MinorUnits' own bound, as
     * a basic defense against an adversarially long scalar operand
     * (AETS-003 §18).
     */
    private const SCALAR_MAX_LENGTH = 1000;

    private readonly BrickMoney $inner;

    private readonly Currency $currency;

    private function __construct(BrickMoney $inner, Currency $currency)
    {
        $this->inner = $inner;
        $this->currency = $currency;
    }

    /**
     * Construct a Money directly from an already-exact MinorUnits value
     * and a Currency. Always succeeds for valid inputs — MinorUnits is
     * already exact, so no additional precision-loss risk exists here.
     * Exact for any magnitude MinorUnits itself supports.
     */
    public static function fromMinorUnits(MinorUnits $minorUnits, Currency $currency): self
    {
        return new self(
            BrickMoney::ofMinor($minorUnits->toString(), self::brickCurrencyFor($currency)),
            $currency,
        );
    }

    /**
     * Construct a Money from a canonical decimal string and a Currency.
     *
     * Applies the canonical decimal grammar (AETS-003 §9) for the
     * target Currency's scale: an optional leading minus sign, digits
     * with no redundant leading zero, and — only if the Currency's
     * scale is greater than zero — exactly one decimal point followed
     * by exactly that many digits. No locale formatting, thousands
     * separator, currency symbol, whitespace, or scientific notation is
     * part of the grammar. No normalization is performed. Grammar
     * validation is entirely hore.my's own — the vendor library is not
     * consulted for parsing, only for arithmetic, so this cannot accept
     * anything looser or stricter than AETS-003 specifies.
     *
     * A grammatically valid but negative amount is rejected with
     * {@see UnresolvedMoneySignPolicyException}, distinct from a
     * malformed amount, because AETS-003 §9's grammar permits a leading
     * minus sign even though Money sign policy itself remains deferred.
     *
     * @throws InvalidMoneyAmountException if the input does not
     *                                     conform to the canonical decimal grammar.
     * @throws UnresolvedMoneySignPolicyException if the input is a
     *                                            grammatically valid negative amount.
     */
    public static function fromDecimalString(string $amount, Currency $currency): self
    {
        if (preg_match(self::grammarFor($currency->scale()), $amount, $matches) !== 1) {
            throw InvalidMoneyAmountException::forValue($amount);
        }

        if (($matches['sign'] ?? '') !== '') {
            throw UnresolvedMoneySignPolicyException::forDecimalString($amount);
        }

        $integerPart = $matches['int'] ?? '';
        $fractionalPart = $matches['frac'] ?? '';

        $combined = ltrim($integerPart.$fractionalPart, '0');
        $numeral = $combined === '' ? '0' : $combined;

        return self::fromMinorUnits(MinorUnits::of($numeral), $currency);
    }

    /**
     * The exact value as a canonical decimal string. Always succeeds
     * and is always lossless for a validly constructed Money, at any
     * magnitude.
     */
    public function toDecimalString(): string
    {
        return $this->inner->getAmount()->__toString();
    }

    /**
     * The exact value as MinorUnits, at any magnitude — not bounded to
     * native PHP int range.
     */
    public function toMinorUnits(): MinorUnits
    {
        return MinorUnits::of($this->inner->getMinorAmount()->toBigInteger()->__toString());
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /**
     * The exact sum of two same-Currency Money values, correct for any
     * magnitude — never bounded to native PHP int range, and never
     * silently converted to float.
     *
     * @throws CurrencyMismatchException if the Currencies differ.
     * @throws MoneyArithmeticException if the underlying arithmetic
     *                                  engine reports an unanticipated failure.
     */
    public function add(self $other): self
    {
        $this->guardSameCurrency($other);

        $mode = self::brickRoundingModeFor(RoundingMode::Unnecessary);
        $result = $this->guardedBrickCall('add', fn (): BrickMoney => $this->inner->plus($other->inner, $mode));

        return new self($result, $this->currency);
    }

    /**
     * The exact difference of two same-Currency Money values, correct
     * for any magnitude, only for the case where the result is
     * non-negative.
     *
     * @throws CurrencyMismatchException if the Currencies differ.
     * @throws UnresolvedMoneySignPolicyException if the result would be
     *                                            negative.
     * @throws MoneyArithmeticException if the underlying arithmetic
     *                                  engine reports an unanticipated failure.
     */
    public function subtract(self $other): self
    {
        $this->guardSameCurrency($other);

        $mode = self::brickRoundingModeFor(RoundingMode::Unnecessary);
        $result = $this->guardedBrickCall('subtract', fn (): BrickMoney => $this->inner->minus($other->inner, $mode));

        if ($result->isNegative()) {
            throw UnresolvedMoneySignPolicyException::forSubtraction();
        }

        return new self($result, $this->currency);
    }

    /**
     * The exact product of this Money and a non-negative exact numeral
     * scalar, correct for any magnitude.
     *
     * The scalar is a canonical numeral string — an optional integer
     * part and, optionally, a decimal point followed by one or more
     * digits (AETS-003 §10's "numeral string" scalar option; no
     * Currency-scale grammar applies, since a scalar is not itself a
     * Money value). A grammatically valid but negative scalar is
     * rejected the same way a negative decimal string is (§9's
     * sign-permitting grammar, deferred sign policy).
     *
     * `$mode` is required at every call site (AETS-003 §13) — omitting
     * it is not a valid invocation. Only {@see RoundingMode::Unnecessary}
     * currently exists, so any exact result that would not fit this
     * Money's Currency scale fails rather than being rounded.
     *
     * @throws InvalidMoneyAmountException if the scalar is not a
     *                                     canonical exact numeral.
     * @throws UnresolvedMoneySignPolicyException if the scalar is
     *                                            negative.
     * @throws RoundingRequiredException if the exact result cannot be
     *                                   represented at this Money's
     *                                   Currency scale.
     * @throws MoneyArithmeticException if the underlying arithmetic
     *                                  engine reports an unanticipated failure.
     */
    public function multiply(string $scalar, RoundingMode $mode): self
    {
        $validScalar = self::guardedScalar($scalar, 'multiply');
        $brickMode = self::brickRoundingModeFor($mode);

        $result = $this->guardedBrickCall(
            'multiply',
            fn (): BrickMoney => $this->inner->multipliedBy($validScalar, $brickMode),
        );

        return new self($result, $this->currency);
    }

    /**
     * The exact quotient of this Money divided by a non-negative exact
     * numeral scalar, correct for any magnitude. Scalar grammar,
     * deferred-sign handling, and the mandatory `$mode` argument are as
     * described on {@see multiply()}.
     *
     * @throws InvalidMoneyAmountException if the scalar is not a
     *                                     canonical exact numeral.
     * @throws UnresolvedMoneySignPolicyException if the scalar is
     *                                            negative.
     * @throws DivisionByZeroException if the scalar is zero.
     * @throws RoundingRequiredException if the exact result cannot be
     *                                   represented at this Money's
     *                                   Currency scale.
     * @throws MoneyArithmeticException if the underlying arithmetic
     *                                  engine reports an unanticipated failure.
     */
    public function divide(string $scalar, RoundingMode $mode): self
    {
        $validScalar = self::guardedScalar($scalar, 'divide');
        $brickMode = self::brickRoundingModeFor($mode);

        $result = $this->guardedBrickCall(
            'divide',
            fn (): BrickMoney => $this->inner->dividedBy($validScalar, $brickMode),
        );

        return new self($result, $this->currency);
    }

    /**
     * A three-way ordering comparison between two same-Currency Money
     * values: negative if this is less than $other, zero if equal,
     * positive if greater. Correct for any magnitude.
     *
     * @throws CurrencyMismatchException if the Currencies differ.
     */
    public function compareTo(self $other): int
    {
        $this->guardSameCurrency($other);

        return $this->guardedBrickCall('compareTo', fn (): int => $this->inner->compareTo($other->inner));
    }

    /**
     * Value equality: true iff same exact quantity and same Currency.
     * Never throws, even for mismatched Currency — the answer to "are
     * these equal" is always well-defined.
     */
    public function equals(self $other): bool
    {
        if (! $this->currency->equals($other->currency)) {
            return false;
        }

        return $this->guardedBrickCall('equals', fn (): bool => $this->inner->isEqualTo($other->inner));
    }

    private function guardSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw CurrencyMismatchException::forCurrencies($this->currency, $other->currency);
        }
    }

    /**
     * Runs a vendor-library call and translates any Brick exception
     * into a hore.my-owned one — no `Brick\...` exception can escape
     * this boundary (AETS-003 §16).
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function guardedBrickCall(string $operationName, callable $operation)
    {
        try {
            return $operation();
        } catch (BrickDivisionByZeroException) {
            throw DivisionByZeroException::forDivisor();
        } catch (BrickRoundingNecessaryException) {
            throw RoundingRequiredException::forOperation($operationName);
        } catch (BrickMoneyException|BrickMathException) {
            // Deliberately not chained as $previous, and $operationName
            // is the only detail carried forward — a vendor exception
            // object or class name must never be observable through
            // this translation, by any public means (AETS-003 §16).
            throw MoneyArithmeticException::forOperation($operationName);
        }
    }

    /**
     * Translates hore.my's own RoundingMode into the vendor library's
     * equivalent — confined entirely to this method, so no vendor
     * rounding type ever appears outside it (AETS-003 §13, §16).
     */
    private static function brickRoundingModeFor(RoundingMode $mode): BrickRoundingMode
    {
        return match ($mode) {
            RoundingMode::Unnecessary => BrickRoundingMode::Unnecessary,
        };
    }

    private static function brickCurrencyFor(Currency $currency): \Brick\Money\Currency
    {
        $identifier = $currency->identifier();
        $scale = $currency->scale();

        // Currency's own invariants already guarantee a non-empty
        // identifier and a non-negative scale; these guards exist so
        // the vendor library's own stricter parameter types are
        // satisfied explicitly rather than assumed.
        if ($identifier === '') {
            throw new \LogicException('Currency identifier must not be empty.');
        }

        if ($scale < 0) {
            throw new \LogicException('Currency scale must not be negative.');
        }

        return new \Brick\Money\Currency($identifier, null, $identifier, $scale);
    }

    private static function grammarFor(int $scale): string
    {
        if ($scale > 0) {
            return sprintf('/^(?<sign>-)?(?<int>0|[1-9][0-9]*)\.(?<frac>[0-9]{%d})$/', $scale);
        }

        return '/^(?<sign>-)?(?<int>0|[1-9][0-9]*)$/';
    }

    /**
     * Validates a `multiply`/`divide` scalar operand against
     * {@see SCALAR_PATTERN} and the deferred sign policy, returning it
     * unchanged once confirmed exact and non-negative.
     *
     * @throws InvalidMoneyAmountException if the scalar is not a
     *                                     canonical exact numeral.
     * @throws UnresolvedMoneySignPolicyException if the scalar is
     *                                            negative.
     */
    private static function guardedScalar(string $scalar, string $operation): string
    {
        if (strlen($scalar) > self::SCALAR_MAX_LENGTH) {
            throw InvalidMoneyAmountException::forScalar($scalar);
        }

        if (preg_match(self::SCALAR_PATTERN, $scalar, $matches) !== 1) {
            throw InvalidMoneyAmountException::forScalar($scalar);
        }

        if ($matches['sign'] !== '') {
            throw UnresolvedMoneySignPolicyException::forScalarOperation($operation, $scalar);
        }

        return $scalar;
    }
}
