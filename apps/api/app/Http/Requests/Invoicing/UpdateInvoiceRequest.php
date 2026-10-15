<?php

declare(strict_types=1);

namespace App\Http\Requests\Invoicing;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateInvoiceRequest extends FormRequest
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
            'due_date' => ['required', 'date_format:Y-m-d'],
            'receivable_account_id' => ['required', 'string', 'max:64'],
            'revenue_account_id' => ['required', 'string', 'max:64'],
            'lines' => ['sometimes', 'array', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'string', 'regex:/^\d{1,15}\.\d{2}$/'],
            // Optional MyInvois data (AETS-013 v0.1.0 §6.1) — present only
            // for a Tenant who has started filling in MyInvois detail; a
            // line with none of these simply carries no MyInvois detail.
            'lines.*.myinvois_tax_type' => ['sometimes', 'nullable', 'string', 'in:01,02,03,04,05,06,E'],
            'lines.*.myinvois_tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'lines.*.myinvois_tax_amount' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,15}\.\d{2}$/'],
            'lines.*.myinvois_classification_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'lines.*.myinvois_unit_of_measure' => ['sometimes', 'nullable', 'string', 'max:16'],
        ];
    }
}
