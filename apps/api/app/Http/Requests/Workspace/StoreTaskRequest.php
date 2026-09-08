<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspace;

use App\Domain\Workspace\CommandType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTaskRequest extends FormRequest
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
            'command_type' => ['required', 'string', Rule::in(array_map(static fn (CommandType $type): string => $type->name, CommandType::cases()))],
            'amount' => ['required', 'string', 'regex:/^\d{1,15}\.\d{2}$/'],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'primary_account_id' => ['required', 'string', 'max:64'],
            'secondary_account_id' => ['required', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:255'],
            'evidence_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
