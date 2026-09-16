<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ProofOfAccuracy;

use App\Domain\ProofOfAccuracy\CertificationCriterionResult;
use App\Domain\ProofOfAccuracy\CertificationRecord;
use App\Domain\ProofOfAccuracy\ExecutedCommandRecord;
use App\Domain\ProofOfAccuracy\TestRunCounts;
use PHPUnit\Framework\TestCase;

/**
 * Proves `POA-012` — the fail-closed aggregation gate this whole
 * namespace exists to enforce: any single failed or missing
 * requirement fails the entire record, no matter how clean everything
 * else is.
 */
final class CertificationRecordTest extends TestCase
{
    public function test_a_fully_clean_record_with_every_approval_present_is_certified_passing(): void
    {
        $record = $this->buildRecord();

        $this->assertTrue($record->isCertifiedPassing());
    }

    /**
     * The load-bearing case: no qualified Accounting Domain Reviewer
     * has approved anything for this product yet, so every record
     * constructed today, however clean, must report false here.
     */
    public function test_a_record_with_no_accounting_domain_reviewer_approval_is_never_certified_passing(): void
    {
        $record = $this->buildRecord(accountingDomainReviewerApprovedBy: null);

        $this->assertFalse($record->isCertifiedPassing());
    }

    public function test_a_blank_accounting_domain_reviewer_name_is_treated_as_absent(): void
    {
        $record = $this->buildRecord(accountingDomainReviewerApprovedBy: '   ');

        $this->assertFalse($record->isCertifiedPassing());
    }

    public function test_a_record_with_no_cto_review_is_never_certified_passing(): void
    {
        $record = $this->buildRecord(ctoReviewedBy: '');

        $this->assertFalse($record->isCertifiedPassing());
    }

    public function test_founder_approval_required_but_missing_fails_the_record(): void
    {
        $record = $this->buildRecord(founderApprovalRequired: true, founderApprovedBy: null);

        $this->assertFalse($record->isCertifiedPassing());
    }

    public function test_founder_approval_required_and_present_does_not_fail_the_record(): void
    {
        $record = $this->buildRecord(founderApprovalRequired: true, founderApprovedBy: 'Afiq Raheemi');

        $this->assertTrue($record->isCertifiedPassing());
    }

    public function test_founder_approval_not_required_and_absent_does_not_fail_the_record(): void
    {
        $record = $this->buildRecord(founderApprovalRequired: false, founderApprovedBy: null);

        $this->assertTrue($record->isCertifiedPassing());
    }

    public function test_any_single_deviation_fails_the_record(): void
    {
        $record = $this->buildRecord(deviations: ['Trial Balance grand total was off by RM0.01.']);

        $this->assertFalse($record->isCertifiedPassing());
    }

    public function test_a_single_test_failure_fails_the_record_even_with_otherwise_zero_counts(): void
    {
        $record = $this->buildRecord(testRunCounts: new TestRunCounts(passed: 199, failed: 1, errors: 0, skipped: 0, incomplete: 0, risky: 0));

        $this->assertFalse($record->isCertifiedPassing());
    }

    public function test_a_single_skipped_test_fails_the_record(): void
    {
        $record = $this->buildRecord(testRunCounts: new TestRunCounts(passed: 199, failed: 0, errors: 0, skipped: 1, incomplete: 0, risky: 0));

        $this->assertFalse($record->isCertifiedPassing());
    }

    public function test_a_single_risky_test_fails_the_record(): void
    {
        $record = $this->buildRecord(testRunCounts: new TestRunCounts(passed: 199, failed: 0, errors: 0, skipped: 0, incomplete: 0, risky: 1));

        $this->assertFalse($record->isCertifiedPassing());
    }

    public function test_a_nonzero_exit_code_command_fails_the_record(): void
    {
        $record = $this->buildRecord(executedCommands: [
            new ExecutedCommandRecord('php artisan migrate --force', 0),
            new ExecutedCommandRecord('./vendor/bin/phpunit', 1),
        ]);

        $this->assertFalse($record->isCertifiedPassing());
    }

    public function test_a_single_failed_criterion_fails_the_record(): void
    {
        $record = $this->buildRecord(criterionResults: [
            CertificationCriterionResult::passed('POA-001'),
            CertificationCriterionResult::failed('POA-005', 'Trial Balance grand total mismatch.'),
        ]);

        $this->assertFalse($record->isCertifiedPassing());
    }

    /**
     * @param  list<ExecutedCommandRecord>|null  $executedCommands
     * @param  list<CertificationCriterionResult>|null  $criterionResults
     * @param  list<string>|null  $deviations
     */
    private function buildRecord(
        ?string $ctoReviewedBy = null,
        string|false|null $accountingDomainReviewerApprovedBy = false,
        bool $founderApprovalRequired = false,
        ?string $founderApprovedBy = null,
        ?TestRunCounts $testRunCounts = null,
        ?array $executedCommands = null,
        ?array $criterionResults = null,
        ?array $deviations = null,
    ): CertificationRecord {
        return new CertificationRecord(
            id: 'poa-run-test-fixture-0001',
            generatedAtUtc: new \DateTimeImmutable('2026-09-16T00:00:00Z'),
            gitRevision: '0000000000000000000000000000000000000000',
            gitTreeIsClean: true,
            datasetId: 'poa-test-fixture',
            datasetVersion: '1',
            manifestContentDigest: hash('sha256', 'test-fixture-manifest'),
            phpVersion: '8.5.10',
            laravelVersion: '13.0.0',
            postgresVersion: '16.15',
            testRunnerVersion: 'PHPUnit 12.5.34',
            executedCommands: $executedCommands ?? [new ExecutedCommandRecord('./vendor/bin/phpunit', 0)],
            testRunCounts: $testRunCounts ?? new TestRunCounts(passed: 1, failed: 0, errors: 0, skipped: 0, incomplete: 0, risky: 0),
            criterionResults: $criterionResults ?? [CertificationCriterionResult::passed('POA-001')],
            deviations: $deviations ?? [],
            ctoReviewedBy: $ctoReviewedBy ?? 'Claude Sonnet 5 (CTO)',
            // `false` is this private helper's own sentinel for "caller
            // did not override" — distinct from the real, meaningful
            // `null` case under test in several methods above.
            accountingDomainReviewerApprovedBy: $accountingDomainReviewerApprovedBy === false ? 'Test Fixture Reviewer' : $accountingDomainReviewerApprovedBy,
            founderApprovalRequired: $founderApprovalRequired,
            founderApprovedBy: $founderApprovedBy,
        );
    }
}
