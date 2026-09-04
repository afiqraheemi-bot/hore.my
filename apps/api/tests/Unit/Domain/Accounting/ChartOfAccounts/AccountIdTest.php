<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountIdException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers Account identity as far as AETS-005 §7 and ATS-005 define it
 * for a bare Value Object. ATS-005 has exactly one identifier-specific
 * test ID, `COA-T002` ("Every Account is assigned a stable, opaque
 * identifier at creation, immutable for its lifetime") — an
 * Account-level test this suite only partially proxies, since Account
 * itself does not exist yet (see M2-T4's report for the full
 * traceability-gap list: ATS-005 has no dedicated test ID for
 * AccountId's own construction validation, its no-generation-API
 * constraint, or its no-database-semantics constraint).
 */
final class AccountIdTest extends TestCase
{
    /**
     * A valid, already-generated opaque identifier is accepted.
     */
    public function test_valid_opaque_identifier_is_accepted(): void
    {
        $id = AccountId::of('01J8Z3K7QYUXG5N7EXAMPLE01');

        $this->assertInstanceOf(AccountId::class, $id);
    }

    /**
     * Exact string round-trip: the value returned by `toString()` is
     * exactly the value supplied to `of()`, unchanged.
     */
    public function test_exact_string_round_trip(): void
    {
        $id = AccountId::of('account-0001');

        $this->assertSame('account-0001', $id->toString());
    }

    /**
     * Value equality: two AccountId instances constructed from the
     * same value are equal.
     */
    public function test_same_value_is_equal(): void
    {
        $a = AccountId::of('account-0001');
        $b = AccountId::of('account-0001');

        $this->assertTrue($a->equals($b));
    }

    /**
     * Different values are not equal.
     */
    public function test_different_value_is_not_equal(): void
    {
        $a = AccountId::of('account-0001');
        $b = AccountId::of('account-0002');

        $this->assertFalse($a->equals($b));
    }

    /**
     * Empty input is rejected.
     */
    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidAccountIdException::class);

        AccountId::of('');
    }

    /**
     * Whitespace-only input is rejected.
     */
    public function test_whitespace_only_input_is_rejected(): void
    {
        $this->expectException(InvalidAccountIdException::class);

        AccountId::of('   ');
    }

    /**
     * Leading whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_leading_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidAccountIdException::class);

        AccountId::of(' account-0001');
    }

    /**
     * Trailing whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_trailing_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidAccountIdException::class);

        AccountId::of('account-0001 ');
    }

    /**
     * A control character (here, a null byte) is rejected.
     */
    public function test_control_character_is_rejected(): void
    {
        $this->expectException(InvalidAccountIdException::class);

        AccountId::of("account-0001\0");
    }

    /**
     * A newline embedded in an otherwise plausible identifier is
     * rejected.
     */
    public function test_embedded_newline_is_rejected(): void
    {
        $this->expectException(InvalidAccountIdException::class);

        AccountId::of("account-0001\n");
    }

    /**
     * An adversarially long identifier is rejected via a bounded,
     * deterministic check.
     */
    public function test_adversarially_long_input_is_rejected(): void
    {
        $this->expectException(InvalidAccountIdException::class);

        AccountId::of(str_repeat('1', 1000));
    }

    /**
     * AccountId is immutable: every property is readonly and no
     * public mutator method exists.
     */
    public function test_account_id_is_immutable(): void
    {
        $reflection = new ReflectionClass(AccountId::class);

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
     * native PHP int as the canonical representation of the value,
     * mirroring Money's own MinorUnits/MON-014 pattern and AccountCode's
     * own equivalent test for this same concern.
     */
    public function test_no_native_int_canonical_accessor(): void
    {
        $reflection = new ReflectionClass(AccountId::class);

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
     * No generation API: AccountId exposes no method that produces a
     * new identifier value — only construction from an
     * already-generated string, exact-string output, and equality.
     * Generation is deliberately not this class's concern (AETS-005
     * §7 treats the concrete representation as an implementation
     * detail this Value Object does not decide).
     */
    public function test_exposes_no_generation_method(): void
    {
        $reflection = new ReflectionClass(AccountId::class);

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
     * No database awareness: AccountId does not extend, implement, or
     * otherwise depend on any Eloquent or database-related type.
     */
    public function test_has_no_database_awareness(): void
    {
        $reflection = new ReflectionClass(AccountId::class);

        $this->assertFalse($reflection->getParentClass());
        $this->assertSame([], $reflection->getInterfaceNames());
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionClass(AccountId::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    /**
     * No UUID/ULID vendor dependency: AccountId is format-agnostic and
     * does not import, reference, or depend on any UUID/ULID library.
     */
    public function test_has_no_uuid_or_ulid_vendor_dependency(): void
    {
        $reflection = new ReflectionClass(AccountId::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Ramsey\\', $source);
        $this->assertStringNotContainsString('Symfony\\Component\\Uid', $source);
        $this->assertStringNotContainsString('use function Str', $source);
    }
}
