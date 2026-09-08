<?php

declare(strict_types=1);

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Payments\AllocationService;
use App\Domain\Payments\PaymentAllocationId;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * Standalone worker process for a genuine two-process concurrency
 * regression test of {@see AllocationService::deallocate()} (P1-3
 * follow-up, 2026-09-11: an external audit found the original
 * `PaymentAllocationRepository::softDelete()` had no `WHERE deleted_at
 * IS NULL` guard and no affected-row check — two concurrent
 * deallocation attempts against the same allocation could each pass
 * `findById()`'s check before either wrote, then both execute the
 * `UPDATE` unconditionally, with the second silently overwriting the
 * first's `deleted_by_actor` — the row ends up deleted either way, but
 * the audit trail could record the wrong actor as having done it).
 *
 * Mirrors {@see \Tests\bin\concurrent_issue_worker}'s own reasoning
 * and synchronization technique exactly (see that file's own
 * docblock for why a genuine two-OS-process test is required here and
 * cannot be reproduced inside one single-threaded PHPUnit process).
 *
 * Usage: `php concurrent_deallocate_worker.php <tenantId>
 * <allocationId> <actor> <readyFile> <goFile> <resultFile>`. Writes a
 * JSON result (or JSON error) to `<resultFile>`; writes nothing to
 * stdout.
 */
[, $tenantIdStr, $allocationIdStr, $actorStr, $readyFile, $goFile, $resultFile] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenantId = TenantId::of($tenantIdStr);
$allocationId = PaymentAllocationId::of($allocationIdStr);
$actor = ActorReference::of($actorStr);

$service = $app->make(AllocationService::class);

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
    $service->deallocate($tenantId, $allocationId, $actor);

    file_put_contents($resultFile, json_encode(['deallocated' => true]));
} catch (Throwable $e) {
    file_put_contents($resultFile, json_encode([
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]));
}
