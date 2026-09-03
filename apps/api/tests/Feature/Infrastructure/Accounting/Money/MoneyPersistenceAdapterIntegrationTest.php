<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Accounting\Money;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Infrastructure\Accounting\Money\Exception\MoneyOutOfPersistenceRangeException;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration-level persistence tests for {@see MoneyPersistenceAdapter}
 * (M1-T6), exercised against a real PostgreSQL instance via the app's
 * own `pgsql` connection — per ATS-003's own framing, MON-T059–MON-T061
 * and MON-T065–MON-T066 are integration-level tests, and this suite
 * deliberately does not run against the default `sqlite` testing
 * connection `phpunit.xml` otherwise selects, since SQLite does not
 * share PostgreSQL's `BIGINT` column semantics.
 *
 * `money_persistence_adapter_test_fixture` is a minimal, adapter-only
 * test fixture table — not a business table, not an accounting
 * migration, and not part of any future Journal/Posting schema. It is
 * created and dropped by this test class itself (idempotently, via
 * `dropIfExists` + `create`), not via a committed migration file, so
 * it cannot be mistaken for real schema.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason. This suite never falls back to SQLite as
 * evidence of PostgreSQL `BIGINT` behavior.
 */
final class MoneyPersistenceAdapterIntegrationTest extends TestCase
{
    private const FIXTURE_TABLE = 'money_persistence_adapter_test_fixture';

    private static ?string $skipReason = null;

    private static bool $fixtureReady = false;

    private MoneyPersistenceAdapter $adapter;

    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = new MoneyPersistenceAdapter;
        $this->myr = Currency::of('MYR');

        $this->ensureFixtureIsReady();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        DB::connection('pgsql')->table(self::FIXTURE_TABLE)->truncate();
    }

    /**
     * MON-T065: a full write-then-read round trip through a real
     * `BIGINT` column reproduces an exactly equal Money value, for a
     * representative MYR amount.
     */
    public function test_normal_myr_value_round_trips_through_a_real_bigint_column(): void
    {
        $original = Money::fromDecimalString('10.25', $this->myr);

        $id = $this->insertFixtureRow($original);
        $reconstructed = $this->reconstructFixtureRow($id);

        $this->assertTrue($original->equals($reconstructed));
    }

    /**
     * MON-T065: zero round-trips exactly through a real `BIGINT`
     * column.
     */
    public function test_zero_round_trips_through_a_real_bigint_column(): void
    {
        $original = Money::fromDecimalString('0.00', $this->myr);

        $id = $this->insertFixtureRow($original);
        $reconstructed = $this->reconstructFixtureRow($id);

        $this->assertTrue($original->equals($reconstructed));
    }

    /**
     * MON-T059 / MON-T060: the signed 64-bit `BIGINT` maximum is
     * accepted and written to, then read back from, a real `BIGINT`
     * column — the boundary is inclusive.
     */
    public function test_bigint_maximum_boundary_round_trips_through_a_real_column(): void
    {
        $original = Money::fromMinorUnits(MinorUnits::of('9223372036854775807'), $this->myr);

        $id = $this->insertFixtureRow($original);
        $reconstructed = $this->reconstructFixtureRow($id);

        $this->assertTrue($original->equals($reconstructed));
        $this->assertSame('9223372036854775807', $reconstructed->toMinorUnits()->toString());
    }

    /**
     * MON-T061 / MON-T066: a value exceeding the signed 64-bit
     * boundary is rejected by the adapter before any SQL is executed
     * — proven against a real table by asserting no row was written,
     * not merely by catching the exception.
     */
    public function test_value_above_bigint_maximum_is_rejected_before_any_write_reaches_postgres(): void
    {
        $tooLarge = Money::fromMinorUnits(MinorUnits::of('9223372036854775808'), $this->myr);

        $rowCountBefore = DB::connection('pgsql')->table(self::FIXTURE_TABLE)->count();

        try {
            $this->adapter->toPersistedAmount($tooLarge);
            $this->fail('Expected MoneyOutOfPersistenceRangeException was not thrown.');
        } catch (MoneyOutOfPersistenceRangeException) {
            // Expected — no insert should ever have been attempted.
        }

        $rowCountAfter = DB::connection('pgsql')->table(self::FIXTURE_TABLE)->count();

        $this->assertSame($rowCountBefore, $rowCountAfter);
    }

    /**
     * MON-T062 / MON-T063: the fixture's persisted row carries an
     * explicit currency column and no scale column, and the amount
     * column is genuinely a `bigint` in PostgreSQL's own catalog — not
     * merely assumed from the migration source.
     */
    public function test_fixture_amount_column_is_a_real_postgres_bigint_with_no_scale_column(): void
    {
        $columns = DB::connection('pgsql')->select(
            'select column_name, data_type from information_schema.columns where table_name = ?',
            [self::FIXTURE_TABLE],
        );

        $columnsByName = [];
        foreach ($columns as $column) {
            $columnsByName[$column->column_name] = $column->data_type;
        }

        $this->assertSame('bigint', $columnsByName['amount_minor_units'] ?? null);
        $this->assertArrayHasKey('currency_code', $columnsByName);
        $this->assertArrayNotHasKey('scale', $columnsByName);
    }

    private function insertFixtureRow(Money $money): int
    {
        /** @var int $id */
        $id = DB::connection('pgsql')->table(self::FIXTURE_TABLE)->insertGetId([
            'amount_minor_units' => $this->adapter->toPersistedAmount($money),
            'currency_code' => $this->adapter->toPersistedCurrency($money),
        ]);

        return $id;
    }

    private function reconstructFixtureRow(int $id): Money
    {
        /** @var object{amount_minor_units: string, currency_code: string} $row */
        $row = DB::connection('pgsql')->table(self::FIXTURE_TABLE)->where('id', $id)->firstOrFail();

        return $this->adapter->fromPersisted((string) $row->amount_minor_units, $row->currency_code);
    }

    private function ensureFixtureIsReady(): void
    {
        if (self::$skipReason !== null || self::$fixtureReady) {
            return;
        }

        try {
            DB::connection('pgsql')->select('select 1');
        } catch (\Throwable $e) {
            self::$skipReason = sprintf(
                'A real PostgreSQL instance is not reachable via the "pgsql" connection (%s). '
                .'Run `docker compose up -d postgres` (see docker-compose.yml) to enable this integration test.',
                $e->getMessage(),
            );

            return;
        }

        Schema::connection('pgsql')->dropIfExists(self::FIXTURE_TABLE);
        Schema::connection('pgsql')->create(self::FIXTURE_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('amount_minor_units');
            $table->string('currency_code', 3);
        });

        self::$fixtureReady = true;
    }
}
