<?php

declare(strict_types=1);

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Quotations\QuotationConversionService;
use App\Domain\Quotations\QuotationId;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * Standalone worker process for a genuine two-process concurrency
 * proof of {@see QuotationConversionService::convert()} (AETS-016,
 * QUO-008): two concurrent conversion attempts against the same
 * `Accepted` Quotation must never both create an Invoice. Mirrors
 * `tests/bin/concurrent_issue_worker.php`'s own reasoning and
 * synchronization technique exactly.
 *
 * **`Illuminate\Contracts\Console\Kernel` is referenced by its fully
 * qualified name below, deliberately never `use`-imported** — see
 * `concurrent_issue_worker.php`'s own docblock for why (a plain
 * top-level script, unlike a class body, only resolves an unqualified
 * name via a `use` that appears before it in file order; this whole
 * directory is excluded from Pint in `pint.json` for the same
 * reason).
 *
 * Usage: `php concurrent_quotation_convert_worker.php <tenantId>
 * <quotationId> <receivableAccountId> <revenueAccountId> <invoiceId>
 * <readyFile> <goFile> <resultFile>`. Writes a JSON result (or JSON
 * error) to `<resultFile>`; writes nothing to stdout.
 */
[, $tenantIdStr, $quotationIdStr, $receivableAccountIdStr, $revenueAccountIdStr, $invoiceIdStr, $readyFile, $goFile, $resultFile] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenantId = TenantId::of($tenantIdStr);
$quotationId = QuotationId::of($quotationIdStr);
$invoiceId = InvoiceId::of($invoiceIdStr);

$service = $app->make(QuotationConversionService::class);

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
    $invoice = $service->convert(
        $tenantId,
        $quotationId,
        $invoiceId,
        AccountId::of($receivableAccountIdStr),
        AccountId::of($revenueAccountIdStr),
        new DateTimeImmutable('2026-12-31'),
    );

    file_put_contents($resultFile, json_encode(['invoice_id' => $invoice->id()->toString()]));
} catch (Throwable $e) {
    file_put_contents($resultFile, json_encode([
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]));
}
