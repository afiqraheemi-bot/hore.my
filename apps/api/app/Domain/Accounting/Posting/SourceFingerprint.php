<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Posting\Exception\InvalidSourceFingerprintException;

/**
 * A Posting Command's Source Fingerprint (AETS-001, Source
 * Fingerprint; AETS-007 §6.2): an identifier used to detect that the
 * same source material — the content or origin of external/imported
 * source data, such as a Bank Transaction, an uploaded receipt, an
 * uploaded invoice, an imported statement row, or an external
 * integration payload — has already given rise to a command, so it
 * cannot do so a second time.
 *
 * **What this identifies, and what it does not.** A SourceFingerprint
 * identifies source *material* for source-level deduplication only.
 * It is conceptually distinct from `IdempotencyKey`
 * (AETS-001; AETS-007 §6.1), which identifies a logical Accounting
 * Command *execution* identity — the two answer different questions
 * and neither substitutes for the other. This class does not identify
 * a Journal (that is `JournalId`'s job, AETS-004 §6), does not
 * identify a Tenant, does not determine Posting Command equality, and
 * does not determine command replay semantics — those remain the
 * future Posting Command/Posting Engine's concern (AETS-007 §6.2,
 * §15), never this Value Object's.
 *
 * **Whether one is required is not this class's decision.**
 * SourceFingerprint is a pure identity wrapper with no opinion on
 * when it must be present. A manual command with no external source
 * material behind it may legitimately carry none — that
 * conditionality is stated and enforced by the future Posting
 * Command's own contract (AETS-007 §6.2), not by this class, which
 * only validates a value it is actually given.
 *
 * **Derivation is deliberately out of scope here.** AETS-001 and
 * AETS-007 §6.2 both treat the Source Fingerprint's concrete
 * derivation (a hashing scheme, a content-vs-origin basis, or
 * otherwise) as a later implementation decision this class does not
 * make. It is therefore deliberately format-agnostic: it wraps an
 * already-derived canonical string and enforces only the minimum safe
 * rejections that hold regardless of the still-undecided derivation
 * strategy (non-empty, not silently normalized, no control character,
 * a defensive length bound). It has no knowledge of, and no opinion
 * on, the structure of whatever source material (a Bank Transaction
 * feed, a receipt image, an invoice document, or any other payload)
 * the fingerprint was derived from — parsing or interpreting that
 * material is not this class's concern. Mirrors the pattern already
 * established for `IdempotencyKey`, `JournalId`, and `AccountId`.
 */
final class SourceFingerprint
{
    /**
     * A defensive bound against pathologically long input, per
     * AETS-003 §18's general input-hygiene requirement — reusing the
     * same 64-character bound already established for
     * `IdempotencyKey`, `JournalId`, and `AccountId`'s own opaque
     * identifiers, since no source document sets a different bound
     * for Source Fingerprint specifically. Not a claim about any
     * derivation strategy's actual output length.
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
     * Construct a SourceFingerprint from an already-derived canonical
     * string. This method does not derive, hash, or generate a
     * fingerprint — it only validates and wraps one supplied by the
     * caller.
     *
     * Rejects an empty value, a value exceeding the defensive length
     * bound, a value containing a control character, and a value with
     * leading or trailing whitespace — the last of these is rejected
     * outright rather than silently trimmed, so a caller can never
     * observe a fingerprint that differs from what was actually
     * supplied.
     *
     * @throws InvalidSourceFingerprintException if the value is not a
     *                                           canonical Source Fingerprint.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidSourceFingerprintException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidSourceFingerprintException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidSourceFingerprintException::forValue($value);
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Exact canonical value equality — case-sensitive, with no
     * implicit normalization.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
