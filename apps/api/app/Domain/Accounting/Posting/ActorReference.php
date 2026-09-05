<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Posting\Exception\InvalidActorReferenceException;

/**
 * A Posting Command's Actor reference (AETS-001, Actor; AETS-007
 * §8.1): the minimal contract standing in for the human user,
 * operator, or authorized system process responsible for a Posting
 * Command, pending the future Identity/Access specification's full
 * Actor schema (AETS-007 §26).
 *
 * **What this is, and what it is not.** This is a pure identity
 * wrapper — an opaque reference sufficient to identify one Actor and,
 * at the Posting Validation Pipeline level, to be checked against the
 * command's own Tenant (AETS-007 §7, §14). It carries no
 * authentication, session, role, or authorization semantics of any
 * kind: it does not verify who the Actor is, does not decide what the
 * Actor is permitted to do, and does not resolve or store which
 * Tenant it belongs to. Accounting Core does not authenticate or
 * authorize an Actor; it only records which already-authorized
 * reference accepted a command (AETS-007 §8.1). Actor/Tenant
 * consistency is, and remains, a Posting Validation Pipeline
 * responsibility this class has no part in.
 *
 * **Physical representation is deliberately not locked here.**
 * AETS-007 §8.1 requires only the reference's identity contract —
 * opaque, immutable, exactly comparable — and explicitly leaves its
 * physical representation (a bounded string, a composite value, or
 * otherwise) open for this implementation, and for the future
 * Identity/Access specification, to make or revise. This class's
 * current choice of a bounded canonical string is exactly that: an
 * implementation choice, not a normative one. It wraps an
 * already-supplied canonical string and enforces only the minimum
 * safe rejections that hold regardless of physical representation
 * (non-empty, not silently normalized, no control character, a
 * defensive length bound) — mirroring the pattern already established
 * for `IdempotencyKey` and `SourceFingerprint`.
 */
final class ActorReference
{
    /**
     * A defensive bound against pathologically long input, per
     * AETS-003 §18's general input-hygiene requirement — reusing the
     * same 64-character bound already established for
     * `IdempotencyKey`, `SourceFingerprint`, `JournalId`, and
     * `AccountId`'s own opaque identifiers, since no source document
     * sets a different bound for Actor reference specifically. Not a
     * claim about any future Identity/Access representation's actual
     * length.
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
     * Construct an ActorReference from an already-supplied canonical
     * string. This method performs no authentication, authorization,
     * or Tenant resolution — it only validates and wraps a value
     * supplied by the caller.
     *
     * Rejects an empty value, a value exceeding the defensive length
     * bound, a value containing a control character, and a value with
     * leading or trailing whitespace — the last of these is rejected
     * outright rather than silently trimmed, so a caller can never
     * observe a reference that differs from what was actually
     * supplied.
     *
     * @throws InvalidActorReferenceException if the value is not a
     *                                        canonical Actor reference.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidActorReferenceException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidActorReferenceException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidActorReferenceException::forValue($value);
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
