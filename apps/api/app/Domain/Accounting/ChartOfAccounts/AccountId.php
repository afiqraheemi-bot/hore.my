<?php

declare(strict_types=1);

namespace App\Domain\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountIdException;

/**
 * An Account's stable, opaque identifier (AETS-005 §7): assigned at
 * creation, immutable for the Account's lifetime, never reused or
 * reassigned to a different Account. A Journal Line references an
 * Account by this identifier, and only by this identifier — never by
 * Account Code or Name.
 *
 * AETS-005 §7 treats the identifier's concrete representation
 * (surrogate key, UUID, or otherwise) as an implementation detail —
 * only its stability and opacity are normative. This Value Object is
 * therefore deliberately format-agnostic: it wraps an
 * already-generated canonical string and enforces only the minimum
 * safe rejections that hold regardless of the still-unspecified
 * generation strategy (non-empty, not silently normalized, no control
 * character, a defensive length bound). It has no opinion on, and no
 * knowledge of, how that string was produced — generation is
 * deliberately not this class's concern; nothing here decides or
 * assumes UUID, ULID, or any other identifier grammar.
 */
final class AccountId
{
    /**
     * A defensive bound against pathologically long input, per
     * AETS-003 §18's general input-hygiene requirement (mirrored here
     * for Account identifiers). Not a claim about any generation
     * strategy's output length.
     */
    private const MAX_LENGTH = 64;

    /**
     * Any ASCII control character (0x00–0x1F, 0x7F) — not part of an
     * opaque identifier's canonical form.
     */
    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Construct an AccountId from an already-generated canonical
     * string. This method does not generate an identifier — it only
     * validates and wraps one supplied by the caller.
     *
     * Rejects an empty value, a value exceeding the defensive length
     * bound, a value containing a control character, and a value with
     * leading or trailing whitespace — the last of these is rejected
     * outright rather than silently trimmed, so a caller can never
     * observe an identifier that differs from what was actually
     * supplied.
     *
     * @throws InvalidAccountIdException if the value is not a
     *                                   canonical Account identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidAccountIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidAccountIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidAccountIdException::forValue($value);
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
