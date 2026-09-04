<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Posting;

use App\Domain\Accounting\Posting\Exception\InvalidIdempotencyKeyException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Covers IdempotencyKey as far as AETS-001, AETS-004 §14, and AETS-007
 * §6.1 define it for a bare Value Object. ATS-007 (`POST-T017`–
 * `POST-T024`) covers Idempotency Key *behavior* within the Posting
 * Command pipeline (first-attempt success, exact retry, conflicting
 * reuse, concurrency) — none of it exercises this Value Object's own
 * construction grammar in isolation. ATS-007 has no dedicated test ID
 * for IdempotencyKey's own construction validation, its
 * no-generation-API constraint, or its no-hashing-API constraint —
 * the same traceability situation already established for
 * `JournalIdTest`/`AccountIdTest` against ATS-004 (M2-T4, M3-T4).
 */
final class IdempotencyKeyTest extends TestCase
{
    /**
     * A valid, already-generated opaque key is accepted.
     */
    public function test_valid_idempotency_key_is_accepted(): void
    {
        $key = IdempotencyKey::of('01J8Z3K7QYUXG5N7EXAMPLE01');

        $this->assertInstanceOf(IdempotencyKey::class, $key);
    }

    /**
     * Exact string round-trip: the value returned by `toString()` is
     * exactly the value supplied to `of()`, unchanged.
     */
    public function test_exact_string_round_trip(): void
    {
        $key = IdempotencyKey::of('key-0001');

        $this->assertSame('key-0001', $key->toString());
    }

    /**
     * Value equality: two IdempotencyKey instances constructed from
     * the same value are equal.
     */
    public function test_same_value_is_equal(): void
    {
        $a = IdempotencyKey::of('key-0001');
        $b = IdempotencyKey::of('key-0001');

        $this->assertTrue($a->equals($b));
    }

    /**
     * Different values are not equal.
     */
    public function test_different_value_is_not_equal(): void
    {
        $a = IdempotencyKey::of('key-0001');
        $b = IdempotencyKey::of('key-0002');

        $this->assertFalse($a->equals($b));
    }

    /**
     * Equality is case-sensitive: AETS-007 states no
     * case-insensitivity rule for Idempotency Key, so none is
     * introduced here, and no implicit normalization occurs.
     */
    public function test_equality_is_case_sensitive(): void
    {
        $a = IdempotencyKey::of('Key-0001');
        $b = IdempotencyKey::of('key-0001');

        $this->assertFalse($a->equals($b));
    }

    /**
     * Empty input is rejected.
     */
    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidIdempotencyKeyException::class);

        IdempotencyKey::of('');
    }

    /**
     * Whitespace-only input is rejected.
     */
    public function test_whitespace_only_input_is_rejected(): void
    {
        $this->expectException(InvalidIdempotencyKeyException::class);

        IdempotencyKey::of('   ');
    }

    /**
     * Leading whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_leading_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidIdempotencyKeyException::class);

        IdempotencyKey::of(' key-0001');
    }

    /**
     * Trailing whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_trailing_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidIdempotencyKeyException::class);

        IdempotencyKey::of('key-0001 ');
    }

    /**
     * A control character (here, a null byte) is rejected.
     */
    public function test_control_character_is_rejected(): void
    {
        $this->expectException(InvalidIdempotencyKeyException::class);

        IdempotencyKey::of("key-0001\0");
    }

    /**
     * A newline embedded in an otherwise plausible key is rejected.
     */
    public function test_embedded_newline_is_rejected(): void
    {
        $this->expectException(InvalidIdempotencyKeyException::class);

        IdempotencyKey::of("key-0001\n");
    }

    /**
     * An adversarially long key is rejected via a bounded,
     * deterministic check.
     */
    public function test_adversarially_long_input_is_rejected(): void
    {
        $this->expectException(InvalidIdempotencyKeyException::class);

        IdempotencyKey::of(str_repeat('1', 1000));
    }

    /**
     * A value at exactly the 64-character bound is accepted — the
     * bound rejects values *longer* than 64, not values of exactly 64.
     */
    public function test_value_at_exactly_the_length_bound_is_accepted(): void
    {
        $key = IdempotencyKey::of(str_repeat('a', 64));

        $this->assertSame(64, strlen($key->toString()));
    }

    /**
     * A value one character past the 64-character bound is rejected.
     */
    public function test_value_one_character_past_the_length_bound_is_rejected(): void
    {
        $this->expectException(InvalidIdempotencyKeyException::class);

        IdempotencyKey::of(str_repeat('a', 65));
    }

    /**
     * IdempotencyKey is immutable: every property is readonly and no
     * public mutator method exists.
     */
    public function test_idempotency_key_is_immutable(): void
    {
        $reflection = new ReflectionClass(IdempotencyKey::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                sprintf('Property "%s" must be readonly.', $property->getName()),
            );
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertStringStartsNotWith(
                'set',
                $method->getName(),
                sprintf('Public method "%s" must not be a mutator.', $method->getName()),
            );
        }
    }

    /**
     * No native database ID semantics: no public method returns a
     * native PHP int as the canonical representation of the value.
     */
    public function test_no_native_int_canonical_accessor(): void
    {
        $reflection = new ReflectionClass(IdempotencyKey::class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();

            if ($returnType instanceof ReflectionNamedType) {
                $this->assertNotSame(
                    'int',
                    $returnType->getName(),
                    sprintf('Public method "%s" must not return a native int.', $method->getName()),
                );
            }
        }
    }

    /**
     * Exactly the intended public API exists: `of`, `toString`,
     * `equals` — no generation method and no hashing method.
     * Generation and hashing are deliberately not this class's
     * concern (AETS-004 §14, AETS-007 §6.1 both defer the concrete
     * derivation decision).
     */
    public function test_exposes_no_generation_or_hashing_method(): void
    {
        $reflection = new ReflectionClass(IdempotencyKey::class);

        $publicMethodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(['of', 'toString', 'equals'], $publicMethodNames);

        foreach (['generate', 'random', 'uuid', 'ulid', 'new', 'create', 'hash', 'fingerprint'] as $forbiddenMethodName) {
            $this->assertNotContains($forbiddenMethodName, $publicMethodNames);
        }
    }

    /**
     * No hashing performed by construction: `of()` stores the exact
     * supplied value, never a derived digest of it.
     */
    public function test_of_does_not_hash_the_supplied_value(): void
    {
        $key = IdempotencyKey::of('plain-value-0001');

        $this->assertSame('plain-value-0001', $key->toString());
    }

    /**
     * No SourceFingerprint behavior: the class's own docblock
     * legitimately *names* SourceFingerprint in prose, to explain that
     * IdempotencyKey is distinct from it and neither substitutes for
     * the other (AETS-001; AETS-007 §6.2) — mirroring the established
     * pattern of `AccountRepositoryTest::test_does_not_run_hierarchy_policy_on_a_single_read()`.
     * What must be absent is any actual *use* of it: an import, a
     * static call, or an instantiation — never a bare mention of the
     * word.
     */
    public function test_has_no_source_fingerprint_behavior(): void
    {
        $reflection = new ReflectionClass(IdempotencyKey::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('use App\\Domain\\Accounting\\Posting\\SourceFingerprint', $source);
        $this->assertStringNotContainsString('SourceFingerprint::', $source);
        $this->assertStringNotContainsString('new SourceFingerprint', $source);
    }

    /**
     * No Journal identity behavior: IdempotencyKey does not identify,
     * reference, or depend on JournalId — it identifies the command
     * execution, never the resulting Journal (AETS-007 §6.1). The
     * docblock names `JournalId` in prose only; no import, static
     * call, or instantiation of it exists.
     */
    public function test_has_no_journal_identity_behavior(): void
    {
        $reflection = new ReflectionClass(IdempotencyKey::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('JournalId::', $source);
        $this->assertStringNotContainsString('new JournalId', $source);
    }

    /**
     * No Tenant dependency: IdempotencyKey has no knowledge of
     * TenantId — the (Tenant, IdempotencyKey) scoping pair is a future
     * Posting Engine concern (AETS-007 §6.1, §15), not this Value
     * Object's.
     */
    public function test_has_no_tenant_dependency(): void
    {
        $reflection = new ReflectionClass(IdempotencyKey::class);

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType) {
                    $this->assertStringNotContainsString('TenantId', $type->getName());
                }
            }
        }

        $source = file_get_contents((string) $reflection->getFileName());
        $this->assertIsString($source);
        $this->assertStringNotContainsString('TenantId', $source);
    }

    /**
     * No database awareness: IdempotencyKey does not extend,
     * implement, or otherwise depend on any Eloquent or
     * database-related type.
     */
    public function test_has_no_database_awareness(): void
    {
        $reflection = new ReflectionClass(IdempotencyKey::class);

        $this->assertFalse($reflection->getParentClass());
        $this->assertSame([], $reflection->getInterfaceNames());
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionClass(IdempotencyKey::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    /**
     * No UUID/ULID vendor dependency: IdempotencyKey is format-agnostic
     * and does not import, reference, or depend on any UUID/ULID
     * library, and performs no hashing via a vendor hashing library.
     */
    public function test_has_no_uuid_ulid_or_hashing_vendor_dependency(): void
    {
        $reflection = new ReflectionClass(IdempotencyKey::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Ramsey\\', $source);
        $this->assertStringNotContainsString('Symfony\\Component\\Uid', $source);
        $this->assertStringNotContainsString('use function Str', $source);
        $this->assertStringNotContainsString('hash(', $source);
        $this->assertStringNotContainsString('md5(', $source);
        $this->assertStringNotContainsString('sha1(', $source);
    }
}
