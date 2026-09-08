<?php

declare(strict_types=1);

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Workspace\TaskId;
use App\Domain\Workspace\TaskService;

/**
 * Standalone worker process for a genuine two-process concurrency
 * proof of {@see TaskService::resume()}'s TSK-004 guard extended to
 * crash recovery: two concurrent recovery attempts against the same
 * Task stranded in `Executing` must never both succeed. Mirrors
 * {@see \Tests\bin\concurrent_task_approve_worker}'s own reasoning and
 * synchronization technique exactly.
 *
 * Usage: `php concurrent_task_resume_worker.php <tenantId> <taskId>
 * <actor> <readyFile> <goFile> <resultFile>`. Writes a JSON result (or
 * JSON error) to `<resultFile>`; writes nothing to stdout.
 */
[, $tenantIdStr, $taskIdStr, $actorStr, $readyFile, $goFile, $resultFile] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
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
    $task = $service->resume($tenantId, $taskId, $actor);

    file_put_contents($resultFile, json_encode(['state' => $task->state()->name]));
} catch (Throwable $e) {
    file_put_contents($resultFile, json_encode([
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]));
}
