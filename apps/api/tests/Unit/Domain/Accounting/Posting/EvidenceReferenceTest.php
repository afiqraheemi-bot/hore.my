<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Posting;

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Accounting\Posting\Exception\InvalidEvidenceReferenceException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\SourceFingerprint;
use App\Domain\Accounting\Posting\SourceReference;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Covers EvidenceReference as far as AETS-004 §19 and AETS-010 §8
 * define it for a bare Value Object, mirroring
 * {@see SourceReferenceTest}'s own coverage of the M4 Value Object this
 * class is a deliberate sibling of.
 */
final class EvidenceReferenceTest extends TestCase
{
    public function test_valid_evidence_reference_is_accepted(): void
    {
        $reference = EvidenceReference::of('01J8Z3K7QYUXG5N7EXAMPLE01');

        $this->assertInstanceOf(EvidenceReference::class, $reference);
    }

    public function test_exact_string_round_trip(): void
    {
        $reference = EvidenceReference::of('evidence-0001');

        $this->assertSame('evidence-0001', $reference->toString());
    }

    public function test_same_value_is_equal(): void
    {
        $a = EvidenceReference::of('evidence-0001');
        $b = EvidenceReference::of('evidence-0001');

        $this->assertTrue($a->equals($b));
    }

    public function test_different_value_is_not_equal(): void
    {
        $a = EvidenceReference::of('evidence-0001');
        $b = EvidenceReference::of('evidence-0002');

        $this->assertFalse($a->equals($b));
    }

    public function test_equality_is_case_sensitive(): void
    {
        $a = EvidenceReference::of('Evidence-0001');
        $b = EvidenceReference::of('evidence-0001');

        $this->assertFalse($a->equals($b));
    }

    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidEvidenceReferenceException::class);

        EvidenceReference::of('');
    }

    public function test_whitespace_only_input_is_rejected(): void
    {
        $this->expectException(InvalidEvidenceReferenceException::class);

        EvidenceReference::of('   ');
    }

    public function test_leading_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidEvidenceReferenceException::class);

        EvidenceReference::of(' evidence-0001');
    }

    public function test_trailing_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidEvidenceReferenceException::class);

        EvidenceReference::of('evidence-0001 ');
    }

    public function test_control_character_is_rejected(): void
    {
        $this->expectException(InvalidEvidenceReferenceException::class);

        EvidenceReference::of("evidence-0001\0");
    }

    public function test_embedded_newline_is_rejected(): void
    {
        $this->expectException(InvalidEvidenceReferenceException::class);

        EvidenceReference::of("evidence-0001\n");
    }

    public function test_adversarially_long_input_is_rejected(): void
    {
        $this->expectException(InvalidEvidenceReferenceException::class);

        EvidenceReference::of(str_repeat('1', 1000));
    }

    public function test_value_at_exactly_the_length_bound_is_accepted(): void
    {
        $reference = EvidenceReference::of(str_repeat('a', 64));

        $this->assertSame(64, strlen($reference->toString()));
    }

    public function test_value_one_character_past_the_length_bound_is_rejected(): void
    {
        $this->expectException(InvalidEvidenceReferenceException::class);

        EvidenceReference::of(str_repeat('a', 65));
    }

    public function test_evidence_reference_is_immutable(): void
    {
        $reflection = new ReflectionClass(EvidenceReference::class);

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
     * Exactly the intended public API exists: `of`, `toString`,
     * `equals` — no method that parses, interprets, or classifies what
     * the reference points to (AETS-010 §8).
     */
    public function test_exposes_no_parsing_or_classification_method(): void
    {
        $reflection = new ReflectionClass(EvidenceReference::class);

        $publicMethodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(['of', 'toString', 'equals'], $publicMethodNames);
    }

    public function test_has_no_tenant_dependency(): void
    {
        $reflection = new ReflectionClass(EvidenceReference::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('TenantId', $source);
    }

    public function test_has_no_database_awareness(): void
    {
        $reflection = new ReflectionClass(EvidenceReference::class);

        $this->assertFalse($reflection->getParentClass());
        $this->assertSame([], $reflection->getInterfaceNames());
    }

    /**
     * EvidenceReference, ActorReference, and SourceReference are
     * separate, unrelated final classes.
     */
    public function test_evidence_reference_is_a_distinct_type_from_actor_and_source_reference(): void
    {
        $evidenceReflection = new ReflectionClass(EvidenceReference::class);

        $this->assertTrue($evidenceReflection->isFinal());
        $this->assertFalse($evidenceReflection->isSubclassOf(ActorReference::class));
        $this->assertFalse($evidenceReflection->isSubclassOf(SourceReference::class));

        $evidence = EvidenceReference::of('shared-value-0001');
        $actor = ActorReference::of('shared-value-0001');
        $source = SourceReference::of('shared-value-0001');

        $this->assertNotInstanceOf(ActorReference::class, $evidence);
        $this->assertNotInstanceOf(SourceReference::class, $evidence);
        $this->assertNotInstanceOf(EvidenceReference::class, $actor);
        $this->assertNotInstanceOf(EvidenceReference::class, $source);
    }

    /**
     * EvidenceReference is also distinct from IdempotencyKey and
     * SourceFingerprint — no shared type, and `equals()` accepts only
     * its own class.
     */
    public function test_evidence_reference_is_distinct_from_idempotency_key_and_source_fingerprint(): void
    {
        $reference = EvidenceReference::of('shared-value-0001');
        $key = IdempotencyKey::of('shared-value-0001');
        $fingerprint = SourceFingerprint::of('shared-value-0001');

        $this->assertNotInstanceOf(IdempotencyKey::class, $reference);
        $this->assertNotInstanceOf(SourceFingerprint::class, $reference);
        $this->assertNotInstanceOf(EvidenceReference::class, $key);
        $this->assertNotInstanceOf(EvidenceReference::class, $fingerprint);

        $equalsParameterType = (new ReflectionClass(EvidenceReference::class))
            ->getMethod('equals')->getParameters()[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $equalsParameterType);
        $resolvedTypeName = $equalsParameterType->getName() === 'self' ? EvidenceReference::class : $equalsParameterType->getName();
        $this->assertSame(EvidenceReference::class, $resolvedTypeName);
    }
}
