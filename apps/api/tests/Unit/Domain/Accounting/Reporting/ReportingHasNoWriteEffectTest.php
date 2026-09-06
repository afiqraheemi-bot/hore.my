<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Reporting;

use App\Infrastructure\Accounting\Reporting\AccountBalanceAggregator;
use App\Infrastructure\Accounting\Reporting\BalanceSheetQuery;
use App\Infrastructure\Accounting\Reporting\EvidenceIndexQuery;
use App\Infrastructure\Accounting\Reporting\GeneralLedgerQuery;
use App\Infrastructure\Accounting\Reporting\ProfitAndLossQuery;
use App\Infrastructure\Accounting\Reporting\TrialBalanceQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Unit\Domain\Accounting\Posting\PostingNoNetworkInTransactionTest;

/**
 * Architecture proof for AETS-009 §5 rule 6 ("Reporting never writes;
 * it only reads") and SRS §9's module-ownership rule ("Reporting:
 * Membaca lejar/projection dan tidak boleh mutasi rekod sumber") —
 * mirrors the established literal-source-scan technique
 * ({@see PostingNoNetworkInTransactionTest},
 * itself citing `JRN-T032`/ATS-004 §21 as its own precedent) applied to
 * every class in the Reporting query layer.
 *
 * A plain substring scan of each class's own source file is
 * deliberately stricter than a runtime/mock-based proof: it fails even
 * on a forbidden call inside a currently-unreached branch, and needs no
 * database connection to run.
 */
final class ReportingHasNoWriteEffectTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function reportingQueryClasses(): array
    {
        $classes = [
            AccountBalanceAggregator::class,
            TrialBalanceQuery::class,
            ProfitAndLossQuery::class,
            BalanceSheetQuery::class,
            GeneralLedgerQuery::class,
            EvidenceIndexQuery::class,
        ];

        $cases = [];
        foreach ($classes as $class) {
            $cases[$class] = [$class];
        }

        return $cases;
    }

    #[DataProvider('reportingQueryClasses')]
    public function test_no_write_operation_against_any_production_table(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);

        $forbiddenSymbols = [
            '->insert(',
            '->insertGetId(',
            '->insertOrIgnore(',
            '->update(',
            '->updateOrInsert(',
            '->upsert(',
            '->delete(',
            '->truncate(',
            '->forceDelete(',
            '->save(',
            '->create(',
            'DB::insert',
            'DB::update',
            'DB::delete',
            'DB::statement',
            'DB::unprepared',
        ];

        foreach ($forbiddenSymbols as $symbol) {
            $this->assertStringNotContainsString($symbol, $source, sprintf(
                '%s must not reference "%s" — the Reporting query layer is read-only '.
                'by construction (AETS-009 §5 rule 6): it derives every report from '.
                'already-Posted Journals and never mutates a source record.',
                $class,
                $symbol,
            ));
        }
    }
}
