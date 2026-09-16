<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Evidence;

use App\Domain\Banking\BankAccountId;
use App\Domain\Evidence\EvidenceId;
use App\Domain\Evidence\Exception\InvalidEvidenceIdException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Unit\Domain\Banking\BankAccountIdTest;

/**
 * Covers Evidence identity (AETS-015 §4) as a bare Value Object,
 * mirroring every other opaque identifier's own test coverage in this
 * codebase (e.g. {@see BankAccountIdTest}).
 */
final class EvidenceIdTest extends TestCase
{
    public function test_exact_string_round_trip(): void
    {
        $id = EvidenceId::of('evidence-0001');

        $this->assertSame('evidence-0001', $id->toString());
    }

    public function test_same_value_is_equal(): void
    {
        $this->assertTrue(EvidenceId::of('x')->equals(EvidenceId::of('x')));
    }

    public function test_different_value_is_not_equal(): void
    {
        $this->assertFalse(EvidenceId::of('x')->equals(EvidenceId::of('y')));
    }

    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidEvidenceIdException::class);

        EvidenceId::of('');
    }

    public function test_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidEvidenceIdException::class);

        EvidenceId::of(' x ');
    }

    public function test_control_character_is_rejected(): void
    {
        $this->expectException(InvalidEvidenceIdException::class);

        EvidenceId::of("x\0");
    }

    public function test_adversarially_long_input_is_rejected(): void
    {
        $this->expectException(InvalidEvidenceIdException::class);

        EvidenceId::of(str_repeat('a', 65));
    }

    public function test_evidence_id_is_immutable(): void
    {
        $reflection = new ReflectionClass(EvidenceId::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly());
        }
    }

    public function test_is_a_distinct_type_from_bank_account_id(): void
    {
        $evidenceId = EvidenceId::of('shared-value');
        $bankAccountId = BankAccountId::of('shared-value');

        $this->assertNotInstanceOf(BankAccountId::class, $evidenceId);
        $this->assertNotInstanceOf(EvidenceId::class, $bankAccountId);
    }

    public function test_exposes_no_generation_method(): void
    {
        $reflection = new ReflectionClass(EvidenceId::class);

        $publicMethodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(['of', 'toString', 'equals'], $publicMethodNames);
    }
}
