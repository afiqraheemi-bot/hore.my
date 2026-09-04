<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Posting\Exception\InvalidIdempotencyKeyException;

/**
 * A Posting Command's Idempotency Key (AETS-001, Idempotency Key;
 * AETS-007 §6.1): the identifier that makes a Posting Command's
 * *execution request* itself safely retryable — repeating or
 * concurrently retrying the same logical command, under the same
 * (Tenant, Idempotency Key) pair, must never create a second
 * accounting effect.
 *
 * **What this identifies, and what it does not.** An IdempotencyKey
 * identifies the logical Accounting Command execution identity only.
 * It does not identify source evidence (that is `SourceFingerprint`'s
 * job, AETS-001, distinct and never a substitute — AETS-007 §6.2), it
 * does not identify a Journal (that is `JournalId`'s job, AETS-004 §6),
 * it does not identify a Tenant, and it does not, by itself, determine
 * whether two command payloads are logically equivalent — that
 * comparison is scoped to (Tenant, IdempotencyKey) plus a
 * logical-command payload comparison the future Posting Engine
 * performs, not this Value Object (AETS-007 §6.1, §15). This class
 * carries none of that logic; it is a pure identity wrapper.
 *
 * **Derivation is deliberately out of scope here.** AETS-004 §14 and
 * AETS-007 §6.1 both treat the Idempotency Key's concrete derivation
 * (hashing scheme, UUID, ULID, or otherwise) as a later implementation
 * decision this class does not make. It is therefore deliberately
 * format-agnostic: it wraps an already-generated canonical string and
 * enforces only the minimum safe rejections that hold regardless of
 * the still-undecided generation strategy (non-empty, not silently
 * normalized, no control character, a defensive length bound). It has
 * no opinion on, and no knowledge of, how that string was produced —
 * generation is not this class's concern; nothing here decides or
 * assumes UUID, ULID, or any other identifier grammar. Mirrors the
 * pattern already established for `JournalId` and `AccountId`.
 */
final class IdempotencyKey
{
    /**
     * A defensive bound against pathologically long input, per
     * AETS-003 §18's general input-hygiene requirement — reusing the
     * same 64-character bound already established for `JournalId`
     * and `AccountId`'s own opaque identifiers, since no source document sets a different
     * bound for Idempotency Key specifically and none of the concrete
     * derivation strategies AETS-004/AETS-007 leave open (a UUID, a
     * ULID, or a deterministic hash) plausibly exceeds it. Not a claim
     * about any generation strategy's actual output length.
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
     * Construct an IdempotencyKey from an already-generated canonical
     * string. This method does not generate a key — it only validates
     * and wraps one supplied by the caller.
     *
     * Rejects an empty value, a value exceeding the defensive length
     * bound, a value containing a control character, and a value with
     * leading or trailing whitespace — the last of these is rejected
     * outright rather than silently trimmed, so a caller can never
     * observe a key that differs from what was actually supplied.
     *
     * @throws InvalidIdempotencyKeyException if the value is not a
     *                                        canonical Idempotency Key.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidIdempotencyKeyException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidIdempotencyKeyException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidIdempotencyKeyException::forValue($value);
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Exact canonical value equality — case-sensitive, with no
     * implicit normalization. AETS-007 does not state a
     * case-insensitivity rule for Idempotency Key, so none is
     * introduced here.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
