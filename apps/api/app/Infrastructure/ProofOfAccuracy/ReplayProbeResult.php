<?php

declare(strict_types=1);

namespace App\Infrastructure\ProofOfAccuracy;

use App\Domain\ProofOfAccuracy\ExecutedCommandRecord;

/**
 * The outcome of {@see GoldenDatasetScenarioRunner::runReplayProbe()}
 * — whether each re-submitted command was recognized as an exact
 * replay (POA-006), alongside the {@see ExecutedCommandRecord}s for
 * the certification record's own audit trail.
 */
final class ReplayProbeResult
{
    /**
     * @param  list<ExecutedCommandRecord>  $records
     */
    public function __construct(
        private readonly array $records,
        private readonly ?bool $expenseWasReplay,
        private readonly ?bool $importWasReplay,
    ) {}

    /**
     * @return list<ExecutedCommandRecord>
     */
    public function records(): array
    {
        return $this->records;
    }

    public function expenseWasReplay(): ?bool
    {
        return $this->expenseWasReplay;
    }

    public function importWasReplay(): ?bool
    {
        return $this->importWasReplay;
    }
}
