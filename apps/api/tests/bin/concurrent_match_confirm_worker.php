<?php

declare(strict_types=1);

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Banking\BankTransactionId;
use App\Domain\Banking\MatchingService;
use App\Domain\Shared\Tenancy\TenantId;
use Illuminate\Contracts\Console\Kernel;

/**
 * Standalone worker for real two-process Match-confirmation races
 * (AETS-008 §12.6/§12.2, `BNK-014`/`BNK-018`).
 *
 * Usage: php concurrent_match_confirm_worker.php <tenantId>
 * <bankTransactionId> <journalId> <actor> <readyFile> <goFile> <resultFile>
 */
[, $tenantIdValue, $bankTransactionIdValue, $journalIdValue, $actorValue, $readyFile, $goFile, $resultFile] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$tenantId = TenantId::of($tenantIdValue);
$bankTransactionId = BankTransactionId::of($bankTransactionIdValue);
$journalId = JournalId::of($journalIdValue);
$service = $app->make(MatchingService::class);

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
    $result = $service->confirm($tenantId, $bankTransactionId, $journalId, ActorReference::of($actorValue));

    file_put_contents($resultFile, json_encode([
        'status' => 'success',
        'is_new_match' => $result->isNewMatch(),
        'match_id' => $result->match()->id()->toString(),
        'journal_id' => $result->match()->journalId()->toString(),
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
