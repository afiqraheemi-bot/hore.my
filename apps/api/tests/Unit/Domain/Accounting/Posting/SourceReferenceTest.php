<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Posting;

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\Exception\InvalidSourceReferenceException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\SourceFingerprint;
use App\Domain\Accounting\Posting\SourceReference;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Covers SourceReference as far as AETS-004 §19 and AETS-007 §9.1
 * define it for a bare Value Object. ATS-007 (`POST-T034`–`POST-T036`)
 * covers Source *behavior* within the Posting Command pipeline — none
 * of it exercises this Value Object's own construction grammar in
 * isolation. ATS-007 has no dedicated test ID for SourceReference's
 * own construction validation or its no-parsing/classification-API
 * constraint — the same traceability situation already established
 * and accepted for `IdempotencyKeyTest`/`SourceFingerprintTest`/
 * `ActorReferenceTest` against ATS-007 (M4-T2, M4-T3, M4-T5).
 */
final class SourceReferenceTest extends TestCase
{
    /**
     * A valid, already-supplied opaque reference is accepted.
     */
    public function test_valid_source_reference_is_accepted(): void
    {
        $reference = SourceReference::of('01J8Z3K7QYUXG5N7EXAMPLE01');

        $this->assertInstanceOf(SourceReference::class, $reference);
    }

    /**
     * Exact string round-trip: the value returned by `toString()` is
     * exactly the value supplied to `of()`, unchanged.
     */
    public function test_exact_string_round_trip(): void
    {
        $reference = SourceReference::of('source-0001');

        $this->assertSame('source-0001', $reference->toString());
    }

    /**
     * Value equality: two SourceReference instances constructed from
     * the same value are equal.
     */
    public function test_same_value_is_equal(): void
    {
        $a = SourceReference::of('source-0001');
        $b = SourceReference::of('source-0001');

        $this->assertTrue($a->equals($b));
    }

    /**
     * Different values are not equal.
     */
    public function test_different_value_is_not_equal(): void
    {
        $a = SourceReference::of('source-0001');
        $b = SourceReference::of('source-0002');

        $this->assertFalse($a->equals($b));
    }

    /**
     * Equality is case-sensitive, with no implicit normalization.
     */
    public function test_equality_is_case_sensitive(): void
    {
        $a = SourceReference::of('Source-0001');
        $b = SourceReference::of('source-0001');

        $this->assertFalse($a->equals($b));
    }

    /**
     * Empty input is rejected.
     */
    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidSourceReferenceException::class);

        SourceReference::of('');
    }

    /**
     * Whitespace-only input is rejected.
     */
    public function test_whitespace_only_input_is_rejected(): void
    {
        $this->expectException(InvalidSourceReferenceException::class);

        SourceReference::of('   ');
    }

    /**
     * Leading whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_leading_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidSourceReferenceException::class);

        SourceReference::of(' source-0001');
    }

    /**
     * Trailing whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_trailing_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidSourceReferenceException::class);

        SourceReference::of('source-0001 ');
    }

    /**
     * A control character (here, a null byte) is rejected.
     */
    public function test_control_character_is_rejected(): void
    {
        $this->expectException(InvalidSourceReferenceException::class);

        SourceReference::of("source-0001\0");
    }

    /**
     * A newline embedded in an otherwise plausible reference is
     * rejected.
     */
    public function test_embedded_newline_is_rejected(): void
    {
        $this->expectException(InvalidSourceReferenceException::class);

        SourceReference::of("source-0001\n");
    }

    /**
     * An adversarially long reference is rejected via a bounded,
     * deterministic check.
     */
    public function test_adversarially_long_input_is_rejected(): void
    {
        $this->expectException(InvalidSourceReferenceException::class);

        SourceReference::of(str_repeat('1', 1000));
    }

    /**
     * A value at exactly the 64-character bound is accepted — the
     * bound rejects values *longer* than 64, not values of exactly 64.
     */
    public function test_value_at_exactly_the_length_bound_is_accepted(): void
    {
        $reference = SourceReference::of(str_repeat('a', 64));

        $this->assertSame(64, strlen($reference->toString()));
    }

    /**
     * A value one character past the 64-character bound is rejected.
     */
    public function test_value_one_character_past_the_length_bound_is_rejected(): void
    {
        $this->expectException(InvalidSourceReferenceException::class);

        SourceReference::of(str_repeat('a', 65));
    }

    /**
     * SourceReference is immutable: every property is readonly and no
     * public mutator method exists.
     */
    public function test_source_reference_is_immutable(): void
    {
        $reflection = new ReflectionClass(SourceReference::class);

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
        $reflection = new ReflectionClass(SourceReference::class);

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
     * `equals` — no method that parses, interprets, or classifies
     * what the reference points to (a directly authored command, a
     * confirmed AI proposal, or a correction reference). That
     * discrimination belongs to whichever future component actually
     * resolves the reference (AETS-010's own eventual design), never
     * to this class (AETS-007 §9.1).
     */
    public function test_exposes_no_parsing_or_classification_method(): void
    {
        $reflection = new ReflectionClass(SourceReference::class);

        $publicMethodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(['of', 'toString', 'equals'], $publicMethodNames);

        foreach ([
            'kind', 'type', 'classify', 'isCommand', 'isProposal', 'isCorrection',
            'parse', 'resolve', 'generate', 'random', 'uuid', 'ulid', 'new', 'create',
        ] as $forbiddenMethodName) {
            $this->assertNotContains($forbiddenMethodName, $publicMethodNames);
        }
    }

    /**
     * No Tenant dependency: SourceReference has no knowledge of
     * TenantId.
     */
    public function test_has_no_tenant_dependency(): void
    {
        $reflection = new ReflectionClass(SourceReference::class);

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
     * No IdempotencyKey/SourceFingerprint coupling: the class's own
     * docblock legitimately *names* both in prose, to cite the
     * established pattern it mirrors. What must be absent is any
     * actual *use*: an import, a static call, or an instantiation.
     */
    public function test_has_no_idempotency_key_or_source_fingerprint_coupling(): void
    {
        $reflection = new ReflectionClass(SourceReference::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('use App\\Domain\\Accounting\\Posting\\IdempotencyKey', $source);
        $this->assertStringNotContainsString('use App\\Domain\\Accounting\\Posting\\SourceFingerprint', $source);
        $this->assertStringNotContainsString('IdempotencyKey::', $source);
        $this->assertStringNotContainsString('SourceFingerprint::', $source);
        $this->assertStringNotContainsString('new IdempotencyKey', $source);
        $this->assertStringNotContainsString('new SourceFingerprint', $source);
    }

    /**
     * No database awareness: SourceReference does not extend,
     * implement, or otherwise depend on any Eloquent or
     * database-related type.
     */
    public function test_has_no_database_awareness(): void
    {
        $reflection = new ReflectionClass(SourceReference::class);

        $this->assertFalse($reflection->getParentClass());
        $this->assertSame([], $reflection->getInterfaceNames());
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionClass(SourceReference::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    /**
     * No UUID/ULID vendor dependency: SourceReference is
     * format-agnostic and does not import, reference, or depend on any
     * UUID/ULID library.
     */
    public function test_has_no_uuid_or_ulid_vendor_dependency(): void
    {
        $reflection = new ReflectionClass(SourceReference::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Ramsey\\', $source);
        $this->assertStringNotContainsString('Symfony\\Component\\Uid', $source);
        $this->assertStringNotContainsString('use function Str', $source);
    }

    /**
     * SourceReference and ActorReference are separate, unrelated
     * final classes — neither is a subclass of the other, and neither
     * shares an interface with the other.
     */
    public function test_source_reference_and_actor_reference_are_different_types(): void
    {
        $sourceReflection = new ReflectionClass(SourceReference::class);
        $actorReflection = new ReflectionClass(ActorReference::class);

        $this->assertTrue($sourceReflection->isFinal());
        $this->assertTrue($actorReflection->isFinal());
        $this->assertFalse($sourceReflection->isSubclassOf(ActorReference::class));
        $this->assertFalse($actorReflection->isSubclassOf(SourceReference::class));

        $source = SourceReference::of('shared-value-0001');
        $actor = ActorReference::of('shared-value-0001');

        $this->assertNotInstanceOf(ActorReference::class, $source);
        $this->assertNotInstanceOf(SourceReference::class, $actor);
    }

    /**
     * SourceReference is also distinct from IdempotencyKey and
     * SourceFingerprint — no shared type, and `equals()` accepts only
     * its own class.
     */
    public function test_source_reference_is_distinct_from_idempotency_key_and_source_fingerprint(): void
    {
        $reference = SourceReference::of('shared-value-0001');
        $key = IdempotencyKey::of('shared-value-0001');
        $fingerprint = SourceFingerprint::of('shared-value-0001');

        $this->assertNotInstanceOf(IdempotencyKey::class, $reference);
        $this->assertNotInstanceOf(SourceFingerprint::class, $reference);
        $this->assertNotInstanceOf(SourceReference::class, $key);
        $this->assertNotInstanceOf(SourceReference::class, $fingerprint);

        $equalsParameterType = (new ReflectionClass(SourceReference::class))
            ->getMethod('equals')->getParameters()[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $equalsParameterType);
        $this->assertSame(SourceReference::class, $equalsParameterType->getName());
    }
}
