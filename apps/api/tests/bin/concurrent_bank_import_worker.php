<?php

declare(strict_types=1);

use App\Domain\Accounting\Money\Currency;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\BankStatementImportService;
use App\Domain\Shared\Tenancy\TenantId;
use Illuminate\Contracts\Console\Kernel;

/**
 * Standalone worker for real two-process Bank Statement import races.
 *
 * Usage: php concurrent_bank_import_worker.php <tenantId> <bankAccountId>
 * <filename> <base64Csv> <readyFile> <goFile> <resultFile>
 */
[, $tenantIdValue, $bankAccountIdValue, $filename, $encodedCsv, $readyFile, $goFile, $resultFile] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$csv = base64_decode($encodedCsv, true);
if ($csv === false) {
    file_put_contents($resultFile, json_encode(['error' => 'invalid base64 CSV']));
    exit(1);
}

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
    $result = $app->make(BankStatementImportService::class)->import(
        TenantId::of($tenantIdValue),
        BankAccountId::of($bankAccountIdValue),
        $filename,
        $csv,
        Currency::of('MYR'),
    );

    file_put_contents($resultFile, json_encode([
        'status' => 'success',
        'is_new_import' => $result->isNewImport(),
        'batch_id' => $result->importBatch()->id()->toString(),
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
}
