<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

final class ClosePeriodRequest extends FormRequest
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
            'closed_through_date' => ['required', 'date_format:Y-m-d'],
            'retained_earnings_account_id' => ['required', 'string', 'max:64'],
        ];
    }
}
