<?php

declare(strict_types=1);

namespace App\Domain\Shared\Tenancy;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Shared\Tenancy\Exception\InvalidTenantIdException;

/**
 * A Tenant's stable, opaque identifier — immutable, generated
 * elsewhere. This Value Object only validates and wraps an
 * already-generated canonical value; it never decides how that value
 * was produced.
 *
 * Deliberately format-agnostic, mirroring
 * {@see AccountId}'s own
 * reasoning: the identifier's concrete representation (surrogate key,
 * UUID, or otherwise) is not a decision this Value Object makes.
 * It wraps an already-generated canonical string and enforces only
 * the minimum safe rejections that hold regardless of the
 * still-unspecified generation strategy (non-empty, not silently
 * normalized, no control character, a defensive length bound). It has
 * no opinion on, and no knowledge of, how that string was produced —
 * generation is deliberately not this class's concern; nothing here
 * decides or assumes UUID, ULID, or any other identifier grammar.
 *
 * Every Account (and every other Tenant-owned record) belongs to
 * exactly one Tenant, referenced by this identifier — the Tenant
 * entity itself, and any persistence/database concern, are not
 * implemented here.
 */
final class TenantId
{
    /**
     * A defensive bound against pathologically long input. Not a
     * claim about any generation strategy's output length.
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
     * Construct a TenantId from an already-generated canonical
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
     * @throws InvalidTenantIdException if the value is not a
     *                                  canonical Tenant identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidTenantIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidTenantIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidTenantIdException::forValue($value);
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
