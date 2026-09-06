<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Transactions\Transfer;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Transactions\Transfer\Exception\InvalidTransferIdException;
use App\Domain\Transactions\Transfer\TransferId;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Unit\Domain\Accounting\Journal\JournalIdTest;

/**
 * Covers Transfer identity (M14) as a bare Value Object, mirroring
 * {@see JournalIdTest}'s own coverage of the pattern this class
 * deliberately reuses.
 */
final class TransferIdTest extends TestCase
{
    public function test_valid_opaque_identifier_is_accepted(): void
    {
        $id = TransferId::of('01J8Z3K7QYUXG5N7EXAMPLE01');

        $this->assertInstanceOf(TransferId::class, $id);
    }

    public function test_exact_string_round_trip(): void
    {
        $id = TransferId::of('transfer-0001');

        $this->assertSame('transfer-0001', $id->toString());
    }

    public function test_same_value_is_equal(): void
    {
        $a = TransferId::of('transfer-0001');
        $b = TransferId::of('transfer-0001');

        $this->assertTrue($a->equals($b));
    }

    public function test_different_value_is_not_equal(): void
    {
        $a = TransferId::of('transfer-0001');
        $b = TransferId::of('transfer-0002');

        $this->assertFalse($a->equals($b));
    }

    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidTransferIdException::class);

        TransferId::of('');
    }

    public function test_whitespace_only_input_is_rejected(): void
    {
        $this->expectException(InvalidTransferIdException::class);

        TransferId::of('   ');
    }

    public function test_leading_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidTransferIdException::class);

        TransferId::of(' transfer-0001');
    }

    public function test_trailing_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidTransferIdException::class);

        TransferId::of('transfer-0001 ');
    }

    public function test_control_character_is_rejected(): void
    {
        $this->expectException(InvalidTransferIdException::class);

        TransferId::of("transfer-0001\0");
    }

    public function test_adversarially_long_input_is_rejected(): void
    {
        $this->expectException(InvalidTransferIdException::class);

        TransferId::of(str_repeat('1', 1000));
    }

    public function test_value_at_exactly_the_length_bound_is_accepted(): void
    {
        $id = TransferId::of(str_repeat('a', 64));

        $this->assertSame(64, strlen($id->toString()));
    }

    public function test_value_one_character_past_the_length_bound_is_rejected(): void
    {
        $this->expectException(InvalidTransferIdException::class);

        TransferId::of(str_repeat('a', 65));
    }

    public function test_transfer_id_is_immutable(): void
    {
        $reflection = new ReflectionClass(TransferId::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly());
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertStringStartsNotWith('set', $method->getName());
        }
    }

    public function test_exposes_no_generation_method(): void
    {
        $reflection = new ReflectionClass(TransferId::class);

        $publicMethodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(['of', 'toString', 'equals'], $publicMethodNames);
    }

    public function test_no_native_int_canonical_accessor(): void
    {
        $reflection = new ReflectionClass(TransferId::class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();

            if ($returnType instanceof ReflectionNamedType) {
                $this->assertNotSame('int', $returnType->getName());
            }
        }
    }

    public function test_is_a_distinct_type_from_journal_id(): void
    {
        $transferId = TransferId::of('shared-value-0001');
        $journalId = JournalId::of('shared-value-0001');

        $this->assertNotInstanceOf(JournalId::class, $transferId);
        $this->assertNotInstanceOf(TransferId::class, $journalId);
    }
}
