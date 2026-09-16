<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Workspace;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Workspace\Exception\InvalidTaskStateTransitionException;
use App\Domain\Workspace\Task;
use App\Domain\Workspace\TaskId;
use App\Domain\Workspace\TaskState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Domain\Workspace\TaskServiceIntegrationTest;
use Tests\Unit\Domain\Banking\ReconciliationTest;

/**
 * Covers the {@see Task} aggregate's own state-machine invariants
 * (ADR-0009, WTS-001 §3–§4, TSK-002) purely at the entity level —
 * mirrors {@see ReconciliationTest} exactly.
 * Persistence, the TSK-004 concurrency guard, and the TSK-003 Proposal
 * translation are covered separately by
 * {@see TaskServiceIntegrationTest}.
 */
final class TaskTest extends TestCase
{
    private function received(): Task
    {
        return Task::receive(TaskId::of('task-0001'), TenantId::of('tenant-0001'), new \DateTimeImmutable('2026-09-08'));
    }

    public function test_receive_starts_in_received_state(): void
    {
        $this->assertSame(TaskState::Received, $this->received()->state());
    }

    public function test_full_happy_path_to_completed(): void
    {
        $completedAt = new \DateTimeImmutable('2026-09-08 10:00:00');
        $journalId = JournalId::of('journal-0001');

        $task = $this->received()
            ->startProcessing()
            ->moveToReview()
            ->approve()
            ->startExecuting()
            ->complete($journalId, $completedAt);

        $this->assertSame(TaskState::Completed, $task->state());
        $this->assertTrue($task->resultJournalId()?->equals($journalId));
        $this->assertSame($completedAt, $task->completedAt());
        $this->assertNull($task->failureReason());
    }

    public function test_needs_information_and_resume_processing_round_trip(): void
    {
        $task = $this->received()->startProcessing()->needsInformation();
        $this->assertSame(TaskState::NeedsInformation, $task->state());

        $resumed = $task->resumeProcessing();
        $this->assertSame(TaskState::Processing, $resumed->state());
    }

    public function test_executing_can_fail_with_a_reason(): void
    {
        $task = $this->received()->startProcessing()->moveToReview()->approve()->startExecuting()->fail('Rejected closed period.');

        $this->assertSame(TaskState::Failed, $task->state());
        $this->assertSame('Rejected closed period.', $task->failureReason());
        $this->assertNull($task->resultJournalId());
    }

    public function test_needs_review_can_be_rejected(): void
    {
        $task = $this->received()->startProcessing()->moveToReview()->reject();

        $this->assertSame(TaskState::Rejected, $task->state());
    }

    public function test_needs_review_can_be_superseded(): void
    {
        $task = $this->received()->startProcessing()->moveToReview()->supersede();

        $this->assertSame(TaskState::Superseded, $task->state());
    }

    public function test_a_task_created_without_supersedes_task_id_carries_none(): void
    {
        $this->assertNull($this->received()->supersedesTaskId());
    }

    public function test_a_correction_task_carries_the_opaque_supersedes_task_id_it_was_created_with(): void
    {
        $supersedesTaskId = TaskId::of('task-0000');
        $task = Task::receive(TaskId::of('task-0001'), TenantId::of('tenant-0001'), new \DateTimeImmutable('2026-09-08'), $supersedesTaskId);

        $this->assertNotNull($task->supersedesTaskId());
        $this->assertTrue($task->supersedesTaskId()->equals($supersedesTaskId));
    }

    public function test_supersedes_task_id_survives_every_transition(): void
    {
        $supersedesTaskId = TaskId::of('task-0000');
        $completedAt = new \DateTimeImmutable('2026-09-08 10:00:00');
        $task = Task::receive(TaskId::of('task-0001'), TenantId::of('tenant-0001'), new \DateTimeImmutable('2026-09-08'), $supersedesTaskId)
            ->startProcessing()
            ->moveToReview()
            ->approve()
            ->startExecuting()
            ->complete(JournalId::of('journal-0001'), $completedAt);

        $this->assertNotNull($task->supersedesTaskId());
        $this->assertTrue($task->supersedesTaskId()->equals($supersedesTaskId));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function cancellableStateProvider(): array
    {
        return [
            ['received'],
            ['processing'],
            ['needsInformation'],
            ['needsReview'],
        ];
    }

    #[DataProvider('cancellableStateProvider')]
    public function test_cancel_is_valid_from_every_pre_approval_state(string $stateFactory): void
    {
        $task = match ($stateFactory) {
            'received' => $this->received(),
            'processing' => $this->received()->startProcessing(),
            'needsInformation' => $this->received()->startProcessing()->needsInformation(),
            'needsReview' => $this->received()->startProcessing()->moveToReview(),
        };

        $this->assertSame(TaskState::Cancelled, $task->cancel()->state());
    }

    public function test_cancel_is_rejected_once_approved(): void
    {
        $this->expectException(InvalidTaskStateTransitionException::class);

        $this->received()->startProcessing()->moveToReview()->approve()->cancel();
    }

    public function test_start_processing_only_valid_from_received(): void
    {
        $this->expectException(InvalidTaskStateTransitionException::class);

        $this->received()->startProcessing()->startProcessing();
    }

    public function test_move_to_review_only_valid_from_processing(): void
    {
        $this->expectException(InvalidTaskStateTransitionException::class);

        $this->received()->moveToReview();
    }

    public function test_approve_only_valid_from_needs_review(): void
    {
        $this->expectException(InvalidTaskStateTransitionException::class);

        $this->received()->startProcessing()->approve();
    }

    public function test_start_executing_only_valid_from_approved(): void
    {
        $this->expectException(InvalidTaskStateTransitionException::class);

        $this->received()->startProcessing()->moveToReview()->startExecuting();
    }

    public function test_complete_only_valid_from_executing(): void
    {
        $this->expectException(InvalidTaskStateTransitionException::class);

        $this->received()->startProcessing()->moveToReview()->approve()
            ->complete(JournalId::of('journal-0001'), new \DateTimeImmutable);
    }

    public function test_fail_only_valid_from_executing(): void
    {
        $this->expectException(InvalidTaskStateTransitionException::class);

        $this->received()->fail('not executing');
    }

    public function test_reject_only_valid_from_needs_review(): void
    {
        $this->expectException(InvalidTaskStateTransitionException::class);

        $this->received()->reject();
    }

    public function test_transitions_never_mutate_the_original_instance(): void
    {
        $received = $this->received();
        $received->startProcessing();

        $this->assertSame(TaskState::Received, $received->state());
    }

    /**
     * @return list<array{0: TaskState, 1: bool}>
     */
    public static function terminalStateProvider(): array
    {
        return [
            [TaskState::Received, false],
            [TaskState::Processing, false],
            [TaskState::NeedsInformation, false],
            [TaskState::NeedsReview, false],
            [TaskState::Approved, false],
            [TaskState::Executing, false],
            [TaskState::Completed, true],
            [TaskState::Rejected, true],
            [TaskState::Failed, true],
            [TaskState::Cancelled, true],
            [TaskState::Superseded, true],
        ];
    }

    #[DataProvider('terminalStateProvider')]
    public function test_is_terminal_matches_wts_001_section_6_tsk_009(TaskState $state, bool $expectedTerminal): void
    {
        $this->assertSame($expectedTerminal, $state->isTerminal());
    }
}
