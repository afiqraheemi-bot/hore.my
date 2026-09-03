<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Money;

use App\Domain\Accounting\Money\Exception\InvalidCurrencyException;

/**
 * A canonical currency identifier and its owned minor-unit scale.
 *
 * Per AETS-003 §7: Currency owns the minor-unit scale for its identifier —
 * scale is never an independent fact suppliable at construction. Canonical
 * identifiers are uppercase ISO 4217 form only; no case normalization is
 * performed here (see ATS-003 §19, MON-T092).
 */
final class Currency
{
    /**
     * Currently-supported canonical identifiers and their ISO 4217
     * minor-unit scale. MVP supports MYR only. The shape of this class
     * does not need to change to support another currency later — only
     * this registry would grow.
     *
     * @var array<string, int>
     */
    private const SUPPORTED = [
        'MYR' => 2,
    ];

    private readonly string $identifier;

    private readonly int $scale;

    private function __construct(string $identifier, int $scale)
    {
        $this->identifier = $identifier;
        $this->scale = $scale;
    }

    /**
     * Construct a Currency from its canonical identifier.
     *
     * Rejects any identifier outside the currently-supported set,
     * including a non-canonical case variant of a supported identifier
     * (e.g. "myr", "Myr") — no implicit normalization is performed.
     *
     * @throws InvalidCurrencyException if the identifier is not a
     *                                  supported canonical currency identifier.
     */
    public static function of(string $identifier): self
    {
        if (! array_key_exists($identifier, self::SUPPORTED)) {
            throw InvalidCurrencyException::forIdentifier($identifier);
        }

        return new self($identifier, self::SUPPORTED[$identifier]);
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function scale(): int
    {
        return $this->scale;
    }

    public function equals(self $other): bool
    {
        return $this->identifier === $other->identifier;
    }
}
