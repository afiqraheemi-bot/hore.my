<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Http\Controllers\Api\BankStatementImportController;

/**
 * Which parser {@see BankStatementImportService::import()} dispatches
 * to (Import & Export, AETS-008 §5.1) — determined by the HTTP
 * boundary from the uploaded file's own extension
 * ({@see BankStatementImportController}),
 * never sniffed from file content: an explicit, caller-supplied
 * choice, mirroring this codebase's own established preference for
 * explicit values over inferred ones wherever a wrong inference would
 * be silently accepted rather than loudly rejected.
 */
enum BankStatementFileFormat
{
    case Csv;
    case Xlsx;
}
