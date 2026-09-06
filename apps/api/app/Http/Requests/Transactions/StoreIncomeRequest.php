<?php

declare(strict_types=1);

namespace App\Http\Requests\Transactions;

use Illuminate\Foundation\Http\FormRequest;

final class StoreIncomeRequest extends FormRequest
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
            'amount' => ['required', 'string', 'regex:/^\d{1,15}\.\d{2}$/'],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'income_account_id' => ['required', 'string', 'max:64'],
            'deposit_account_id' => ['required', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:255'],
            'evidence_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
