<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Journal;

use App\Domain\Accounting\Journal\Exception\InvalidJournalIdException;
use App\Domain\Accounting\Journal\JournalId;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Unit\Domain\Accounting\ChartOfAccounts\AccountIdTest;

/**
 * Covers Journal identity as far as AETS-004 §6 and ATS-004 define it
 * for a bare Value Object. ATS-004 has exactly one identifier-specific
 * test ID, `JRN-T014` ("Every Journal is assigned a stable, opaque
 * identifier at creation, immutable for its lifetime") — a Journal-
 * level test this suite only partially proxies, since the Journal
 * aggregate itself does not exist yet. Mirrors the same traceability
 * situation already established for {@see AccountIdTest}
 * (M2-T4): ATS-004 has no dedicated test ID for JournalId's own
 * construction validation, its no-generation-API constraint, or its
 * no-database-semantics constraint.
 */
final class JournalIdTest extends TestCase
{
    /**
     * A valid, already-generated opaque identifier is accepted.
     */
    public function test_valid_opaque_identifier_is_accepted(): void
    {
        $id = JournalId::of('01J8Z3K7QYUXG5N7EXAMPLE01');

        $this->assertInstanceOf(JournalId::class, $id);
    }

    /**
     * Exact string round-trip: the value returned by `toString()` is
     * exactly the value supplied to `of()`, unchanged.
     */
    public function test_exact_string_round_trip(): void
    {
        $id = JournalId::of('journal-0001');

        $this->assertSame('journal-0001', $id->toString());
    }

    /**
     * Value equality: two JournalId instances constructed from the
     * same value are equal.
     */
    public function test_same_value_is_equal(): void
    {
        $a = JournalId::of('journal-0001');
        $b = JournalId::of('journal-0001');

        $this->assertTrue($a->equals($b));
    }

    /**
     * Different values are not equal.
     */
    public function test_different_value_is_not_equal(): void
    {
        $a = JournalId::of('journal-0001');
        $b = JournalId::of('journal-0002');

        $this->assertFalse($a->equals($b));
    }

    /**
     * Empty input is rejected.
     */
    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidJournalIdException::class);

        JournalId::of('');
    }

    /**
     * Whitespace-only input is rejected.
     */
    public function test_whitespace_only_input_is_rejected(): void
    {
        $this->expectException(InvalidJournalIdException::class);

        JournalId::of('   ');
    }

    /**
     * Leading whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_leading_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidJournalIdException::class);

        JournalId::of(' journal-0001');
    }

    /**
     * Trailing whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_trailing_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidJournalIdException::class);

        JournalId::of('journal-0001 ');
    }

    /**
     * A control character (here, a null byte) is rejected.
     */
    public function test_control_character_is_rejected(): void
    {
        $this->expectException(InvalidJournalIdException::class);

        JournalId::of("journal-0001\0");
    }

    /**
     * A newline embedded in an otherwise plausible identifier is
     * rejected.
     */
    public function test_embedded_newline_is_rejected(): void
    {
        $this->expectException(InvalidJournalIdException::class);

        JournalId::of("journal-0001\n");
    }

    /**
     * An adversarially long identifier is rejected via a bounded,
     * deterministic check.
     */
    public function test_adversarially_long_input_is_rejected(): void
    {
        $this->expectException(InvalidJournalIdException::class);

        JournalId::of(str_repeat('1', 1000));
    }

    /**
     * A value at exactly the 64-character bound is accepted — the
     * bound rejects values *longer* than 64, not values of exactly 64.
     */
    public function test_value_at_exactly_the_length_bound_is_accepted(): void
    {
        $id = JournalId::of(str_repeat('a', 64));

        $this->assertSame(64, strlen($id->toString()));
    }

    /**
     * A value one character past the 64-character bound is rejected.
     */
    public function test_value_one_character_past_the_length_bound_is_rejected(): void
    {
        $this->expectException(InvalidJournalIdException::class);

        JournalId::of(str_repeat('a', 65));
    }

    /**
     * JournalId is immutable: every property is readonly and no
     * public mutator method exists.
     */
    public function test_journal_id_is_immutable(): void
    {
        $reflection = new ReflectionClass(JournalId::class);

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
     * mirroring AccountId's own equivalent test for this same concern.
     */
    public function test_no_native_int_canonical_accessor(): void
    {
        $reflection = new ReflectionClass(JournalId::class);

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
     * No generation API: JournalId exposes no method that produces a
     * new identifier value — only construction from an
     * already-generated string, exact-string output, and equality.
     * Generation is deliberately not this class's concern (AETS-004
     * §6 treats the concrete representation as an implementation
     * detail this Value Object does not decide).
     */
    public function test_exposes_no_generation_method(): void
    {
        $reflection = new ReflectionClass(JournalId::class);

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
     * No database awareness: JournalId does not extend, implement, or
     * otherwise depend on any Eloquent or database-related type.
     */
    public function test_has_no_database_awareness(): void
    {
        $reflection = new ReflectionClass(JournalId::class);

        $this->assertFalse($reflection->getParentClass());
        $this->assertSame([], $reflection->getInterfaceNames());
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionClass(JournalId::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    /**
     * No UUID/ULID vendor dependency: JournalId is format-agnostic and
     * does not import, reference, or depend on any UUID/ULID library.
     */
    public function test_has_no_uuid_or_ulid_vendor_dependency(): void
    {
        $reflection = new ReflectionClass(JournalId::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Ramsey\\', $source);
        $this->assertStringNotContainsString('Symfony\\Component\\Uid', $source);
        $this->assertStringNotContainsString('use function Str', $source);
    }
}
