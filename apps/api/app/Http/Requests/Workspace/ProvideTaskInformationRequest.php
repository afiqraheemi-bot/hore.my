<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspace;

use App\Domain\Workspace\TaskService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Completes a Task's deferred Account decision (WTS-001 v3.0.0,
 * TSK-013) — see {@see TaskService::provideInformation()}.
 */
final class ProvideTaskInformationRequest extends FormRequest
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
            'primary_account_id' => ['required', 'string', 'max:64'],
            'secondary_account_id' => ['required', 'string', 'max:64'],
        ];
    }
}
