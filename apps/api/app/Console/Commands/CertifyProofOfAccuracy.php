<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\ProofOfAccuracy\CertificationCriterionResult;
use App\Domain\ProofOfAccuracy\CertificationRecord;
use App\Domain\ProofOfAccuracy\ExecutedCommandRecord;
use App\Domain\ProofOfAccuracy\GoldenDatasetIntegrityVerifier;
use App\Domain\ProofOfAccuracy\GoldenDatasetManifestLoader;
use App\Domain\ProofOfAccuracy\TestRunCounts;
use App\Infrastructure\ProofOfAccuracy\CertificationRecordWriter;
use App\Infrastructure\ProofOfAccuracy\GoldenDatasetCertificationEvaluator;
use App\Infrastructure\ProofOfAccuracy\GoldenDatasetScenarioRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `proof-of-accuracy:certify` — runs AETS-012's Golden Dataset v1
 * end to end against real PostgreSQL through this codebase's own real
 * domain services, evaluates POA-001 through POA-011, and writes a
 * {@see CertificationRecord}. Deliberately never sets
 * `accountingDomainReviewerApprovedBy`/`founderApprovedBy` — those are
 * for a human to supply into the record afterward; POA-012's own
 * fail-closed `isCertifiedPassing()` gate always reports `false` for a
 * record this command alone ever produced.
 *
 * **Destructive.** This command runs `migrate:fresh` twice, against
 * whichever database the active connection resolves to. It refuses to
 * run at all unless that database's name contains "test" — run it as
 * `php artisan proof-of-accuracy:certify --env=testing`, exactly like
 * this codebase's own PHPUnit suite, never against a development or
 * production database.
 */
final class CertifyProofOfAccuracy extends Command
{
    protected $signature = 'proof-of-accuracy:certify {--output=} {--cto-reviewed-by=} {--git-revision=} {--git-tree-clean=}';

    protected $description = 'Run AETS-012 Golden Dataset v1 end to end and produce a Proof of Accuracy CertificationRecord (never self-certifying).';

    public function handle(
        GoldenDatasetManifestLoader $manifestLoader,
        GoldenDatasetIntegrityVerifier $integrityVerifier,
        GoldenDatasetScenarioRunner $runner,
        GoldenDatasetCertificationEvaluator $evaluator,
        CertificationRecordWriter $writer,
    ): int {
        $databaseName = (string) DB::connection()->getDatabaseName();

        if (! str_contains($databaseName, 'test')) {
            $this->error("Refusing to run: active database \"{$databaseName}\" does not look like a test database. Run with --env=testing.");

            return self::FAILURE;
        }

        $datasetBaseDir = base_path('tests/Fixtures/ProofOfAccuracy/v1');
        $manifestPath = $datasetBaseDir.'/manifest.json';

        $this->info("Certifying Golden Dataset against database \"{$databaseName}\"...");

        Artisan::call('migrate:fresh', ['--force' => true]);

        [$firstRun, $firstError] = $this->certifyOnce($manifestLoader, $integrityVerifier, $runner, $evaluator, $manifestPath, $datasetBaseDir);

        if ($firstError !== null) {
            $this->error($firstError);

            return self::FAILURE;
        }

        Artisan::call('migrate:fresh', ['--force' => true]);

        [$secondRun, $secondError] = $this->certifyOnce($manifestLoader, $integrityVerifier, $runner, $evaluator, $manifestPath, $datasetBaseDir);

        if ($secondError !== null) {
            $this->error($secondError);

            return self::FAILURE;
        }

        $poa009 = $this->compareIndependentRuns($this->mixedToString($firstRun['criteria_json'] ?? ''), $this->mixedToString($secondRun['criteria_json'] ?? ''));

        /** @var list<CertificationCriterionResult> $firstCriterionResults */
        $firstCriterionResults = $firstRun['criterion_results'];
        $criterionResults = array_merge($firstCriterionResults, [$poa009]);

        [$testRunCounts, $poa010, $phpunitExecutedCommand] = $this->runDedicatedTestSuite();
        $criterionResults[] = $poa010;

        /** @var list<ExecutedCommandRecord> $firstExecutedCommands */
        $firstExecutedCommands = $firstRun['executed_commands'];
        /** @var list<ExecutedCommandRecord> $secondExecutedCommands */
        $secondExecutedCommands = $secondRun['executed_commands'];

        $executedCommands = array_merge(
            $firstExecutedCommands,
            [new ExecutedCommandRecord('poa:second_independent_clean_state_run', 0)],
            $secondExecutedCommands,
            [$phpunitExecutedCommand],
        );

        $deviations = [];
        foreach ($criterionResults as $result) {
            if (! $result->isPassed()) {
                $deviations[] = "{$result->criterionId()}: {$result->detail()}";
            }
        }

        $ctoReviewedBy = (string) ($this->option('cto-reviewed-by') ?: sprintf('CTO (self-review, %s)', now()->toDateString()));
        $postgresVersion = $this->queryPostgresVersion();

        // This container only bind-mounts `apps/api`, not the
        // monorepo's `.git` — `git rev-parse`/`git status` run from
        // here cannot see it, so the caller supplies the real values
        // computed on the host checkout via --git-revision/
        // --git-tree-clean rather than trusting a container-local
        // "unknown".
        $gitRevisionOption = $this->option('git-revision');
        $gitTreeCleanOption = $this->option('git-tree-clean');
        $gitRevision = is_string($gitRevisionOption) && $gitRevisionOption !== ''
            ? $gitRevisionOption
            : (trim((string) shell_exec('git rev-parse HEAD 2>/dev/null')) ?: 'unknown');
        $gitTreeIsClean = is_string($gitTreeCleanOption) && $gitTreeCleanOption !== ''
            ? filter_var($gitTreeCleanOption, FILTER_VALIDATE_BOOLEAN)
            : trim((string) shell_exec('git status --porcelain 2>/dev/null')) === '';

        $record = new CertificationRecord(
            (string) Str::uuid(),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $gitRevision,
            $gitTreeIsClean,
            'hore-my-poa-v1',
            '1.0.0',
            $this->mixedToString($firstRun['manifest_content_digest'] ?? ''),
            PHP_VERSION,
            (string) app()->version(),
            $postgresVersion,
            trim((string) shell_exec('php vendor/bin/phpunit --version 2>/dev/null')) ?: 'unknown',
            $executedCommands,
            $testRunCounts,
            $criterionResults,
            $deviations,
            $ctoReviewedBy,
            null,
            true,
            null,
        );

        // base_path()'s only guaranteed sibling inside this container is
        // this Laravel app itself (`apps/api` is the sole bind mount) —
        // the monorepo's `docs/` directory is not reachable from here.
        // The default output therefore lands under this app's own
        // storage; copying it into `docs/specifications/accounting/
        // certification-records/` is a deliberate separate step taken
        // outside the container, on the host checkout.
        $outputPath = (string) ($this->option('output') ?: storage_path('app/proof-of-accuracy/hore-my-poa-v1-1.0.0.json'));
        @mkdir(dirname($outputPath), 0755, true);
        $writer->writeToFile($record, $outputPath);

        $this->newLine();
        $this->info('Certification run complete. Mechanical criteria:');
        foreach ($criterionResults as $result) {
            $this->line(sprintf('  [%s] %s — %s', $result->isPassed() ? 'PASS' : 'FAIL', $result->criterionId(), $result->detail()));
        }
        $this->newLine();
        $this->line("isCertifiedPassing(): {$this->boolStr($record->isCertifiedPassing())} (expected false — no Accounting Domain Reviewer has approved this dataset yet)");
        $this->line("Record written to: {$outputPath}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function certifyOnce(
        GoldenDatasetManifestLoader $manifestLoader,
        GoldenDatasetIntegrityVerifier $integrityVerifier,
        GoldenDatasetScenarioRunner $runner,
        GoldenDatasetCertificationEvaluator $evaluator,
        string $manifestPath,
        string $datasetBaseDir,
    ): array {
        $executedCommands = [];

        try {
            $manifest = $manifestLoader->loadFromFile($manifestPath);
            $integrityVerifier->verify($manifest, $datasetBaseDir);
            $executedCommands[] = new ExecutedCommandRecord('poa:manifest_load_and_verify', 0);
            $poa001 = CertificationCriterionResult::passed('POA-001', 'Manifest loaded and every artifact digest verified against manifest.json.');
        } catch (\Throwable $e) {
            $executedCommands[] = new ExecutedCommandRecord('poa:manifest_load_and_verify', 1);

            return [[], "Manifest integrity check failed: {$e->getMessage()}"];
        }

        $scenario = json_decode((string) file_get_contents($datasetBaseDir.'/canonical/scenario-commands.json'), true, flags: JSON_THROW_ON_ERROR);
        $expected = json_decode((string) file_get_contents($datasetBaseDir.'/expected/accounting-results.json'), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($scenario) || ! is_array($expected)) {
            return [[], 'Decoded scenario or expected JSON is not an array.'];
        }

        /** @var array<string, mixed> $scenario */
        /** @var array<string, mixed> $expected */
        $actor = ActorReference::of('poa-v1-certification-runner');

        try {
            $runRecords = $runner->run($scenario, $datasetBaseDir, $actor);
            $executedCommands = array_merge($executedCommands, $runRecords);

            $replayProbe = $runner->runReplayProbe($scenario, $datasetBaseDir, $actor);
            $executedCommands = array_merge($executedCommands, $replayProbe->records());

            $criterionResults = array_merge([$poa001], $evaluator->evaluate($scenario, $expected, $datasetBaseDir, $replayProbe));
        } catch (\Throwable $e) {
            return [[], 'Scenario execution or evaluation failed: '.$e->getMessage()."\n".$e->getTraceAsString()];
        }

        $criteriaJson = json_encode(array_map(static fn (CertificationCriterionResult $r): array => [
            'id' => $r->criterionId(),
            'passed' => $r->isPassed(),
            'detail' => $r->detail(),
        ], $criterionResults));

        return [[
            'criterion_results' => $criterionResults,
            'executed_commands' => $executedCommands,
            'criteria_json' => (string) $criteriaJson,
            'manifest_content_digest' => $manifest->contentDigest(),
        ], null];
    }

    private function compareIndependentRuns(string $firstCriteriaJson, string $secondCriteriaJson): CertificationCriterionResult
    {
        if ($firstCriteriaJson !== $secondCriteriaJson) {
            return CertificationCriterionResult::failed('POA-009', 'Two independent clean-state runs produced different criterion outcomes — not deterministic.');
        }

        return CertificationCriterionResult::passed('POA-009', 'Two independent clean-state (migrate:fresh) runs of the full scenario produced byte-identical criterion outcomes.');
    }

    /**
     * @return array{0: TestRunCounts, 1: CertificationCriterionResult, 2: ExecutedCommandRecord}
     */
    private function runDedicatedTestSuite(): array
    {
        $logPath = sys_get_temp_dir().'/poa-events-'.bin2hex(random_bytes(8)).'.txt';

        $command = sprintf(
            'cd %s && php vendor/bin/phpunit --testsuite=Feature --filter=ProofOfAccuracyCertificationTest --log-events-text=%s 2>&1',
            escapeshellarg(base_path()),
            escapeshellarg($logPath),
        );

        exec($command, $outputLines, $exitCode);

        $eventsText = is_file($logPath) ? (string) file_get_contents($logPath) : '';
        @unlink($logPath);

        $passed = $this->countLinesStartingWith($eventsText, 'Test Passed (');
        $failed = $this->countLinesStartingWith($eventsText, 'Test Failed (');
        $errors = $this->countLinesStartingWith($eventsText, 'Test Errored (');
        $skipped = $this->countLinesStartingWith($eventsText, 'Test Skipped (');
        $incomplete = $this->countLinesStartingWith($eventsText, 'Test Marked Incomplete (');
        $risky = $this->countLinesStartingWith($eventsText, 'Test Considered Risky (');

        $counts = new TestRunCounts($passed, $failed, $errors, $skipped, $incomplete, $risky);

        $poa010 = $counts->hasAnyFailure() || $exitCode !== 0
            ? CertificationCriterionResult::failed('POA-010', "Dedicated real-PostgreSQL test suite did not finish clean: passed={$passed} failed={$failed} errors={$errors} skipped={$skipped} incomplete={$incomplete} risky={$risky}, exit={$exitCode}.")
            : CertificationCriterionResult::passed('POA-010', "Dedicated real-PostgreSQL test suite finished with {$passed} passed, zero failures/errors/skips/incomplete/risky.");

        return [$counts, $poa010, new ExecutedCommandRecord('poa:dedicated_test_suite', $exitCode)];
    }

    private function countLinesStartingWith(string $text, string $prefix): int
    {
        $count = 0;
        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, $prefix)) {
                $count++;
            }
        }

        return $count;
    }

    private function boolStr(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private function mixedToString(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        throw new \RuntimeException('Cannot stringify a non-scalar value: '.get_debug_type($value));
    }

    private function queryPostgresVersion(): string
    {
        $row = DB::selectOne('select version() as version');

        if (! is_object($row)) {
            return 'unknown';
        }

        $asArray = (array) $row;
        $value = $asArray['version'] ?? null;

        return is_string($value) ? trim($value) : 'unknown';
    }
}
