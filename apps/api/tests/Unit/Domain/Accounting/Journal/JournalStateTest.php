<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Journal;

use App\Domain\Accounting\Journal\JournalState;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;

/**
 * Covers the ATS-004 tests relevant to {@see JournalState} in
 * isolation (M3-T4) — the Journal lifecycle-state primitive itself,
 * not yet a Journal aggregate, which does not exist in this codebase
 * yet. `JRN-T015` ("a Journal records its current lifecycle state,
 * and only Draft and Posted are valid values") is exercised here at
 * the level this type can prove alone: that `Draft` and `Posted` each
 * exist as a valid case, and that no third value is even
 * representable. `JRN-T001` (a Draft Journal can be assembled),
 * `JRN-T023` (only a Draft Journal may transition to Posted), and
 * `JRN-T024` (re-posting an already-Posted Journal is rejected) are
 * behaviors of a future Journal aggregate and Posting Engine — this
 * enum deliberately encodes no transition logic for them to build on
 * yet.
 */
final class JournalStateTest extends TestCase
{
    /**
     * JRN-T015 (enum-level foundation): Draft case exists.
     */
    public function test_draft_case_exists(): void
    {
        $this->assertInstanceOf(JournalState::class, JournalState::Draft);
    }

    /**
     * JRN-T015 (enum-level foundation): Posted case exists.
     */
    public function test_posted_case_exists(): void
    {
        $this->assertInstanceOf(JournalState::class, JournalState::Posted);
    }

    /**
     * Exactly two JournalState cases — no more, no fewer. Proves no
     * additional lifecycle state (Pending, Approved, Processing,
     * Cancelled, Reversed, Replaced, Deleted, or otherwise) exists.
     */
    public function test_defines_exactly_two_cases(): void
    {
        $this->assertSame(
            [JournalState::Draft, JournalState::Posted],
            JournalState::cases(),
        );
    }

    /**
     * Reversal and Replacement are each a new Journal (AETS-004 §16,
     * §17), never a third JournalState case the original transitions
     * into — proven directly: no case with either name exists.
     */
    public function test_reversal_and_replacement_are_not_cases(): void
    {
        $caseNames = array_map(
            static fn (JournalState $case): string => $case->name,
            JournalState::cases(),
        );

        $this->assertNotContains('Reversed', $caseNames);
        $this->assertNotContains('Reversal', $caseNames);
        $this->assertNotContains('Replaced', $caseNames);
        $this->assertNotContains('Replacement', $caseNames);
    }

    /**
     * No persistence/backing value is introduced: the enum is
     * unbacked, so no arbitrary string/int value can be coerced into
     * one via `from()`/`tryFrom()` — those methods do not exist on an
     * unbacked enum at all (AETS-004 does not lock a persisted
     * representation).
     */
    public function test_unbacked_enum_admits_no_unsupported_value(): void
    {
        $reflection = new ReflectionEnum(JournalState::class);

        $this->assertFalse($reflection->isBacked());
        $this->assertFalse($reflection->hasMethod('from'));
        $this->assertFalse($reflection->hasMethod('tryFrom'));
    }

    /**
     * PHP enum cases are singletons: two references to the same
     * JournalState are always identical, never a copy.
     */
    public function test_case_identity_is_stable(): void
    {
        $a = JournalState::Draft;
        $b = JournalState::Draft;

        $this->assertSame($a, $b);
    }

    /**
     * No property beyond every PHP enum case's implicit `name`, no
     * method at all — in particular, no transition method (e.g. a
     * `post()`/`canTransitionTo()`) and no helper not directly
     * required by AETS-004 — no trait, and no interface beyond PHP's
     * own implicit `UnitEnum`. This is also the structural proof that
     * no Posted -> Draft (or any other) transition behavior exists at
     * this layer: there is no method here that could perform one.
     */
    public function test_enum_has_no_properties_methods_traits_or_interfaces(): void
    {
        $reflection = new ReflectionEnum(JournalState::class);

        $propertyNames = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            $reflection->getProperties(),
        );
        $this->assertSame(['name'], $propertyNames);

        $this->assertSame([], $reflection->getTraitNames());
        $this->assertSame(['UnitEnum'], $reflection->getInterfaceNames());

        $builtInEnumMethods = ['cases', 'from', 'tryFrom'];
        $ownMethods = array_filter(
            $reflection->getMethods(),
            static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === JournalState::class
                && ! in_array($method->getName(), $builtInEnumMethods, true),
        );
        $this->assertSame([], $ownMethods);
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionEnum(JournalState::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }
}
