<?php

declare(strict_types=1);

namespace App\Http\Requests\Quotations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A Quotation carries no Account of its own (AETS-016 §2.2) — the
 * caller must supply both here, exactly as {@see
 * \App\Http\Requests\Invoicing\StoreInvoiceRequest} requires for an
 * ordinary Invoice draft.
 */
final class ConvertQuotationRequest extends FormRequest
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
            'receivable_account_id' => ['required', 'string', 'max:64'],
            'revenue_account_id' => ['required', 'string', 'max:64'],
            'due_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
