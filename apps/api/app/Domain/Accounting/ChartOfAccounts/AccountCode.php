<?php

declare(strict_types=1);

namespace App\Domain\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountCodeException;
use App\Domain\Accounting\Money\MinorUnits;

/**
 * A canonical, tenant-scoped-unique Account Code (AETS-005 §8): a
 * short, human-auditable, business-facing label — never the Account's
 * own opaque identifier, and never a raw database identifier exposed
 * directly as the code (`COA-020`).
 *
 * AETS-005 explicitly defers the final Account Code grammar — no
 * character set, digit count, or range-to-Account-Type numbering
 * convention is decided here (§8, §25). This Value Object therefore
 * enforces only what AETS-005 already normatively requires regardless
 * of that future grammar: the value must be non-empty, must not be
 * silently normalized (no leading/trailing whitespace is trimmed —
 * such input is rejected outright, not accepted in altered form), and
 * must not contain a control character. A bounded maximum length is
 * enforced purely as input hygiene against adversarially long input
 * (mirroring the same defensive bound already established for
 * {@see MinorUnits}), not as a claim
 * about any final numbering scheme's length. Whether an Account Code
 * is numeric, alphabetic, or mixed is deliberately left unconstrained
 * — AETS-005 explicitly does not assume codes must be numeric.
 *
 * Tenant-scoped uniqueness (`COA-003`), System Account code
 * immutability (`COA-013`), and User-Created Account code mutability
 * (§8) are all Account-level concerns this Value Object does not, and
 * cannot, enforce on its own — they require Account and Tenant, which
 * do not exist yet (see M2-T3's report).
 */
final class AccountCode
{
    /**
     * A defensive bound against pathologically long input, per
     * AETS-003 §18's general input-hygiene requirement (mirrored here
     * for Account Code). Not a claim about any final numbering
     * scheme's maximum length.
     */
    private const MAX_LENGTH = 64;

    /**
     * Any ASCII control character (0x00–0x1F, 0x7F) — not part of a
     * human-auditable canonical code, regardless of the still-deferred
     * final grammar.
     */
    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Construct an AccountCode from a canonical string.
     *
     * Rejects an empty value, a value exceeding the defensive length
     * bound, a value containing a control character, and a value with
     * leading or trailing whitespace — the last of these is rejected
     * outright rather than silently trimmed, so a caller can never
     * observe a code that differs from what was actually supplied.
     *
     * @throws InvalidAccountCodeException if the value is not a
     *                                     canonical Account Code.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidAccountCodeException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidAccountCodeException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidAccountCodeException::forValue($value);
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
