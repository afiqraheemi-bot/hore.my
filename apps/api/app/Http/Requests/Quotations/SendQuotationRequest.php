<?php

declare(strict_types=1);

namespace App\Http\Requests\Quotations;

use App\Http\Requests\Invoicing\IssueInvoiceRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `issue_date` is required explicitly from the caller — never derived
 * from the server's own clock, mirroring
 * {@see IssueInvoiceRequest}'s own
 * identical contract.
 */
final class SendQuotationRequest extends FormRequest
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
            'issue_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
