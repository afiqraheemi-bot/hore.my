<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Accounting\Money;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Exception\InvalidCurrencyException;
use App\Domain\Accounting\Money\Exception\InvalidMinorUnitsException;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Infrastructure\Accounting\Money\Exception\MoneyOutOfPersistenceRangeException;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Adapter-contract tests for {@see MoneyPersistenceAdapter} (M1-T6):
 * pure unit-level coverage of the mapping and bounds-checking logic,
 * with no database dependency. Covers MON-T059–MON-T063, MON-T066
 * (the parts of ATS-003 §14's persistence tests that do not require a
 * real PostgreSQL instance) plus MON-T009/MON-T010's public-contract
 * requirements applied to this adapter. Round-trip fidelity against an
 * actual `BIGINT` column (MON-T059, MON-T060, MON-T065) is proven
 * separately by the integration test — this file exercises the
 * adapter's own logic in isolation.
 */
final class MoneyPersistenceAdapterTest extends TestCase
{
    private MoneyPersistenceAdapter $adapter;

    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new MoneyPersistenceAdapter;
        $this->myr = Currency::of('MYR');
    }

    /**
     * MON-T062: the persisted amount is the Money's exact MinorUnits
     * numeral, and the persisted currency is its canonical identifier
     * — written explicitly, never inferred.
     */
    public function test_money_maps_to_its_persisted_amount_and_currency(): void
    {
        $money = Money::fromDecimalString('10.25', $this->myr);

        $this->assertSame('1025', $this->adapter->toPersistedAmount($money));
        $this->assertSame('MYR', $this->adapter->toPersistedCurrency($money));
    }

    /**
     * MON-T064: a persisted `(amount, currency)` pair reconstructs an
     * equal Money value via the validated `fromMinorUnits` path.
     */
    public function test_persisted_amount_and_currency_map_back_to_money(): void
    {
        $money = $this->adapter->fromPersisted('1025', 'MYR');

        $this->assertSame('10.25', $money->toDecimalString());
        $this->assertTrue($this->myr->equals($money->currency()));
    }

    /**
     * MON-T065: a full write-then-read round trip reproduces an
     * exactly equal Money value.
     */
    public function test_write_then_read_round_trip_is_exact(): void
    {
        $original = Money::fromDecimalString('999999.99', $this->myr);

        $amount = $this->adapter->toPersistedAmount($original);
        $currency = $this->adapter->toPersistedCurrency($original);
        $reconstructed = $this->adapter->fromPersisted($amount, $currency);

        $this->assertTrue($original->equals($reconstructed));
    }

    /**
     * Zero round-trips exactly, same as any other in-range amount.
     */
    public function test_zero_round_trips_exactly(): void
    {
        $original = Money::fromDecimalString('0.00', $this->myr);

        $reconstructed = $this->adapter->fromPersisted(
            $this->adapter->toPersistedAmount($original),
            $this->adapter->toPersistedCurrency($original),
        );

        $this->assertTrue($original->equals($reconstructed));
    }

    /**
     * MON-T060: the signed 64-bit BIGINT maximum itself is accepted —
     * the boundary is inclusive, not exclusive.
     */
    public function test_bigint_maximum_boundary_is_accepted(): void
    {
        $money = Money::fromMinorUnits(MinorUnits::of('9223372036854775807'), $this->myr);

        $amount = $this->adapter->toPersistedAmount($money);

        $this->assertSame('9223372036854775807', $amount);
    }

    /**
     * MON-T061: a MinorUnits value one beyond the signed 64-bit
     * maximum is rejected before any write — a typed failure, not a
     * database-level error surfaced afterward.
     */
    public function test_value_above_bigint_maximum_is_rejected_before_write(): void
    {
        $money = Money::fromMinorUnits(MinorUnits::of('9223372036854775808'), $this->myr);

        $this->expectException(MoneyOutOfPersistenceRangeException::class);

        $this->adapter->toPersistedAmount($money);
    }

    /**
     * The same rejection holds at a magnitude far beyond the BIGINT
     * boundary (more digits than the maximum), proving the bounds
     * check is not merely a fixed-width comparison that happens to
     * work near the boundary.
     */
    public function test_value_far_above_bigint_maximum_is_rejected(): void
    {
        $money = Money::fromMinorUnits(
            MinorUnits::of('999999999999999999999999999999999999999999'),
            $this->myr,
        );

        $this->expectException(MoneyOutOfPersistenceRangeException::class);

        $this->adapter->toPersistedAmount($money);
    }

    /**
     * MON-T061 is proven exact, not merely native-int-approximate: the
     * bounds check must not go through a native PHP int at any point
     * (native int silently overflows to float beyond PHP_INT_MAX on
     * this platform, which would corrupt exactly this comparison).
     * Proven structurally, by scanning the adapter's own source for an
     * int cast or arithmetic on the amount, rather than only by
     * behavioral proximity to the boundary (already covered above).
     */
    public function test_bounds_check_does_not_use_native_int_conversion(): void
    {
        $reflection = new ReflectionClass(MoneyPersistenceAdapter::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('(int)', $source);
        $this->assertStringNotContainsString('intval(', $source);
        $this->assertStringNotContainsString('(float)', $source);
        $this->assertStringNotContainsString('floatval(', $source);
    }

    /**
     * MON-T062: currency is preserved exactly through the round trip
     * — the adapter never substitutes or defaults it.
     */
    public function test_explicit_currency_is_preserved_through_round_trip(): void
    {
        $money = Money::fromDecimalString('50.00', $this->myr);

        $reconstructed = $this->adapter->fromPersisted(
            $this->adapter->toPersistedAmount($money),
            $this->adapter->toPersistedCurrency($money),
        );

        $this->assertSame('MYR', $this->adapter->toPersistedCurrency($reconstructed));
    }

    /**
     * MON-T063: the adapter's public contract has no method that
     * produces or accepts a scale value — scale is never persisted
     * separately from Currency.
     */
    public function test_adapter_exposes_no_scale_accessor(): void
    {
        $reflection = new ReflectionClass(MoneyPersistenceAdapter::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertStringNotContainsStringIgnoringCase('scale', $method->getName());

            foreach ($method->getParameters() as $parameter) {
                $this->assertStringNotContainsStringIgnoringCase('scale', $parameter->getName());
            }
        }
    }

    /**
     * A malformed persisted amount (not a canonical non-negative
     * integer numeral) is rejected via Money's own existing
     * validation — the adapter does not invent a second grammar.
     */
    public function test_malformed_persisted_amount_is_rejected(): void
    {
        $this->expectException(InvalidMinorUnitsException::class);

        $this->adapter->fromPersisted('10.25', 'MYR');
    }

    /**
     * A negative persisted amount is rejected the same way — Money
     * sign policy is not broadened by the adapter; the signed BIGINT
     * range's negative half is not treated as authorization for a
     * negative Money.
     */
    public function test_negative_persisted_amount_is_rejected(): void
    {
        $this->expectException(InvalidMinorUnitsException::class);

        $this->adapter->fromPersisted('-1025', 'MYR');
    }

    /**
     * An unsupported or non-canonical persisted currency identifier is
     * rejected via Currency's own existing validation.
     */
    public function test_invalid_persisted_currency_is_rejected(): void
    {
        $this->expectException(InvalidCurrencyException::class);

        $this->adapter->fromPersisted('1025', 'XXX');
    }

    /**
     * MON-001 applied to persistence: no adapter method accepts or
     * returns a native `float` anywhere in its public signature.
     */
    public function test_no_float_in_adapter_public_api(): void
    {
        $reflection = new ReflectionClass(MoneyPersistenceAdapter::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $this->assertNotFloatType($type, $method->getName());
            }

            $this->assertNotFloatType($method->getReturnType(), $method->getName());
        }
    }

    /**
     * MON-T067/MON-T068 applied to the infrastructure layer: no
     * `Brick\...` namespace type appears anywhere in this adapter's
     * public method signatures — Money's own vendor wrapping is not
     * bypassed here.
     */
    public function test_no_vendor_type_in_adapter_public_api(): void
    {
        $reflection = new ReflectionClass(MoneyPersistenceAdapter::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $this->assertNotVendorType($type, $method->getName());
            }

            $this->assertNotVendorType($method->getReturnType(), $method->getName());
        }
    }

    /**
     * Repo-wide: the `Brick\` namespace is not referenced anywhere
     * under the Infrastructure Money adapter — vendor isolation is not
     * merely a public-signature-level guarantee here, since this
     * adapter has no legitimate reason to touch the vendor library at
     * all (unlike Money.php itself).
     */
    public function test_brick_namespace_does_not_appear_in_the_adapter(): void
    {
        $reflection = new ReflectionClass(MoneyPersistenceAdapter::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Brick', $source);
    }

    /**
     * Domain/infrastructure isolation: the Money domain module has no
     * Eloquent, database, or other framework dependency — this
     * adapter, not Money/Currency/MinorUnits, is the only code aware
     * of persistence (AETS-003 §15; ENGINEERING_BLUEPRINT.md §4.1).
     */
    public function test_domain_money_module_has_no_framework_dependency(): void
    {
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                dirname(__DIR__, 5).'/app/Domain/Accounting/Money',
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($directory as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $this->assertIsString($contents);
            $this->assertStringNotContainsString('Illuminate\\', $contents, sprintf(
                'Unexpected framework dependency in "%s".',
                $file->getPathname(),
            ));
            $this->assertStringNotContainsString('Eloquent', $contents, sprintf(
                'Unexpected Eloquent dependency in "%s".',
                $file->getPathname(),
            ));
        }
    }

    private function assertNotFloatType(?\ReflectionType $type, string $context): void
    {
        if ($type instanceof \ReflectionNamedType) {
            $this->assertNotSame('float', $type->getName(), sprintf('Method/parameter "%s" must not use float.', $context));
        }
    }

    private function assertNotVendorType(?\ReflectionType $type, string $context): void
    {
        if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
            $this->assertStringStartsNotWith(
                'Brick\\',
                $type->getName(),
                sprintf('Method/parameter "%s" must not expose a Brick type.', $context),
            );
        }
    }
}
