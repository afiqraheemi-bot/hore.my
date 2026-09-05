<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Posting\Exception\InvalidEvidenceReferenceException;

/**
 * A Journal's Evidence reference (AETS-004 §19, Actor / Source /
 * Evidence Traceability; AETS-010 §8): the minimal contract standing in
 * for a traceable reference to the Evidence — a receipt, invoice, bank
 * statement, or other document — supporting a posted Journal, pending a
 * future Document Processing specification's full Evidence schema
 * (AETS-010 §2.2).
 *
 * **What this is, and what it is not.** This is a pure identity
 * wrapper, mirroring {@see ActorReference} and {@see SourceReference}
 * exactly. It does not itself parse, interpret, or classify what it
 * points to, does not resolve to a file, hash, or upload record, and
 * does not itself validate that the Evidence it names exists or
 * belongs to any particular Tenant — AETS-010 §11 explicitly defers
 * that validation until Evidence gains a persisted, tenant-scoped
 * identity to validate against, since no producer of one exists yet.
 *
 * **Physical representation is deliberately not locked here.**
 * AETS-010 §8 requires only the reference's identity contract — opaque,
 * immutable, exactly comparable — and explicitly leaves its physical
 * representation open for this implementation, and for a future
 * Document Processing specification, to make or revise. This class's
 * current choice of a bounded canonical string mirrors
 * {@see ActorReference}/{@see SourceReference} exactly, for the same
 * reasons.
 */
final class EvidenceReference
{
    /**
     * A defensive bound against pathologically long input, mirroring
     * the same 64-character bound already established for
     * `ActorReference`, `SourceReference`, `IdempotencyKey`,
     * `SourceFingerprint`, `JournalId`, and `AccountId`'s own opaque
     * identifiers. Not a claim about a future Evidence schema's actual
     * identifier length.
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
     * Construct an EvidenceReference from an already-supplied canonical
     * string. This method performs no parsing, interpretation, or
     * classification of what the reference points to, and does not
     * validate that it resolves to real Evidence — it only validates
     * and wraps a value supplied by the caller (AETS-010 §8).
     *
     * Rejects an empty value, a value exceeding the defensive length
     * bound, a value containing a control character, and a value with
     * leading or trailing whitespace — the last of these is rejected
     * outright rather than silently trimmed, so a caller can never
     * observe a reference that differs from what was actually
     * supplied.
     *
     * @throws InvalidEvidenceReferenceException if the value is not a
     *                                           canonical Evidence reference.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidEvidenceReferenceException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidEvidenceReferenceException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidEvidenceReferenceException::forValue($value);
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
