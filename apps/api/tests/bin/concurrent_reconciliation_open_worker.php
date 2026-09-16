<?php

declare(strict_types=1);

use App\Domain\Accounting\Money\Currency;
use App\Domain\Banking\BankAccountId;
use App\Domain\Accounting\Money\Money;
use App\Domain\Banking\ReconciliationService;
use App\Domain\Shared\Tenancy\TenantId;
use Illuminate\Contracts\Console\Kernel;

/**
 * Standalone worker for real two-process Reconciliation-opening races
 * (AETS-008 §12.4, `BNK-015`).
 *
 * Usage: php concurrent_reconciliation_open_worker.php <tenantId>
 * <bankAccountId> <periodStart> <periodEnd> <readyFile> <goFile> <resultFile>
 */
[, $tenantIdValue, $bankAccountIdValue, $periodStart, $periodEnd, $readyFile, $goFile, $resultFile] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$tenantId = TenantId::of($tenantIdValue);
$bankAccountId = BankAccountId::of($bankAccountIdValue);
$service = $app->make(ReconciliationService::class);
$myr = Currency::of('MYR');

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
    $reconciliation = $service->open(
        $tenantId,
        $bankAccountId,
        new DateTimeImmutable($periodStart),
        new DateTimeImmutable($periodEnd),
        Money::fromDecimalString('1000.00', $myr),
        Money::fromDecimalString('1000.00', $myr),
    );

    file_put_contents($resultFile, json_encode([
        'status' => 'success',
        'id' => $reconciliation->id()->toString(),
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
