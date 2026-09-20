<?php

declare(strict_types=1);

namespace App\Infrastructure\Workspace;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Workspace\CommandType;
use App\Domain\Workspace\Exception\ProposalNotFoundException;
use App\Domain\Workspace\Proposal;
use App\Domain\Workspace\ProposalId;
use App\Domain\Workspace\ProposalProducerType;
use App\Domain\Workspace\TaskId;
use App\Http\Controllers\Api\TaskController;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the Proposal entity (ADR-0009, WTS-001
 * §5), through the production `proposals` table.
 */
final class ProposalRepository
{
    private const TABLE = 'proposals';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    public function record(Proposal $proposal): void
    {
        $this->connection->table(self::TABLE)->insert([
            'tenant_id' => $proposal->tenantId()->toString(),
            'proposal_id' => $proposal->id()->toString(),
            'task_id' => $proposal->taskId()->toString(),
            'command_type' => $proposal->commandType()->name,
            'amount' => $this->money->toPersistedAmount($proposal->amount()),
            'currency' => $this->money->toPersistedCurrency($proposal->amount()),
            'transaction_date' => $proposal->transactionDate()->format('Y-m-d'),
            'primary_account_id' => $proposal->primaryAccountId()->toString(),
            'secondary_account_id' => $proposal->secondaryAccountId()->toString(),
            'description' => $proposal->description(),
            'evidence_reference' => $proposal->evidenceReference()?->toString(),
            'confidence' => $proposal->confidence(),
            'producer_reference' => $proposal->producerReference()->toString(),
            'producer_type' => $proposal->producerType()->name,
            'created_at' => $proposal->createdAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function findById(TenantId $tenantId, ProposalId $id): ?Proposal
    {
        /** @var object{tenant_id: string, proposal_id: string, task_id: string, command_type: string, amount: int|string, currency: string, transaction_date: string, primary_account_id: string, secondary_account_id: string, description: string, evidence_reference: string|null, confidence: float|string|null, producer_reference: string, producer_type: string, created_at: string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('proposal_id', $id->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    /**
     * The current, live Proposal for a Task — the most recently
     * created one. WTS-001 §5: a Task may have more than one Proposal
     * only through `Superseded` chaining, never two simultaneously
     * live ones, so the newest row is always the authoritative one.
     *
     * @throws ProposalNotFoundException if the Task has no Proposal.
     */
    public function getCurrentForTask(TenantId $tenantId, TaskId $taskId): Proposal
    {
        /** @var object{tenant_id: string, proposal_id: string, task_id: string, command_type: string, amount: int|string, currency: string, transaction_date: string, primary_account_id: string, secondary_account_id: string, description: string, evidence_reference: string|null, confidence: float|string|null, producer_reference: string, producer_type: string, created_at: string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('task_id', $taskId->toString())
            ->orderByDesc('created_at')
            ->first();

        if ($row === null) {
            throw ProposalNotFoundException::forTask($taskId);
        }

        return $this->fromPersisted($row);
    }

    /**
     * The current Proposal for every Task the tenant has, in one query
     * — the same "most recent row per task_id is authoritative" rule
     * as {@see getCurrentForTask()}, applied to the whole tenant rather
     * than issuing that query once per Task (the Work Queue list's own
     * need — see {@see TaskController::index()}).
     *
     * @return array<string, Proposal> keyed by Task id
     */
    public function getCurrentForTenant(TenantId $tenantId): array
    {
        /** @var list<object{tenant_id: string, proposal_id: string, task_id: string, command_type: string, amount: int|string, currency: string, transaction_date: string, primary_account_id: string, secondary_account_id: string, description: string, evidence_reference: string|null, confidence: float|string|null, producer_reference: string, producer_type: string, created_at: string}> $rows */
        $rows = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->orderByDesc('created_at')
            ->get()
            ->all();

        $current = [];
        foreach ($rows as $row) {
            $current[$row->task_id] ??= $this->fromPersisted($row);
        }

        return $current;
    }

    /**
     * @param  object{tenant_id: string, proposal_id: string, task_id: string, command_type: string, amount: int|string, currency: string, transaction_date: string, primary_account_id: string, secondary_account_id: string, description: string, evidence_reference: string|null, confidence: float|string|null, producer_reference: string, producer_type: string, created_at: string}  $row
     */
    private function fromPersisted(object $row): Proposal
    {
        return new Proposal(
            ProposalId::of($row->proposal_id),
            TenantId::of($row->tenant_id),
            TaskId::of($row->task_id),
            self::commandTypeFromPersisted($row->command_type),
            $this->money->fromPersisted((string) $row->amount, $row->currency),
            new \DateTimeImmutable($row->transaction_date),
            AccountId::of($row->primary_account_id),
            AccountId::of($row->secondary_account_id),
            $row->description,
            $row->evidence_reference === null ? null : EvidenceReference::of($row->evidence_reference),
            $row->confidence === null ? null : (float) $row->confidence,
            ActorReference::of($row->producer_reference),
            self::producerTypeFromPersisted($row->producer_type),
            new \DateTimeImmutable($row->created_at),
        );
    }

    private static function commandTypeFromPersisted(string $value): CommandType
    {
        foreach (CommandType::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw new \RuntimeException(sprintf('Unrecognized persisted Proposal command_type "%s".', $value));
    }

    private static function producerTypeFromPersisted(string $value): ProposalProducerType
    {
        foreach (ProposalProducerType::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw new \RuntimeException(sprintf('Unrecognized persisted Proposal producer_type "%s".', $value));
    }
}
