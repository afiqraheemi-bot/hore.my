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

    /**
     * A dedicated fixed-layout parser for Maybank's own native PDF
     * e-statement (AETS-008 §12.10, decided 2026-09-19) — not a second
     * schema and not configurable mapping; the identical fixed v1
     * schema §5 already describes, translated from Maybank's own
     * layout by {@see MaybankPdfBankStatementParser}. The only PDF
     * format today, so `.pdf` unambiguously resolves to this case at
     * the HTTP boundary; a second PDF-issuing bank will need an
     * explicit disambiguation signal there, not content-sniffing.
     */
    case MaybankPdf;
}
