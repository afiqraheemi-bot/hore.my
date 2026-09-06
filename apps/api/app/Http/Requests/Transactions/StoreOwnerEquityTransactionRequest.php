<?php

declare(strict_types=1);

namespace App\Http\Requests\Transactions;

use App\Domain\Transactions\OwnerEquity\OwnerEquityMovementType;
use App\Http\Controllers\Api\CapitalContributionController;
use App\Http\Controllers\Api\OwnerDrawingController;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared validation rules for both Owner Equity Transaction endpoints
 * (M15) — {@see CapitalContributionController}
 * and {@see OwnerDrawingController} — since a
 * Capital Contribution and a Drawing accept the exact same request
 * shape; only which endpoint is called (never a body field) decides the
 * {@see OwnerEquityMovementType}.
 */
final class StoreOwnerEquityTransactionRequest extends FormRequest
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
            'equity_account_id' => ['required', 'string', 'max:64'],
            'cash_account_id' => ['required', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:255'],
            'evidence_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
