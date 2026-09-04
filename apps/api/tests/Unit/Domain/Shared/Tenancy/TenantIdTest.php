<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Tenancy;

use App\Domain\Shared\Tenancy\Exception\InvalidTenantIdException;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Unit\Domain\Accounting\ChartOfAccounts\AccountIdTest;

/**
 * Covers TenantId with the same rigor as
 * {@see AccountIdTest}
 * — the two Value Objects share an identical contract and reasoning
 * for the same class of concern (an already-generated, format-agnostic
 * opaque identifier).
 */
final class TenantIdTest extends TestCase
{
    /**
     * A valid, already-generated opaque identifier is accepted.
     */
    public function test_valid_opaque_identifier_is_accepted(): void
    {
        $id = TenantId::of('01J8Z3K7QYUXG5N7EXAMPLE01');

        $this->assertInstanceOf(TenantId::class, $id);
    }

    /**
     * Exact string round-trip: the value returned by `toString()` is
     * exactly the value supplied to `of()`, unchanged.
     */
    public function test_exact_string_round_trip(): void
    {
        $id = TenantId::of('tenant-0001');

        $this->assertSame('tenant-0001', $id->toString());
    }

    /**
     * Value equality: two TenantId instances constructed from the
     * same value are equal.
     */
    public function test_same_value_is_equal(): void
    {
        $a = TenantId::of('tenant-0001');
        $b = TenantId::of('tenant-0001');

        $this->assertTrue($a->equals($b));
    }

    /**
     * Different values are not equal.
     */
    public function test_different_value_is_not_equal(): void
    {
        $a = TenantId::of('tenant-0001');
        $b = TenantId::of('tenant-0002');

        $this->assertFalse($a->equals($b));
    }

    /**
     * Empty input is rejected.
     */
    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidTenantIdException::class);

        TenantId::of('');
    }

    /**
     * Whitespace-only input is rejected.
     */
    public function test_whitespace_only_input_is_rejected(): void
    {
        $this->expectException(InvalidTenantIdException::class);

        TenantId::of('   ');
    }

    /**
     * Leading whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_leading_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidTenantIdException::class);

        TenantId::of(' tenant-0001');
    }

    /**
     * Trailing whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_trailing_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidTenantIdException::class);

        TenantId::of('tenant-0001 ');
    }

    /**
     * A control character (here, a null byte) is rejected.
     */
    public function test_control_character_is_rejected(): void
    {
        $this->expectException(InvalidTenantIdException::class);

        TenantId::of("tenant-0001\0");
    }

    /**
     * A newline embedded in an otherwise plausible identifier is
     * rejected.
     */
    public function test_embedded_newline_is_rejected(): void
    {
        $this->expectException(InvalidTenantIdException::class);

        TenantId::of("tenant-0001\n");
    }

    /**
     * An adversarially long identifier is rejected via a bounded,
     * deterministic check.
     */
    public function test_adversarially_long_input_is_rejected(): void
    {
        $this->expectException(InvalidTenantIdException::class);

        TenantId::of(str_repeat('1', 1000));
    }

    /**
     * TenantId is immutable: every property is readonly and no public
     * mutator method exists.
     */
    public function test_tenant_id_is_immutable(): void
    {
        $reflection = new ReflectionClass(TenantId::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                sprintf('Property "%s" must be readonly.', $property->getName()),
            );
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
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
        $reflection = new ReflectionClass(TenantId::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();

            if ($returnType instanceof \ReflectionNamedType) {
                $this->assertNotSame(
                    'int',
                    $returnType->getName(),
                    sprintf('Public method "%s" must not return a native int.', $method->getName()),
                );
            }
        }
    }

    /**
     * No generation API: TenantId exposes no method that produces a
     * new identifier value — only construction from an
     * already-generated string, exact-string output, and equality.
     */
    public function test_exposes_no_generation_method(): void
    {
        $reflection = new ReflectionClass(TenantId::class);

        $publicMethodNames = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(['of', 'toString', 'equals'], $publicMethodNames);

        foreach (['generate', 'random', 'uuid', 'ulid', 'new', 'create'] as $forbiddenMethodName) {
            $this->assertNotContains($forbiddenMethodName, $publicMethodNames);
        }
    }

    /**
     * No database awareness: TenantId does not extend, implement, or
     * otherwise depend on any Eloquent or database-related type.
     */
    public function test_has_no_database_awareness(): void
    {
        $reflection = new ReflectionClass(TenantId::class);

        $this->assertFalse($reflection->getParentClass());
        $this->assertSame([], $reflection->getInterfaceNames());
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionClass(TenantId::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    /**
     * No UUID/ULID vendor dependency: TenantId is format-agnostic and
     * does not import, reference, or depend on any UUID/ULID library.
     */
    public function test_has_no_uuid_or_ulid_vendor_dependency(): void
    {
        $reflection = new ReflectionClass(TenantId::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Ramsey\\', $source);
        $this->assertStringNotContainsString('Symfony\\Component\\Uid', $source);
        $this->assertStringNotContainsString('use function Str', $source);
    }
}
