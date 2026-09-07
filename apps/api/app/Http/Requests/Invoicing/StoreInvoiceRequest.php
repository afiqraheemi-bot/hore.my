<?php

declare(strict_types=1);

namespace App\Http\Requests\Invoicing;

use Illuminate\Foundation\Http\FormRequest;

final class StoreInvoiceRequest extends FormRequest
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
            'customer_id' => ['required', 'string', 'max:64'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'receivable_account_id' => ['required', 'string', 'max:64'],
            'revenue_account_id' => ['required', 'string', 'max:64'],
            'lines' => ['sometimes', 'array', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'string', 'regex:/^\d{1,15}\.\d{2}$/'],
        ];
    }
}
