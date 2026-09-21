<?php

declare(strict_types=1);

namespace Tests\Feature\ProofOfAccuracy;

use App\Console\Commands\CertifyProofOfAccuracy;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\ProofOfAccuracy\CertificationCriterionResult;
use App\Domain\ProofOfAccuracy\GoldenDatasetIntegrityVerifier;
use App\Domain\ProofOfAccuracy\GoldenDatasetManifestLoader;
use App\Infrastructure\ProofOfAccuracy\GoldenDatasetCertificationEvaluator;
use App\Infrastructure\ProofOfAccuracy\GoldenDatasetScenarioRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Http\Api\IdentityAndAccountingApiTest;
use Tests\TestCase;

/**
 * AETS-012's dedicated real-PostgreSQL regression proof (POA-010): runs
 * Golden Dataset v1 end to end through {@see GoldenDatasetScenarioRunner}
 * and diffs every report through {@see GoldenDatasetCertificationEvaluator}
 * — the same classes {@see CertifyProofOfAccuracy}
 * uses to assemble a CertificationRecord — so this scenario stays
 * continuously exercised by the ordinary test suite, not just by a
 * manually-invoked Artisan command.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason (mirrors the established convention in
 * {@see IdentityAndAccountingApiTest}).
 */
final class ProofOfAccuracyCertificationTest extends TestCase
{
    private const TABLES_TO_CLEAN = [
        'evidence',
        'payment_allocations',
        'payments',
        'posting_idempotency_keys',
        'posting_source_fingerprints',
        'audit_events',
        'journal_evidence_links',
        'expenses',
        'incomes',
        'transfers',
        'reconciliation_completion_snapshots',
        'reconciliation_reopenings',
        'matches',
        'bank_transactions',
        'reconciliations',
        'bank_statement_import_batches',
        'bank_accounts',
        'invoice_lines',
        'invoices',
        'invoice_number_sequences',
        'customers',
        'journal_lines',
        'journals',
        'accounts',
        'tenants',
    ];

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        foreach (self::TABLES_TO_CLEAN as $table) {
            DB::connection('pgsql')->table($table)->delete();
        }
    }

    protected function tearDown(): void
    {
        if (self::$skipReason === null) {
            foreach (self::TABLES_TO_CLEAN as $table) {
                DB::connection('pgsql')->table($table)->delete();
            }
        }

        parent::tearDown();
    }

    public function test_golden_dataset_v1_certifies_every_mechanical_poa_criterion(): void
    {
        $datasetBaseDir = base_path('tests/Fixtures/ProofOfAccuracy/v1');

        $manifest = $this->app->make(GoldenDatasetManifestLoader::class)->loadFromFile($datasetBaseDir.'/manifest.json');
        $this->app->make(GoldenDatasetIntegrityVerifier::class)->verify($manifest, $datasetBaseDir);

        $scenario = json_decode((string) file_get_contents($datasetBaseDir.'/canonical/scenario-commands.json'), true, flags: JSON_THROW_ON_ERROR);
        $expected = json_decode((string) file_get_contents($datasetBaseDir.'/expected/accounting-results.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($scenario);
        $this->assertIsArray($expected);
        /** @var array<string, mixed> $scenario */
        /** @var array<string, mixed> $expected */
        $actor = ActorReference::of('poa-v1-certification-test');

        // The fixture's dates (issue/due/payment/as-of) are fixed
        // calendar dates authored once, but AgingReportQuery's P1-4
        // guard compares payment_allocations.created_at — real
        // wall-clock now() at insert time — against the fixture's own
        // as-of date. Left unfrozen, this test passes only through the
        // fixture's as-of date and fails every day after. Freeze to a
        // fixed instant on that as-of date so every created_at this
        // run produces lands on or before it, regardless of the actual
        // calendar date the suite runs on.
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-09-20 12:00:00', 'Asia/Kuala_Lumpur'));

        try {
            /** @var GoldenDatasetScenarioRunner $runner */
            $runner = $this->app->make(GoldenDatasetScenarioRunner::class);
            $runner->run($scenario, $datasetBaseDir, $actor);
            $replayProbe = $runner->runReplayProbe($scenario, $datasetBaseDir, $actor);

            /** @var GoldenDatasetCertificationEvaluator $evaluator */
            $evaluator = $this->app->make(GoldenDatasetCertificationEvaluator::class);
            $results = $evaluator->evaluate($scenario, $expected, $datasetBaseDir, $replayProbe);
        } finally {
            CarbonImmutable::setTestNow();
        }

        foreach ($results as $result) {
            /** @var CertificationCriterionResult $result */
            $this->assertTrue($result->isPassed(), "{$result->criterionId()} failed: {$result->detail()}");
        }

        $this->assertCount(6, $results, 'Expected one CertificationCriterionResult per POA-002, POA-004, POA-005, POA-006, POA-007, POA-008.');
    }

    private function ensureMigrated(): void
    {
        if (self::$skipReason !== null || self::$migrated) {
            return;
        }

        try {
            DB::connection('pgsql')->select('select 1');
        } catch (\Throwable $e) {
            self::$skipReason = sprintf(
                'A real PostgreSQL instance is not reachable via the "pgsql" connection (%s).',
                $e->getMessage(),
            );

            return;
        }

        $requiredTables = [...self::TABLES_TO_CLEAN, 'users'];
        $missingATable = false;

        foreach ($requiredTables as $table) {
            if (! Schema::connection('pgsql')->hasTable($table)) {
                $missingATable = true;

                break;
            }
        }

        if ($missingATable) {
            Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);
        }

        self::$migrated = true;
    }
}
