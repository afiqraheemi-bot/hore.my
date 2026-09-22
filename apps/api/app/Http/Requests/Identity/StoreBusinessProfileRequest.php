<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Models\BusinessProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBusinessProfileRequest extends FormRequest
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
            'legal_name' => ['required', 'string', 'max:255'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', Rule::in(BusinessProfile::STATES)],
            'postcode' => ['required', 'string', 'regex:/^\d{5}$/'],
            'business_type' => ['required', 'string', Rule::in(BusinessProfile::BUSINESS_TYPES)],
            'financial_year_start_month' => ['required', 'integer', 'between:1,12'],
            'msic_code' => ['sometimes', 'nullable', 'string', 'regex:/^\d{5}$/'],
            'sst_registration_number' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
