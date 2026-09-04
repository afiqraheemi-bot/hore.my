<?php

declare(strict_types=1);

namespace App\Domain\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountNameException;

/**
 * An Account's human-readable Name (AETS-005 §9): a non-empty label a
 * bookkeeper reads and recognizes. Unlike {@see AccountCode}, a Name
 * carries no tenant-scoped-uniqueness requirement — distinct Names are
 * a usability concern, not an integrity one (§9).
 *
 * AETS-005 introduces no naming taxonomy or localization/i18n policy
 * — none is invented here. This Value Object enforces only what
 * AETS-005 already normatively requires regardless of any such future
 * policy: the value must be non-empty, must not be silently
 * normalized (no leading/trailing whitespace is trimmed — such input
 * is rejected outright, not accepted in altered form), and must not
 * contain a control character. A bounded maximum length is enforced
 * purely as input hygiene against adversarially long input (mirroring
 * the same defensive bound already established for
 * {@see AccountCode} and {@see AccountId}), not as a claim about any
 * final naming convention's length.
 */
final class AccountName
{
    /**
     * A defensive bound against pathologically long input, per
     * AETS-003 §18's general input-hygiene requirement (mirrored here
     * for Account Name). Not a claim about any final naming
     * convention's maximum length.
     */
    private const MAX_LENGTH = 64;

    /**
     * Any ASCII control character (0x00–0x1F, 0x7F) — not part of a
     * human-readable name.
     */
    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Construct an AccountName from a canonical string.
     *
     * Rejects an empty value, a value exceeding the defensive length
     * bound, a value containing a control character, and a value with
     * leading or trailing whitespace — the last of these is rejected
     * outright rather than silently trimmed, so a caller can never
     * observe a name that differs from what was actually supplied.
     *
     * @throws InvalidAccountNameException if the value is not a
     *                                     canonical Account Name.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidAccountNameException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidAccountNameException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidAccountNameException::forValue($value);
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
