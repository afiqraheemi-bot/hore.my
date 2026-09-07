<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

final class StorePaymentRequest extends FormRequest
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
            'amount' => ['required', 'string', 'regex:/^\d{1,15}\.\d{2}$/'],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'deposit_account_id' => ['required', 'string', 'max:64'],
            'receivable_account_id' => ['required', 'string', 'max:64'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
