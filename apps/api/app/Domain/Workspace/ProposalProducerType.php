<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

/**
 * Who produced a Proposal (WTS-000 §5, WTS-001 §5). Every Proposal
 * created before AI Orchestration exists (Phase D, the "Non-AI
 * Workflow Shell") is `Human` — `AI` is reserved for the future
 * AI-Produced Proposal Intake Contract (WTS-004) and must not be
 * producible by anything but an authorized AI Orchestration producer
 * once that module exists (WTS-000 §5).
 */
enum ProposalProducerType
{
    case Human;
    case AI;
}
