<?php

declare(strict_types=1);

namespace App\Http\Requests\Reporting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by Profit & Loss, General Ledger, and Evidence Index (all a
 * bounded Period).
 */
final class PeriodRequest extends FormRequest
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
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'format' => ['sometimes', 'string', 'in:json,csv,xlsx,pdf'],
        ];
    }
}
