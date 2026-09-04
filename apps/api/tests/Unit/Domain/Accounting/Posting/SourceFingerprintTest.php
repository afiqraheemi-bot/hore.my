<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Posting;

use App\Domain\Accounting\Posting\Exception\InvalidSourceFingerprintException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\SourceFingerprint;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Covers SourceFingerprint as far as AETS-001 and AETS-007 §6.2 define
 * it for a bare Value Object. ATS-007 (`POST-T025`–`POST-T031`) covers
 * Source Fingerprint *behavior* within the Posting Command pipeline
 * (manual-command omission, required-for-source-derived-commands,
 * missing-required rejection, anti-fabrication, duplicate-source
 * prevention) — none of it exercises this Value Object's own
 * construction grammar in isolation. ATS-007 has no dedicated test ID
 * for SourceFingerprint's own construction validation, its
 * no-derivation-API constraint, or its distinctness from
 * IdempotencyKey — the same traceability situation already
 * established and accepted for `IdempotencyKeyTest`/`JournalIdTest`/
 * `AccountIdTest` against ATS-007/ATS-004 (M4-T2, M2-T4, M3-T4).
 */
final class SourceFingerprintTest extends TestCase
{
    /**
     * A valid, already-derived opaque fingerprint is accepted.
     */
    public function test_valid_source_fingerprint_is_accepted(): void
    {
        $fingerprint = SourceFingerprint::of('01J8Z3K7QYUXG5N7EXAMPLE01');

        $this->assertInstanceOf(SourceFingerprint::class, $fingerprint);
    }

    /**
     * Exact string round-trip: the value returned by `toString()` is
     * exactly the value supplied to `of()`, unchanged.
     */
    public function test_exact_string_round_trip(): void
    {
        $fingerprint = SourceFingerprint::of('fp-0001');

        $this->assertSame('fp-0001', $fingerprint->toString());
    }

    /**
     * Value equality: two SourceFingerprint instances constructed
     * from the same value are equal.
     */
    public function test_same_value_is_equal(): void
    {
        $a = SourceFingerprint::of('fp-0001');
        $b = SourceFingerprint::of('fp-0001');

        $this->assertTrue($a->equals($b));
    }

    /**
     * Different values are not equal.
     */
    public function test_different_value_is_not_equal(): void
    {
        $a = SourceFingerprint::of('fp-0001');
        $b = SourceFingerprint::of('fp-0002');

        $this->assertFalse($a->equals($b));
    }

    /**
     * Equality is case-sensitive, with no implicit normalization.
     */
    public function test_equality_is_case_sensitive(): void
    {
        $a = SourceFingerprint::of('Fp-0001');
        $b = SourceFingerprint::of('fp-0001');

        $this->assertFalse($a->equals($b));
    }

    /**
     * Empty input is rejected.
     */
    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidSourceFingerprintException::class);

        SourceFingerprint::of('');
    }

    /**
     * Whitespace-only input is rejected.
     */
    public function test_whitespace_only_input_is_rejected(): void
    {
        $this->expectException(InvalidSourceFingerprintException::class);

        SourceFingerprint::of('   ');
    }

    /**
     * Leading whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_leading_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidSourceFingerprintException::class);

        SourceFingerprint::of(' fp-0001');
    }

    /**
     * Trailing whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_trailing_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidSourceFingerprintException::class);

        SourceFingerprint::of('fp-0001 ');
    }

    /**
     * A control character (here, a null byte) is rejected.
     */
    public function test_control_character_is_rejected(): void
    {
        $this->expectException(InvalidSourceFingerprintException::class);

        SourceFingerprint::of("fp-0001\0");
    }

    /**
     * A newline embedded in an otherwise plausible fingerprint is
     * rejected.
     */
    public function test_embedded_newline_is_rejected(): void
    {
        $this->expectException(InvalidSourceFingerprintException::class);

        SourceFingerprint::of("fp-0001\n");
    }

    /**
     * An adversarially long fingerprint is rejected via a bounded,
     * deterministic check.
     */
    public function test_adversarially_long_input_is_rejected(): void
    {
        $this->expectException(InvalidSourceFingerprintException::class);

        SourceFingerprint::of(str_repeat('1', 1000));
    }

    /**
     * A value at exactly the 64-character bound is accepted — the
     * bound rejects values *longer* than 64, not values of exactly 64.
     */
    public function test_value_at_exactly_the_length_bound_is_accepted(): void
    {
        $fingerprint = SourceFingerprint::of(str_repeat('a', 64));

        $this->assertSame(64, strlen($fingerprint->toString()));
    }

    /**
     * A value one character past the 64-character bound is rejected.
     */
    public function test_value_one_character_past_the_length_bound_is_rejected(): void
    {
        $this->expectException(InvalidSourceFingerprintException::class);

        SourceFingerprint::of(str_repeat('a', 65));
    }

    /**
     * SourceFingerprint is immutable: every property is readonly and
     * no public mutator method exists.
     */
    public function test_source_fingerprint_is_immutable(): void
    {
        $reflection = new ReflectionClass(SourceFingerprint::class);

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
        $reflection = new ReflectionClass(SourceFingerprint::class);

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
     * `equals` — no generation method and no derivation/hashing
     * method. Derivation and hashing are deliberately not this
     * class's concern (AETS-001, AETS-007 §6.2 both defer the
     * concrete derivation decision).
     */
    public function test_exposes_no_generation_or_derivation_method(): void
    {
        $reflection = new ReflectionClass(SourceFingerprint::class);

        $publicMethodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(['of', 'toString', 'equals'], $publicMethodNames);

        foreach (['derive', 'hash', 'generate', 'random', 'uuid', 'ulid', 'new', 'create', 'fromBankTransaction', 'fromReceipt', 'fromInvoice', 'fromPayload'] as $forbiddenMethodName) {
            $this->assertNotContains($forbiddenMethodName, $publicMethodNames);
        }
    }

    /**
     * No hashing/derivation performed by construction: `of()` stores
     * the exact supplied value, never a derived digest of it.
     */
    public function test_of_does_not_hash_or_derive_the_supplied_value(): void
    {
        $fingerprint = SourceFingerprint::of('plain-value-0001');

        $this->assertSame('plain-value-0001', $fingerprint->toString());
    }

    /**
     * Distinct from IdempotencyKey: the class's own docblock
     * legitimately *names* IdempotencyKey in prose, to explain that
     * SourceFingerprint is conceptually different and neither
     * substitutes for the other (AETS-001; AETS-007 §6.1–§6.2) —
     * mirroring the established pattern of
     * `AccountRepositoryTest::test_does_not_run_hierarchy_policy_on_a_single_read()`.
     * What must be absent is any actual coupling: an import, a static
     * call, an instantiation, or a shared base type — never a bare
     * mention of the word.
     */
    public function test_has_no_idempotency_key_coupling(): void
    {
        $reflection = new ReflectionClass(SourceFingerprint::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('use App\\Domain\\Accounting\\Posting\\IdempotencyKey', $source);
        $this->assertStringNotContainsString('IdempotencyKey::', $source);
        $this->assertStringNotContainsString('new IdempotencyKey', $source);
    }

    /**
     * SourceFingerprint and IdempotencyKey are different PHP types —
     * neither is an instance of the other, and neither extends,
     * implements, or is otherwise substitutable for the other through
     * a method signature.
     */
    public function test_source_fingerprint_and_idempotency_key_are_different_types(): void
    {
        $fingerprint = SourceFingerprint::of('fp-0001');
        $key = IdempotencyKey::of('fp-0001');

        $this->assertNotInstanceOf(IdempotencyKey::class, $fingerprint);
        $this->assertNotInstanceOf(SourceFingerprint::class, $key);

        $fingerprintReflection = new ReflectionClass(SourceFingerprint::class);
        $keyReflection = new ReflectionClass(IdempotencyKey::class);

        $this->assertFalse($fingerprintReflection->isSubclassOf(IdempotencyKey::class));
        $this->assertFalse($keyReflection->isSubclassOf(SourceFingerprint::class));
    }

    /**
     * Neither type can be substituted for the other through a method
     * signature: a parameter or return type declared as one is not
     * satisfied by the other at the type-declaration level (both are
     * `final`, unrelated classes with no shared interface).
     */
    public function test_types_are_not_substitutable_through_method_signatures(): void
    {
        $fingerprintReflection = new ReflectionClass(SourceFingerprint::class);
        $keyReflection = new ReflectionClass(IdempotencyKey::class);

        $this->assertTrue($fingerprintReflection->isFinal());
        $this->assertTrue($keyReflection->isFinal());
        $this->assertSame([], $fingerprintReflection->getInterfaceNames());
        $this->assertSame([], $keyReflection->getInterfaceNames());

        $equalsParameterType = $fingerprintReflection->getMethod('equals')->getParameters()[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $equalsParameterType);
        $this->assertSame(SourceFingerprint::class, $equalsParameterType->getName());
        $this->assertNotSame(IdempotencyKey::class, $equalsParameterType->getName());
    }

    /**
     * No Journal identity behavior: SourceFingerprint does not
     * identify, reference, or depend on JournalId — it identifies
     * source material, never a Journal (AETS-007 §6.2). The docblock
     * names `JournalId` in prose only; no import, static call, or
     * instantiation of it exists.
     */
    public function test_has_no_journal_identity_behavior(): void
    {
        $reflection = new ReflectionClass(SourceFingerprint::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('JournalId::', $source);
        $this->assertStringNotContainsString('new JournalId', $source);
    }

    /**
     * No Tenant dependency: SourceFingerprint has no knowledge of
     * TenantId — Tenant scoping is a future Posting Engine concern
     * (AETS-007 §6.2), not this Value Object's.
     */
    public function test_has_no_tenant_dependency(): void
    {
        $reflection = new ReflectionClass(SourceFingerprint::class);

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
     * No source-payload parsing logic: SourceFingerprint has no
     * knowledge of Bank Transaction, Receipt, or Invoice payload
     * structure — it wraps an opaque, already-derived string only.
     */
    public function test_has_no_source_payload_parsing_logic(): void
    {
        $reflection = new ReflectionClass(SourceFingerprint::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('BankTransaction::', $source);
        $this->assertStringNotContainsString('Receipt::', $source);
        $this->assertStringNotContainsString('Invoice::', $source);
        $this->assertStringNotContainsString('json_decode', $source);
        $this->assertStringNotContainsString('simplexml', $source);
    }

    /**
     * No database awareness: SourceFingerprint does not extend,
     * implement, or otherwise depend on any Eloquent or
     * database-related type.
     */
    public function test_has_no_database_awareness(): void
    {
        $reflection = new ReflectionClass(SourceFingerprint::class);

        $this->assertFalse($reflection->getParentClass());
        $this->assertSame([], $reflection->getInterfaceNames());
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionClass(SourceFingerprint::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    /**
     * No UUID/ULID or hashing vendor dependency: SourceFingerprint is
     * format-agnostic and does not import, reference, or depend on any
     * UUID/ULID library, and performs no hashing via a vendor hashing
     * library.
     */
    public function test_has_no_uuid_ulid_or_hashing_vendor_dependency(): void
    {
        $reflection = new ReflectionClass(SourceFingerprint::class);
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
