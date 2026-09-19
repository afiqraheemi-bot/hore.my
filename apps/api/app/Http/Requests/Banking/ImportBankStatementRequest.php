<?php

declare(strict_types=1);

namespace App\Http\Requests\Banking;

use App\Domain\Banking\CsvBankStatementParser;
use App\Domain\Banking\MaybankPdfBankStatementParser;
use App\Domain\Banking\XlsxBankStatementParser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SEC-005 (input protection): the uploaded file is bounded by both
 * extension/MIME and size before {@see CsvBankStatementParser}/
 * {@see XlsxBankStatementParser}/{@see MaybankPdfBankStatementParser}
 * ever sees its content — a 5MB ceiling is generous for a bank
 * statement (tens of thousands of CSV rows, or an XLSX/PDF of the
 * same) while still bounding worst-case memory/parse cost from an
 * arbitrary upload.
 *
 * **`xlsx` added 2026-09-16 (Import & Export, AETS-008 §5.1). `pdf`
 * added 2026-09-19 (AETS-008 §12.10, Maybank only).**
 */
final class ImportBankStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'statement' => ['required', 'file', 'mimes:csv,txt,xlsx,pdf', 'max:5120'],
        ];
    }
}
