<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Workspace;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Real-PostgreSQL proof for the Workspace table migrations. Each test
 * uses a dedicated schema so forward and reverse migration behavior
 * cannot disturb the shared application tables or development data.
 */
final class WorkspaceTablesMigrationTest extends TestCase
{
    private const SCHEMA = 'workspace_migration_test';

    /** @var list<\Closure(): void> */
    private array $rollbacks = [];

    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection('pgsql')->select('select 1');
            $this->databaseReady = true;
        } catch (\Throwable $exception) {
            $this->markTestSkipped('A real PostgreSQL instance is required: '.$exception->getMessage());
        }

        DB::connection('pgsql')->unprepared('drop schema if exists '.self::SCHEMA.' cascade');
        DB::connection('pgsql')->unprepared('create schema '.self::SCHEMA);
        DB::connection('pgsql')->unprepared('set search_path to '.self::SCHEMA);

        DB::connection('pgsql')->unprepared(
            'create table accounts (tenant_id varchar(64) not null, account_id varchar(64) not null, primary key (account_id), unique (tenant_id, account_id))',
        );
        DB::connection('pgsql')->unprepared(
            'create table journals (tenant_id varchar(64) not null, journal_id varchar(64) not null, primary key (journal_id), unique (tenant_id, journal_id))',
        );

        foreach ([
            'database/migrations/2026_09_08_010000_create_tasks_table.php',
            'database/migrations/2026_09_08_020000_create_proposals_table.php',
            'database/migrations/2026_09_08_030000_create_task_transitions_table.php',
            'database/migrations/2026_09_08_035000_add_transition_sequence_to_task_transitions_table.php',
        ] as $path) {
            $migration = require base_path($path);
            if (! is_callable([$migration, 'up']) || ! is_callable([$migration, 'down'])) {
                throw new \LogicException("Migration {$path} must expose up() and down().");
            }

            $up = \Closure::fromCallable([$migration, 'up']);
            $up();
            $this->rollbacks[] = \Closure::fromCallable([$migration, 'down']);
        }
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            DB::connection('pgsql')->unprepared('set search_path to public');
            DB::connection('pgsql')->unprepared('drop schema if exists '.self::SCHEMA.' cascade');
        }

        parent::tearDown();
    }

    public function test_workspace_migrations_apply_and_reverse_cleanly(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasColumns('tasks', [
            'tenant_id', 'task_id', 'state', 'result_journal_id', 'failure_reason', 'completed_at',
        ]));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns('proposals', [
            'tenant_id', 'proposal_id', 'task_id', 'command_type', 'amount', 'producer_type',
        ]));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns('task_transitions', [
            'transition_id', 'tenant_id', 'task_id', 'from_state', 'to_state', 'transition_sequence',
        ]));

        foreach (array_reverse($this->rollbacks) as $rollback) {
            $rollback();
        }

        $this->assertFalse(Schema::connection('pgsql')->hasTable('task_transitions'));
        $this->assertFalse(Schema::connection('pgsql')->hasTable('proposals'));
        $this->assertFalse(Schema::connection('pgsql')->hasTable('tasks'));
    }

    public function test_task_rejects_a_noncanonical_state(): void
    {
        $this->expectException(QueryException::class);
        $this->insertTask('tenant-a', 'task-invalid', 'NotAState');
    }

    public function test_proposal_rejects_a_noncanonical_command_type(): void
    {
        $this->seedTaskAndAccounts();

        $this->expectException(QueryException::class);
        $this->insertProposal(commandType: 'NotACommand');
    }

    public function test_proposal_rejects_a_noncanonical_producer_type(): void
    {
        $this->seedTaskAndAccounts();

        $this->expectException(QueryException::class);
        $this->insertProposal(producerType: 'NotAProducer');
    }

    public function test_transition_rejects_a_noncanonical_to_state(): void
    {
        $this->seedTaskAndAccounts();

        $this->expectException(QueryException::class);
        DB::connection('pgsql')->table('task_transitions')->insert([
            'transition_id' => 'transition-invalid',
            'tenant_id' => 'tenant-a',
            'task_id' => 'task-a',
            'actor' => 'actor-a',
            'from_state' => 'Received',
            'to_state' => 'NotAState',
        ]);
    }

    public function test_transition_rejects_a_noncanonical_from_state(): void
    {
        $this->seedTaskAndAccounts();

        $this->expectException(QueryException::class);
        DB::connection('pgsql')->table('task_transitions')->insert([
            'transition_id' => 'transition-invalid-from',
            'tenant_id' => 'tenant-a',
            'task_id' => 'task-a',
            'actor' => 'actor-a',
            'from_state' => 'NotAState',
            'to_state' => 'NeedsReview',
        ]);
    }

    public function test_proposal_cannot_reference_another_tenants_task(): void
    {
        $this->seedTaskAndAccounts();
        $this->insertTask('tenant-b', 'task-b', 'NeedsReview');

        $this->expectException(QueryException::class);
        $this->insertProposal(tenantId: 'tenant-b', taskId: 'task-a');
    }

    public function test_proposal_cannot_reference_another_tenants_account(): void
    {
        $this->seedTaskAndAccounts();
        $this->insertTask('tenant-b', 'task-b', 'NeedsReview');

        $this->expectException(QueryException::class);
        $this->insertProposal(tenantId: 'tenant-b', taskId: 'task-b');
    }

    public function test_task_cannot_reference_another_tenants_journal(): void
    {
        DB::connection('pgsql')->table('journals')->insert([
            'tenant_id' => 'tenant-b',
            'journal_id' => 'journal-b',
        ]);

        $this->expectException(QueryException::class);
        DB::connection('pgsql')->table('tasks')->insert([
            'tenant_id' => 'tenant-a',
            'task_id' => 'task-cross-tenant-journal',
            'state' => 'Completed',
            'result_journal_id' => 'journal-b',
        ]);
    }

    public function test_transition_sequence_is_generated_always_and_cannot_be_caller_supplied(): void
    {
        $this->seedTaskAndAccounts();

        $this->expectException(QueryException::class);
        DB::connection('pgsql')->table('task_transitions')->insert([
            'transition_id' => 'transition-explicit-sequence',
            'tenant_id' => 'tenant-a',
            'task_id' => 'task-a',
            'actor' => 'actor-a',
            'from_state' => null,
            'to_state' => 'Received',
            'transition_sequence' => 999,
        ]);
    }

    private function seedTaskAndAccounts(): void
    {
        $this->insertTask('tenant-a', 'task-a', 'NeedsReview');

        DB::connection('pgsql')->table('accounts')->insert([
            ['tenant_id' => 'tenant-a', 'account_id' => 'account-a-primary'],
            ['tenant_id' => 'tenant-a', 'account_id' => 'account-a-secondary'],
        ]);
    }

    private function insertTask(string $tenantId, string $taskId, string $state): void
    {
        DB::connection('pgsql')->table('tasks')->insert([
            'tenant_id' => $tenantId,
            'task_id' => $taskId,
            'state' => $state,
        ]);
    }

    private function insertProposal(
        string $tenantId = 'tenant-a',
        string $taskId = 'task-a',
        string $commandType = 'Expense',
        string $producerType = 'Human',
    ): void {
        DB::connection('pgsql')->table('proposals')->insert([
            'tenant_id' => $tenantId,
            'proposal_id' => 'proposal-a',
            'task_id' => $taskId,
            'command_type' => $commandType,
            'amount' => 100,
            'currency' => 'MYR',
            'transaction_date' => '2026-09-09',
            'primary_account_id' => 'account-a-primary',
            'secondary_account_id' => 'account-a-secondary',
            'description' => 'Migration constraint proof',
            'producer_reference' => 'actor-a',
            'producer_type' => $producerType,
        ]);
    }
}
