<?php

declare(strict_types=1);

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Workspace\TaskId;
use App\Domain\Workspace\TaskService;
use App\Infrastructure\Workspace\TaskRepository;
use Illuminate\Contracts\Console\Kernel;

/**
 * Standalone worker process for a genuine two-process concurrency
 * proof of {@see TaskService::provideInformation()}'s TSK-004 guard,
 * extended per WTS-001 v3.0.0 TSK-013: two concurrent attempts to
 * complete the same Task's deferred Account decision must never both
 * succeed. Mirrors `concurrent_task_resume_worker.php`'s own reasoning
 * and synchronization technique exactly — both callers already
 * observe the same `NeedsInformation` state, so the guard is
 * {@see TaskRepository::getByIdForUpdate()}'s
 * pessimistic row lock, not an optimistic compare-and-swap.
 *
 * Usage: `php concurrent_task_provide_information_worker.php <tenantId>
 * <taskId> <primaryAccountId> <secondaryAccountId> <actor> <readyFile>
 * <goFile> <resultFile>`. Writes a JSON result (or JSON error) to
 * `<resultFile>`; writes nothing to stdout.
 */
[, $tenantIdStr, $taskIdStr, $primaryAccountIdStr, $secondaryAccountIdStr, $actorStr, $readyFile, $goFile, $resultFile] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$tenantId = TenantId::of($tenantIdStr);
$taskId = TaskId::of($taskIdStr);
$actor = ActorReference::of($actorStr);

$service = $app->make(TaskService::class);

touch($readyFile);

$deadline = microtime(true) + 5.0;
while (! file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        file_put_contents($resultFile, json_encode(['error' => 'timed out waiting for go-file']));
        exit(1);
    }
    usleep(2000);
}

try {
    $task = $service->provideInformation($tenantId, $taskId, $actor, AccountId::of($primaryAccountIdStr), AccountId::of($secondaryAccountIdStr));

    file_put_contents($resultFile, json_encode(['state' => $task->state()->name]));
} catch (Throwable $e) {
    file_put_contents($resultFile, json_encode([
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]));
}
