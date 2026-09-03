<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Money;

use App\Domain\Accounting\Money\RoundingMode;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;

/**
 * Covers RoundingMode's minimal member set (M1-T4): only `Unnecessary`
 * is defined, since AETS-003 §13 does not name any other mode as
 * currently required. Immutability and type safety are PHP language
 * guarantees for enums (no properties, no mutation possible, singleton
 * instances per case) — these tests confirm the intended minimal
 * member set and that no vendor type is reachable from it, rather than
 * proving something the language already guarantees unconditionally.
 */
final class RoundingModeTest extends TestCase
{
    /**
     * Only the one currently-required mode is defined — no mode is
     * added in anticipation of future business rounding policy.
     */
    public function test_only_unnecessary_is_defined(): void
    {
        $this->assertSame([RoundingMode::Unnecessary], RoundingMode::cases());
    }

    /**
     * PHP enum cases are singletons: two references to the same case
     * are always identical, never merely equal.
     */
    public function test_case_identity_is_stable(): void
    {
        $a = RoundingMode::Unnecessary;
        $b = RoundingMode::Unnecessary;

        $this->assertSame($a, $b);
    }

    /**
     * The enum itself is unbacked (no scalar value to leak) and
     * declares no properties or public methods beyond the `name`
     * property every PHP enum case has implicitly — nothing for a
     * vendor type to attach to.
     */
    public function test_enum_has_no_properties_or_extra_public_methods(): void
    {
        $reflection = new ReflectionEnum(RoundingMode::class);

        $this->assertFalse($reflection->isBacked());

        $propertyNames = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            $reflection->getProperties(),
        );
        $this->assertSame(['name'], $propertyNames);

        $builtInEnumMethods = ['cases', 'from', 'tryFrom'];
        $ownMethods = array_filter(
            $reflection->getMethods(),
            static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === RoundingMode::class
                && ! in_array($method->getName(), $builtInEnumMethods, true),
        );
        $this->assertSame([], $ownMethods);
    }
}
