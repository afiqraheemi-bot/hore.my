<?php

declare(strict_types=1);

namespace App\Http\Requests\Banking;

use App\Domain\Banking\CsvBankStatementParser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SEC-005 (input protection): the uploaded file is bounded by both
 * extension/MIME and size before {@see CsvBankStatementParser}
 * ever sees its content — a 5MB ceiling is generous for a CSV bank
 * statement (tens of thousands of rows) while still bounding worst-case
 * memory/parse cost from an arbitrary upload.
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
            'statement' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }
}
