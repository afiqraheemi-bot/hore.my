<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Posting\Exception\InvalidSourceReferenceException;

/**
 * A Posting Command's Source reference (AETS-004 §19, Actor / Source /
 * Evidence Traceability; AETS-007 §9.1): the minimal contract standing
 * in for the traceable reference to the Accounting Command, accepted
 * Accounting Proposal, or correction reference that gave rise to a
 * Posting Command, pending AETS-010's full Source schema (AETS-007
 * §26).
 *
 * **What this is, and what it is not.** This is a pure identity
 * wrapper — an opaque reference sufficient to trace back to whatever
 * gave rise to the command. It does not itself parse, interpret, or
 * classify what it points to: it has no knowledge of, and makes no
 * distinction between, a directly authored command, a confirmed AI
 * proposal, or a Reversal/Replacement's correction reference. That
 * discrimination, where ever needed, belongs to whichever future
 * component actually resolves the reference (AETS-010's own eventual
 * design), never to this class (AETS-007 §9.1). Source is also
 * conceptually distinct from Actor (AETS-007 §9): Actor identifies
 * *who* accepted a command; Source identifies *what* gave rise to it
 * — the two are recorded independently and neither is derived from
 * the other.
 *
 * **Physical representation is deliberately not locked here.**
 * AETS-007 §9.1 requires only the reference's identity contract —
 * opaque, immutable, exactly comparable — and explicitly leaves its
 * physical representation open for this implementation, and for
 * AETS-010, to make or revise. This class's current choice of a
 * bounded canonical string is exactly that: an implementation choice,
 * not a normative one. It wraps an already-supplied canonical string
 * and enforces only the minimum safe rejections that hold regardless
 * of physical representation (non-empty, not silently normalized, no
 * control character, a defensive length bound) — mirroring the
 * pattern already established for `IdempotencyKey` and
 * `SourceFingerprint`.
 */
final class SourceReference
{
    /**
     * A defensive bound against pathologically long input, per
     * AETS-003 §18's general input-hygiene requirement — reusing the
     * same 64-character bound already established for
     * `IdempotencyKey`, `SourceFingerprint`, `JournalId`, and
     * `AccountId`'s own opaque identifiers, since no source document
     * sets a different bound for Source reference specifically. Not a
     * claim about AETS-010's eventual representation's actual length.
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
     * Construct a SourceReference from an already-supplied canonical
     * string. This method performs no parsing, interpretation, or
     * classification of what the reference points to — it only
     * validates and wraps a value supplied by the caller.
     *
     * Rejects an empty value, a value exceeding the defensive length
     * bound, a value containing a control character, and a value with
     * leading or trailing whitespace — the last of these is rejected
     * outright rather than silently trimmed, so a caller can never
     * observe a reference that differs from what was actually
     * supplied.
     *
     * @throws InvalidSourceReferenceException if the value is not a
     *                                         canonical Source reference.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidSourceReferenceException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidSourceReferenceException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidSourceReferenceException::forValue($value);
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
