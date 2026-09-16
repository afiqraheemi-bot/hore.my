<?php

declare(strict_types=1);

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Workspace\CommandType;
use App\Domain\Workspace\TaskId;
use App\Domain\Workspace\TaskService;
use Illuminate\Contracts\Console\Kernel;

/**
 * Standalone worker process for a genuine two-process concurrency
 * proof of {@see TaskService::supersedeAndSubmitCorrection()} (WTS-001
 * v3.0.0, TSK-014): two concurrent correction attempts against the
 * same `NeedsReview` Task must never both succeed — exactly one
 * `supersede()` may win the optimistic compare-and-swap
 * {@see TaskService::applyTransition()}
 * already enforces (TSK-004), the same guard `reject()`/`approve()`
 * rely on. Mirrors `concurrent_task_approve_worker.php`'s own
 * synchronization technique exactly.
 *
 * Usage: `php concurrent_task_supersede_worker.php <tenantId>
 * <supersedesTaskId> <primaryAccountId> <secondaryAccountId> <actor>
 * <readyFile> <goFile> <resultFile>`. Each worker submits its own
 * correction under an Idempotency Key derived from `<actor>`, so the
 * two corrections are never themselves mistaken for a replay of one
 * another. Writes a JSON result (or JSON error) to `<resultFile>`;
 * writes nothing to stdout.
 */
[, $tenantIdStr, $supersedesTaskIdStr, $primaryAccountIdStr, $secondaryAccountIdStr, $actorStr, $readyFile, $goFile, $resultFile] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$tenantId = TenantId::of($tenantIdStr);
$supersedesTaskId = TaskId::of($supersedesTaskIdStr);
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
    $task = $service->supersedeAndSubmitCorrection(
        $tenantId,
        $supersedesTaskId,
        $actor,
        'Concurrent correction attempt.',
        IdempotencyKey::of('idem-supersede-'.$actorStr),
        CommandType::Expense,
        Money::fromDecimalString('75.00', Currency::of('MYR')),
        new DateTimeImmutable('2026-09-16'),
        AccountId::of($primaryAccountIdStr),
        AccountId::of($secondaryAccountIdStr),
        'Concurrent correction',
        null,
    );

    file_put_contents($resultFile, json_encode(['task_id' => $task->id()->toString(), 'state' => $task->state()->name]));
} catch (Throwable $e) {
    file_put_contents($resultFile, json_encode([
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]));
}
