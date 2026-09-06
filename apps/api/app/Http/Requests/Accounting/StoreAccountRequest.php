<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAccountRequest extends FormRequest
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
            'account_code' => ['required', 'string', 'max:64'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', 'string', 'in:Asset,Liability,Equity,Revenue,Expense'],
            'posting_eligible' => ['sometimes', 'boolean'],
        ];
    }
}
