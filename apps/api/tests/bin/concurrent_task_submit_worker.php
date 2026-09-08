<?php

declare(strict_types=1);

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Workspace\CommandType;
use App\Domain\Workspace\TaskService;

/**
 * Standalone worker process for a genuine two-process concurrency
 * proof that {@see TaskService::submit()} handles a *first-submission*
 * race safely: two concurrent requests under the same, never-before-
 * used Idempotency Key both observing "no existing Task yet" and both
 * attempting to create one — mirrors
 * {@see \Tests\bin\concurrent_task_approve_worker}'s own reasoning and
 * synchronization technique, but races `submit()` itself rather than
 * a transition on an already-existing Task.
 *
 * Usage: `php concurrent_task_submit_worker.php <tenantId>
 * <idempotencyKey> <amount> <primaryAccountId> <secondaryAccountId>
 * <actor> <readyFile> <goFile> <resultFile>`. Writes a JSON result (or
 * JSON error) to `<resultFile>`; writes nothing to stdout.
 */
[, $tenantIdStr, $idempotencyKeyStr, $amountStr, $primaryAccountIdStr, $secondaryAccountIdStr, $actorStr, $readyFile, $goFile, $resultFile] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenantId = TenantId::of($tenantIdStr);
$idempotencyKey = IdempotencyKey::of($idempotencyKeyStr);
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
    $task = $service->submit(
        $tenantId,
        $actor,
        $idempotencyKey,
        CommandType::Expense,
        Money::fromDecimalString($amountStr, Currency::of('MYR')),
        new DateTimeImmutable('2026-09-08'),
        AccountId::of($primaryAccountIdStr),
        AccountId::of($secondaryAccountIdStr),
        'concurrent submission race test',
        null,
    );

    file_put_contents($resultFile, json_encode(['task_id' => $task->id()->toString(), 'state' => $task->state()->name]));
} catch (Throwable $e) {
    file_put_contents($resultFile, json_encode([
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]));
}
