<?php

declare(strict_types=1);

namespace App\Http\Requests\Banking;

use Illuminate\Foundation\Http\FormRequest;

final class StoreBankAccountRequest extends FormRequest
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
            'linked_account_id' => ['required', 'string', 'max:64'],
            'bank_name' => ['required', 'string', 'max:100'],
            'account_number_last4' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}$/'],
        ];
    }
}
