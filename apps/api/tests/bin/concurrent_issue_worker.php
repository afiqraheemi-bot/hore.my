<?php

declare(strict_types=1);

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceIssuingService;
use App\Domain\Shared\Tenancy\TenantId;
use App\Http\Support\DeterministicIdempotentId;

/**
 * Standalone worker process for a genuine two-process concurrency
 * regression test of {@see InvoiceIssuingService::issue()}
 * (P0-1: an external audit found that an earlier version of that
 * class read the Invoice and ran its Draft/Issued checks *before*
 * opening a transaction or taking any row lock — two concurrent Issue
 * attempts against the same Draft Invoice, with different Idempotency
 * Keys, could each observe `Draft` and each post their own Journal).
 *
 * PHPUnit is single-threaded and this codebase's own `Tests\TestCase`
 * performs no per-test transaction rollback (real Postgres commits,
 * per this codebase's established convention) — a genuine race
 * between two overlapping `issue()` calls cannot be reproduced inside
 * one PHPUnit process. This script is spawned twice, as two real OS
 * processes, by `InvoiceIssuingServiceIntegrationTest` — each
 * independently bootstraps Laravel, resolves the real
 * `InvoiceIssuingService` from the container, and calls `issue()`
 * against the *same* already-persisted Draft Invoice with a
 * *different* Idempotency Key, exactly the double-click/racing-retry
 * scenario the audit described.
 *
 * **Synchronization**: both workers `touch()` their own ready-file,
 * then busy-wait for a shared go-file the parent test process creates
 * only once *both* ready-files exist — so both workers call `issue()`
 * as close to simultaneously as OS scheduling allows, rather than one
 * trivially finishing before the other even starts.
 *
 * **`Illuminate\Contracts\Console\Kernel` is referenced by its fully
 * qualified name below, deliberately never `use`-imported, and this
 * whole directory is excluded from Pint in `pint.json`.** This is a
 * plain top-level script, not a class — an unqualified `Kernel::class`
 * only resolves correctly if its `use` import appears *before* that
 * reference in file order (unlike inside a class body, where `use`
 * declarations always apply regardless of position). Pint's own
 * fixers (`ordered_imports`, and separately `fully_qualified_strict_types`
 * re-introducing exactly the `use` it had just been told to avoid)
 * broke this script silently twice in a row (a
 * `ReflectionException: Class "Kernel" does not exist`) before
 * `tests/bin` was excluded from Pint's scope entirely — if that
 * exclusion is ever removed, restore it rather than letting Pint
 * reformat this file.
 *
 * Usage: `php concurrent_issue_worker.php <tenantId> <invoiceId>
 * <idempotencyKey> <readyFile> <goFile> <resultFile>`. Writes a JSON
 * result (or JSON error) to `<resultFile>`; writes nothing to stdout.
 */
[, $tenantIdStr, $invoiceIdStr, $idemKeyStr, $readyFile, $goFile, $resultFile] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenantId = TenantId::of($tenantIdStr);
$invoiceId = InvoiceId::of($invoiceIdStr);
$idempotencyKey = IdempotencyKey::of($idemKeyStr);
$journalId = JournalId::of(DeterministicIdempotentId::derive($tenantId, $idempotencyKey, 'journal'));

$service = $app->make(InvoiceIssuingService::class);

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
    $result = $service->issue(
        $tenantId,
        $invoiceId,
        $journalId,
        $idempotencyKey,
        ActorReference::of('user-concurrency-test'),
        new DateTimeImmutable('today'),
    );

    file_put_contents($resultFile, json_encode([
        'newly_issued' => $result->isNewlyIssued(),
        'journal_id' => $result->invoice()->journalId()?->toString(),
        'invoice_number' => $result->invoice()->invoiceNumber(),
    ]));
} catch (Throwable $e) {
    file_put_contents($resultFile, json_encode([
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]));
}
