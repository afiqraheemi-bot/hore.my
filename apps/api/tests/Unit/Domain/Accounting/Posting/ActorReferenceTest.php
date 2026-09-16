<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Posting;

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\Exception\InvalidActorReferenceException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\SourceFingerprint;
use App\Domain\Accounting\Posting\SourceReference;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Covers ActorReference as far as AETS-001 and AETS-007 §8.1 define it
 * for a bare Value Object. ATS-007 (`POST-T032`, `POST-T033`,
 * `POST-T036`) covers Actor *behavior* within the Posting Command
 * pipeline — none of it exercises this Value Object's own
 * construction grammar in isolation. ATS-007 has no dedicated test ID
 * for ActorReference's own construction validation or its
 * no-authentication/session/authorization-API constraint — the same
 * traceability situation already established and accepted for
 * `IdempotencyKeyTest`/`SourceFingerprintTest` against ATS-007
 * (M4-T2, M4-T3).
 */
final class ActorReferenceTest extends TestCase
{
    /**
     * A valid, already-supplied opaque reference is accepted.
     */
    public function test_valid_actor_reference_is_accepted(): void
    {
        $reference = ActorReference::of('01J8Z3K7QYUXG5N7EXAMPLE01');

        $this->assertInstanceOf(ActorReference::class, $reference);
    }

    /**
     * Exact string round-trip: the value returned by `toString()` is
     * exactly the value supplied to `of()`, unchanged.
     */
    public function test_exact_string_round_trip(): void
    {
        $reference = ActorReference::of('actor-0001');

        $this->assertSame('actor-0001', $reference->toString());
    }

    /**
     * Value equality: two ActorReference instances constructed from
     * the same value are equal.
     */
    public function test_same_value_is_equal(): void
    {
        $a = ActorReference::of('actor-0001');
        $b = ActorReference::of('actor-0001');

        $this->assertTrue($a->equals($b));
    }

    /**
     * Different values are not equal.
     */
    public function test_different_value_is_not_equal(): void
    {
        $a = ActorReference::of('actor-0001');
        $b = ActorReference::of('actor-0002');

        $this->assertFalse($a->equals($b));
    }

    /**
     * Equality is case-sensitive, with no implicit normalization.
     */
    public function test_equality_is_case_sensitive(): void
    {
        $a = ActorReference::of('Actor-0001');
        $b = ActorReference::of('actor-0001');

        $this->assertFalse($a->equals($b));
    }

    /**
     * Empty input is rejected.
     */
    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidActorReferenceException::class);

        ActorReference::of('');
    }

    /**
     * Whitespace-only input is rejected.
     */
    public function test_whitespace_only_input_is_rejected(): void
    {
        $this->expectException(InvalidActorReferenceException::class);

        ActorReference::of('   ');
    }

    /**
     * Leading whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_leading_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidActorReferenceException::class);

        ActorReference::of(' actor-0001');
    }

    /**
     * Trailing whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_trailing_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidActorReferenceException::class);

        ActorReference::of('actor-0001 ');
    }

    /**
     * A control character (here, a null byte) is rejected.
     */
    public function test_control_character_is_rejected(): void
    {
        $this->expectException(InvalidActorReferenceException::class);

        ActorReference::of("actor-0001\0");
    }

    /**
     * A newline embedded in an otherwise plausible reference is
     * rejected.
     */
    public function test_embedded_newline_is_rejected(): void
    {
        $this->expectException(InvalidActorReferenceException::class);

        ActorReference::of("actor-0001\n");
    }

    /**
     * An adversarially long reference is rejected via a bounded,
     * deterministic check.
     */
    public function test_adversarially_long_input_is_rejected(): void
    {
        $this->expectException(InvalidActorReferenceException::class);

        ActorReference::of(str_repeat('1', 1000));
    }

    /**
     * A value at exactly the 64-character bound is accepted — the
     * bound rejects values *longer* than 64, not values of exactly 64.
     */
    public function test_value_at_exactly_the_length_bound_is_accepted(): void
    {
        $reference = ActorReference::of(str_repeat('a', 64));

        $this->assertSame(64, strlen($reference->toString()));
    }

    /**
     * A value one character past the 64-character bound is rejected.
     */
    public function test_value_one_character_past_the_length_bound_is_rejected(): void
    {
        $this->expectException(InvalidActorReferenceException::class);

        ActorReference::of(str_repeat('a', 65));
    }

    /**
     * ActorReference is immutable: every property is readonly and no
     * public mutator method exists.
     */
    public function test_actor_reference_is_immutable(): void
    {
        $reflection = new ReflectionClass(ActorReference::class);

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
        $reflection = new ReflectionClass(ActorReference::class);

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
     * `equals` — no authentication, session, role, authorization,
     * generation, or Tenant-resolution method. Accounting Core does
     * not authenticate or authorize an Actor (AETS-007 §8.1); it only
     * records which already-authorized reference accepted a command.
     */
    public function test_exposes_no_authentication_session_role_or_authorization_method(): void
    {
        $reflection = new ReflectionClass(ActorReference::class);

        $publicMethodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(['of', 'toString', 'equals'], $publicMethodNames);

        foreach ([
            'authenticate', 'authorize', 'isAuthorized', 'hasRole', 'role', 'roles',
            'session', 'permissions', 'can', 'tenant', 'tenantId', 'resolveTenant',
            'generate', 'random', 'uuid', 'ulid', 'new', 'create',
        ] as $forbiddenMethodName) {
            $this->assertNotContains($forbiddenMethodName, $publicMethodNames);
        }
    }

    /**
     * No Tenant dependency: ActorReference has no knowledge of
     * TenantId — Actor/Tenant consistency is a Posting Validation
     * Pipeline responsibility (AETS-007 §7, §14), never this Value
     * Object's.
     */
    public function test_has_no_tenant_dependency(): void
    {
        $reflection = new ReflectionClass(ActorReference::class);

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
     * established pattern it mirrors — mirroring
     * `AccountRepositoryTest::test_does_not_run_hierarchy_policy_on_a_single_read()`'s
     * established handling of this exact situation. What must be
     * absent is any actual *use*: an import, a static call, or an
     * instantiation.
     */
    public function test_has_no_idempotency_key_or_source_fingerprint_coupling(): void
    {
        $reflection = new ReflectionClass(ActorReference::class);
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
     * No database awareness: ActorReference does not extend,
     * implement, or otherwise depend on any Eloquent or
     * database-related type.
     */
    public function test_has_no_database_awareness(): void
    {
        $reflection = new ReflectionClass(ActorReference::class);

        $this->assertFalse($reflection->getParentClass());
        $this->assertSame([], $reflection->getInterfaceNames());
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionClass(ActorReference::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    /**
     * No UUID/ULID vendor dependency: ActorReference is
     * format-agnostic and does not import, reference, or depend on any
     * UUID/ULID library.
     */
    public function test_has_no_uuid_or_ulid_vendor_dependency(): void
    {
        $reflection = new ReflectionClass(ActorReference::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Ramsey\\', $source);
        $this->assertStringNotContainsString('Symfony\\Component\\Uid', $source);
        $this->assertStringNotContainsString('use function Str', $source);
    }

    /**
     * ActorReference and SourceReference are separate, unrelated
     * final classes — neither is a subclass of the other, and neither
     * shares an interface with the other.
     */
    public function test_actor_reference_and_source_reference_are_different_types(): void
    {
        $actorReflection = new ReflectionClass(ActorReference::class);
        $sourceReflection = new ReflectionClass(SourceReference::class);

        $this->assertTrue($actorReflection->isFinal());
        $this->assertTrue($sourceReflection->isFinal());
        $this->assertFalse($actorReflection->isSubclassOf(SourceReference::class));
        $this->assertFalse($sourceReflection->isSubclassOf(ActorReference::class));
        $this->assertSame([], $actorReflection->getInterfaceNames());
        $this->assertSame([], $sourceReflection->getInterfaceNames());

        $actor = ActorReference::of('shared-value-0001');
        $source = SourceReference::of('shared-value-0001');

        $this->assertNotInstanceOf(SourceReference::class, $actor);
        $this->assertNotInstanceOf(ActorReference::class, $source);
    }

    /**
     * ActorReference is also distinct from IdempotencyKey and
     * SourceFingerprint — no shared type, and `equals()` accepts only
     * its own class.
     */
    public function test_actor_reference_is_distinct_from_idempotency_key_and_source_fingerprint(): void
    {
        $reference = ActorReference::of('shared-value-0001');
        $key = IdempotencyKey::of('shared-value-0001');
        $fingerprint = SourceFingerprint::of('shared-value-0001');

        $this->assertNotInstanceOf(IdempotencyKey::class, $reference);
        $this->assertNotInstanceOf(SourceFingerprint::class, $reference);
        $this->assertNotInstanceOf(ActorReference::class, $key);
        $this->assertNotInstanceOf(ActorReference::class, $fingerprint);

        $equalsParameterType = (new ReflectionClass(ActorReference::class))
            ->getMethod('equals')->getParameters()[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $equalsParameterType);
        $resolvedTypeName = $equalsParameterType->getName() === 'self' ? ActorReference::class : $equalsParameterType->getName();
        $this->assertSame(ActorReference::class, $resolvedTypeName);
    }
}
