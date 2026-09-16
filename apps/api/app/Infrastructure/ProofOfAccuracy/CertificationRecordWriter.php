<?php

declare(strict_types=1);

namespace App\Infrastructure\ProofOfAccuracy;

use App\Domain\ProofOfAccuracy\CertificationRecord;

/**
 * Serializes a {@see CertificationRecord} to the machine-readable JSON
 * document AETS-012 §6.9/§8 requires every certification run to
 * retain. Pure serialization only — never decides where certification
 * records are stored long-term; AETS-012's own §11/ATS-012 §7 leave
 * "controlled certification-record location and retention policy" an
 * open decision this class does not make.
 */
final class CertificationRecordWriter
{
    public function toJson(CertificationRecord $record): string
    {
        $payload = [
            'id' => $record->id(),
            'generated_at_utc' => $record->generatedAtUtc()->format(\DateTimeInterface::ATOM),
            'git_revision' => $record->gitRevision(),
            'git_tree_is_clean' => $record->gitTreeIsClean(),
            'dataset_id' => $record->datasetId(),
            'dataset_version' => $record->datasetVersion(),
            'manifest_content_digest' => $record->manifestContentDigest(),
            'php_version' => $record->phpVersion(),
            'laravel_version' => $record->laravelVersion(),
            'postgres_version' => $record->postgresVersion(),
            'test_runner_version' => $record->testRunnerVersion(),
            'executed_commands' => array_map(static fn ($c): array => [
                'command' => $c->command(),
                'exit_code' => $c->exitCode(),
            ], $record->executedCommands()),
            'test_run_counts' => [
                'passed' => $record->testRunCounts()->passed(),
                'failed' => $record->testRunCounts()->failed(),
                'errors' => $record->testRunCounts()->errors(),
                'skipped' => $record->testRunCounts()->skipped(),
                'incomplete' => $record->testRunCounts()->incomplete(),
                'risky' => $record->testRunCounts()->risky(),
            ],
            'criterion_results' => array_map(static fn ($c): array => [
                'criterion_id' => $c->criterionId(),
                'passed' => $c->isPassed(),
                'detail' => $c->detail(),
            ], $record->criterionResults()),
            'deviations' => $record->deviations(),
            'cto_reviewed_by' => $record->ctoReviewedBy(),
            'accounting_domain_reviewer_approved_by' => $record->accountingDomainReviewerApprovedBy(),
            'founder_approval_required' => $record->founderApprovalRequired(),
            'founder_approved_by' => $record->founderApprovedBy(),
            'is_certified_passing' => $record->isCertifiedPassing(),
        ];

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function writeToFile(CertificationRecord $record, string $path): void
    {
        file_put_contents($path, $this->toJson($record));
    }
}
