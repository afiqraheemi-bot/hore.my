<?php

declare(strict_types=1);

namespace App\Infrastructure\Workspace;

use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Workspace\CommandType;
use App\Domain\Workspace\TaskDraft;
use App\Domain\Workspace\TaskId;
use App\Http\Controllers\Api\TaskController;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for {@see TaskDraft} (WTS-001 v3.0.0,
 * TSK-013), through the production `task_drafts` table.
 */
final class TaskDraftRepository
{
    private const TABLE = 'task_drafts';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    public function record(TaskDraft $draft): void
    {
        $this->connection->table(self::TABLE)->insert([
            'tenant_id' => $draft->tenantId()->toString(),
            'task_id' => $draft->taskId()->toString(),
            'command_type' => $draft->commandType()->name,
            'amount' => $this->money->toPersistedAmount($draft->amount()),
            'currency' => $this->money->toPersistedCurrency($draft->amount()),
            'transaction_date' => $draft->transactionDate()->format('Y-m-d'),
            'description' => $draft->description(),
            'evidence_reference' => $draft->evidenceReference()?->toString(),
            'created_at' => $draft->createdAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function findByTask(TenantId $tenantId, TaskId $taskId): ?TaskDraft
    {
        /** @var object{tenant_id: string, task_id: string, command_type: string, amount: int|string, currency: string, transaction_date: string, description: string, evidence_reference: string|null, created_at: string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('task_id', $taskId->toString())
            ->first();

        return $row === null ? null : $this->fromPersisted($row);
    }

    /**
     * Every Draft the tenant has, in one query — the Work Queue list's
     * own need (see {@see TaskController::index()})
     * to avoid issuing {@see findByTask()} once per Task.
     *
     * @return array<string, TaskDraft> keyed by Task id
     */
    public function findAllForTenant(TenantId $tenantId): array
    {
        /** @var list<object{tenant_id: string, task_id: string, command_type: string, amount: int|string, currency: string, transaction_date: string, description: string, evidence_reference: string|null, created_at: string}> $rows */
        $rows = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->get()
            ->all();

        $drafts = [];
        foreach ($rows as $row) {
            $drafts[$row->task_id] = $this->fromPersisted($row);
        }

        return $drafts;
    }

    /**
     * @throws \RuntimeException if a Task in `NeedsInformation` has no
     *                           Draft — an invariant violation of this
     *                           module's own atomic writes, never a
     *                           legitimate runtime outcome.
     */
    public function getByTask(TenantId $tenantId, TaskId $taskId): TaskDraft
    {
        return $this->findByTask($tenantId, $taskId)
            ?? throw new \RuntimeException(sprintf('Task "%s" has no Task Draft.', $taskId->toString()));
    }

    /**
     * @param  object{tenant_id: string, task_id: string, command_type: string, amount: int|string, currency: string, transaction_date: string, description: string, evidence_reference: string|null, created_at: string}  $row
     */
    private function fromPersisted(object $row): TaskDraft
    {
        return new TaskDraft(
            TenantId::of($row->tenant_id),
            TaskId::of($row->task_id),
            self::commandTypeFromPersisted($row->command_type),
            $this->money->fromPersisted((string) $row->amount, $row->currency),
            new \DateTimeImmutable($row->transaction_date),
            $row->description,
            $row->evidence_reference === null ? null : EvidenceReference::of($row->evidence_reference),
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

        throw new \RuntimeException(sprintf('Unrecognized persisted Task Draft command_type "%s".', $value));
    }
}
