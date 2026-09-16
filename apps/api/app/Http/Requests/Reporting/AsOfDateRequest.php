<?php

declare(strict_types=1);

namespace App\Http\Requests\Reporting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by Trial Balance and Balance Sheet (both an "as of" snapshot).
 */
final class AsOfDateRequest extends FormRequest
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
            'as_of' => ['required', 'date_format:Y-m-d'],
            'format' => ['sometimes', 'string', 'in:json,csv,xlsx'],
        ];
    }
}
