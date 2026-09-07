<?php

declare(strict_types=1);

namespace App\Http\Requests\Banking;

use Illuminate\Foundation\Http\FormRequest;

final class OpenReconciliationRequest extends FormRequest
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
            'opening_balance' => ['required', 'string', 'regex:/^\d{1,15}\.\d{2}$/'],
            'closing_balance' => ['required', 'string', 'regex:/^\d{1,15}\.\d{2}$/'],
        ];
    }
}
