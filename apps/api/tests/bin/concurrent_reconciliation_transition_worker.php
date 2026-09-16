<?php

declare(strict_types=1);

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Banking\ReconciliationId;
use App\Domain\Banking\ReconciliationService;
use App\Domain\Shared\Tenancy\TenantId;
use Illuminate\Contracts\Console\Kernel;

/**
 * Standalone worker for real two-process Reconciliation lifecycle races.
 *
 * Usage: php concurrent_reconciliation_transition_worker.php <tenantId>
 * <reconciliationId> <action> <actor> <readyFile> <goFile> <resultFile>
 */
[, $tenantIdValue, $reconciliationIdValue, $action, $actorValue, $readyFile, $goFile, $resultFile] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$tenantId = TenantId::of($tenantIdValue);
$reconciliationId = ReconciliationId::of($reconciliationIdValue);
$service = $app->make(ReconciliationService::class);

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
    $reconciliation = match ($action) {
        'start-review' => $service->startReview($tenantId, $reconciliationId),
        'mark-balanced' => $service->markBalanced($tenantId, $reconciliationId),
        'complete' => $service->complete($tenantId, $reconciliationId),
        'reopen' => $service->reopen(
            $tenantId,
            $reconciliationId,
            'Concurrent integrity proof',
            ActorReference::of($actorValue),
        ),
        default => throw new InvalidArgumentException(sprintf('Unsupported action "%s".', $action)),
    };

    file_put_contents($resultFile, json_encode(['state' => $reconciliation->state()->name], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
