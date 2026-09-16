<?php

declare(strict_types=1);

namespace App\Domain\ProofOfAccuracy;

/**
 * The immutable report of one AETS-012 certification run (§8) — every
 * field that section mandates, plus the single authoritative
 * fail-closed gate ({@see isCertifiedPassing()}) implementing
 * `POA-012`: "any failed or missing criterion fails the whole gate;
 * partial coverage is never called Proof of Accuracy passed."
 *
 * **This class cannot manufacture certification.** Constructing one
 * with every field green does not itself make Proof of Accuracy pass
 * — AETS-012 §3 is explicit that only the review this document names
 * (CTO / Technical Partner, and an Accounting Domain Reviewer) confers
 * that authority. `accountingDomainReviewerApprovedBy` is nullable for
 * exactly this reason: no qualified reviewer has approved anything yet
 * for this product, so any record constructed today honestly carries
 * `null` there, and {@see isCertifiedPassing()} correctly reports
 * `false` regardless of how clean every other field is.
 */
final class CertificationRecord
{
    /**
     * @param  list<ExecutedCommandRecord>  $executedCommands
     * @param  list<CertificationCriterionResult>  $criterionResults
     * @param  list<string>  $deviations
     */
    public function __construct(
        private readonly string $id,
        private readonly \DateTimeImmutable $generatedAtUtc,
        private readonly string $gitRevision,
        private readonly bool $gitTreeIsClean,
        private readonly string $datasetId,
        private readonly string $datasetVersion,
        private readonly string $manifestContentDigest,
        private readonly string $phpVersion,
        private readonly string $laravelVersion,
        private readonly string $postgresVersion,
        private readonly string $testRunnerVersion,
        private readonly array $executedCommands,
        private readonly TestRunCounts $testRunCounts,
        private readonly array $criterionResults,
        private readonly array $deviations,
        private readonly string $ctoReviewedBy,
        private readonly ?string $accountingDomainReviewerApprovedBy,
        private readonly bool $founderApprovalRequired,
        private readonly ?string $founderApprovedBy,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function generatedAtUtc(): \DateTimeImmutable
    {
        return $this->generatedAtUtc;
    }

    public function gitRevision(): string
    {
        return $this->gitRevision;
    }

    public function gitTreeIsClean(): bool
    {
        return $this->gitTreeIsClean;
    }

    public function datasetId(): string
    {
        return $this->datasetId;
    }

    public function datasetVersion(): string
    {
        return $this->datasetVersion;
    }

    public function manifestContentDigest(): string
    {
        return $this->manifestContentDigest;
    }

    public function phpVersion(): string
    {
        return $this->phpVersion;
    }

    public function laravelVersion(): string
    {
        return $this->laravelVersion;
    }

    public function postgresVersion(): string
    {
        return $this->postgresVersion;
    }

    public function testRunnerVersion(): string
    {
        return $this->testRunnerVersion;
    }

    /**
     * @return list<ExecutedCommandRecord>
     */
    public function executedCommands(): array
    {
        return $this->executedCommands;
    }

    public function testRunCounts(): TestRunCounts
    {
        return $this->testRunCounts;
    }

    /**
     * @return list<CertificationCriterionResult>
     */
    public function criterionResults(): array
    {
        return $this->criterionResults;
    }

    /**
     * @return list<string>
     */
    public function deviations(): array
    {
        return $this->deviations;
    }

    public function ctoReviewedBy(): string
    {
        return $this->ctoReviewedBy;
    }

    public function accountingDomainReviewerApprovedBy(): ?string
    {
        return $this->accountingDomainReviewerApprovedBy;
    }

    public function founderApprovalRequired(): bool
    {
        return $this->founderApprovalRequired;
    }

    public function founderApprovedBy(): ?string
    {
        return $this->founderApprovedBy;
    }

    /**
     * The single authoritative gate (`POA-012`). Every one of these
     * must hold, with no partial credit:
     *
     * - every executed command exited zero;
     * - the test run has zero failures/errors/skips/incomplete/risky
     *   (`POA-010`);
     * - every cited `POA-NNN` criterion result passed;
     * - there are zero recorded deviations (a deviation means failed,
     *   per AETS-012 §8, never conditionally passed);
     * - the CTO review field is non-empty;
     * - the Accounting Domain Reviewer approval field is non-empty —
     *   currently always `null` for this product, so this alone
     *   already keeps every record this class can produce today
     *   correctly uncertified; and
     * - if a Founder approval was declared required for this run, that
     *   field is also non-empty.
     */
    public function isCertifiedPassing(): bool
    {
        if (trim($this->ctoReviewedBy) === '') {
            return false;
        }

        if ($this->accountingDomainReviewerApprovedBy === null || trim($this->accountingDomainReviewerApprovedBy) === '') {
            return false;
        }

        if ($this->founderApprovalRequired && ($this->founderApprovedBy === null || trim($this->founderApprovedBy) === '')) {
            return false;
        }

        if ($this->deviations !== []) {
            return false;
        }

        if ($this->testRunCounts->hasAnyFailure()) {
            return false;
        }

        foreach ($this->executedCommands as $executedCommand) {
            if (! $executedCommand->succeeded()) {
                return false;
            }
        }

        foreach ($this->criterionResults as $criterionResult) {
            if (! $criterionResult->isPassed()) {
                return false;
            }
        }

        return true;
    }
}
