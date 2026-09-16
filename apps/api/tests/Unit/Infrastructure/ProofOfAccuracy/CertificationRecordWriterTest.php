<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\ProofOfAccuracy;

use App\Domain\ProofOfAccuracy\CertificationCriterionResult;
use App\Domain\ProofOfAccuracy\CertificationRecord;
use App\Domain\ProofOfAccuracy\ExecutedCommandRecord;
use App\Domain\ProofOfAccuracy\TestRunCounts;
use App\Infrastructure\ProofOfAccuracy\CertificationRecordWriter;
use PHPUnit\Framework\TestCase;

final class CertificationRecordWriterTest extends TestCase
{
    public function test_the_serialized_json_round_trips_every_field(): void
    {
        $record = new CertificationRecord(
            id: 'poa-run-test-fixture-0001',
            generatedAtUtc: new \DateTimeImmutable('2026-09-16T03:00:00Z'),
            gitRevision: '2ff19f2abcdef1234567890abcdef1234567890',
            gitTreeIsClean: true,
            datasetId: 'poa-test-fixture',
            datasetVersion: '1',
            manifestContentDigest: hash('sha256', 'test-fixture-manifest'),
            phpVersion: '8.5.10',
            laravelVersion: '13.0.0',
            postgresVersion: '16.15',
            testRunnerVersion: 'PHPUnit 12.5.34',
            executedCommands: [new ExecutedCommandRecord('./vendor/bin/phpunit', 0)],
            testRunCounts: new TestRunCounts(passed: 1714, failed: 0, errors: 0, skipped: 0, incomplete: 0, risky: 0),
            criterionResults: [
                CertificationCriterionResult::passed('POA-001', 'manifest verified'),
                CertificationCriterionResult::failed('POA-005', 'Trial Balance mismatch'),
            ],
            deviations: [],
            ctoReviewedBy: 'Claude Sonnet 5 (CTO)',
            accountingDomainReviewerApprovedBy: null,
            founderApprovalRequired: false,
            founderApprovedBy: null,
        );

        $json = (new CertificationRecordWriter)->toJson($record);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('poa-run-test-fixture-0001', $decoded['id']);
        $this->assertSame('2026-09-16T03:00:00+00:00', $decoded['generated_at_utc']);
        $this->assertTrue($decoded['git_tree_is_clean']);
        $this->assertSame('poa-test-fixture', $decoded['dataset_id']);
        $this->assertSame(1714, $decoded['test_run_counts']['passed']);
        $this->assertCount(2, $decoded['criterion_results']);
        $this->assertSame('POA-005', $decoded['criterion_results'][1]['criterion_id']);
        $this->assertFalse($decoded['criterion_results'][1]['passed']);
        $this->assertNull($decoded['accounting_domain_reviewer_approved_by']);
        $this->assertFalse($decoded['is_certified_passing']);
    }

    public function test_write_to_file_persists_the_same_json(): void
    {
        $record = new CertificationRecord(
            id: 'poa-run-test-fixture-0002',
            generatedAtUtc: new \DateTimeImmutable('2026-09-16T03:00:00Z'),
            gitRevision: '0000000000000000000000000000000000000000',
            gitTreeIsClean: true,
            datasetId: 'poa-test-fixture',
            datasetVersion: '1',
            manifestContentDigest: hash('sha256', 'test-fixture-manifest'),
            phpVersion: '8.5.10',
            laravelVersion: '13.0.0',
            postgresVersion: '16.15',
            testRunnerVersion: 'PHPUnit 12.5.34',
            executedCommands: [],
            testRunCounts: new TestRunCounts(1, 0, 0, 0, 0, 0),
            criterionResults: [],
            deviations: [],
            ctoReviewedBy: 'Claude Sonnet 5 (CTO)',
            accountingDomainReviewerApprovedBy: null,
            founderApprovalRequired: false,
            founderApprovedBy: null,
        );

        $writer = new CertificationRecordWriter;
        $path = sys_get_temp_dir().'/poa_certification_record_test_'.bin2hex(random_bytes(8)).'.json';

        try {
            $writer->writeToFile($record, $path);

            $this->assertSame($writer->toJson($record), file_get_contents($path));
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}
