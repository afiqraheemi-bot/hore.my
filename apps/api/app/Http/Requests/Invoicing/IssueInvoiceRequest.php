<?php

declare(strict_types=1);

namespace App\Http\Requests\Invoicing;

use App\Http\Controllers\Api\InvoiceController;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `issue_date` is required explicitly from the caller — never derived
 * from the server's own clock ({@see InvoiceController::issue()}'s
 * own docblock), mirroring every other Transactions module's own
 * `transaction_date`/`payment_date` contract (Income, Expense,
 * Transfer, Payment all require this the same way).
 */
final class IssueInvoiceRequest extends FormRequest
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
