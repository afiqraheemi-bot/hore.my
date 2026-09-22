<?php

declare(strict_types=1);

namespace App\Http\Requests\MyInvois;

use App\Domain\MyInvois\MyInvoisEnvironment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreMyInvoisCredentialRequest extends FormRequest
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
            'environment' => ['required', 'string', Rule::in(array_map(
                static fn (MyInvoisEnvironment $case): string => $case->value,
                MyInvoisEnvironment::cases(),
            ))],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:1000'],
        ];
    }
}
