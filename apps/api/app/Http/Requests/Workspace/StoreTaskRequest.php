<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspace;

use App\Domain\Workspace\CommandType;
use App\Domain\Workspace\TaskService;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `primary_account_id`/`secondary_account_id` are optional (WTS-001
 * v3.0.0, TSK-013): a submitter may explicitly defer the Account
 * decision, in which case {@see TaskController::store()}
 * calls {@see TaskService::saveForLaterCompletion()}
 * instead of {@see TaskService::submit()}. Both
 * fields must be supplied together, or neither — {@see withValidator()}
 * rejects exactly one being present, since that can only be a client
 * error, never a legitimate partial submission.
 */
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
            'primary_account_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'secondary_account_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:255'],
            'evidence_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasPrimary = filled($this->input('primary_account_id'));
            $hasSecondary = filled($this->input('secondary_account_id'));

            if ($hasPrimary !== $hasSecondary) {
                $validator->errors()->add('primary_account_id', 'Both accounts must be supplied together, or neither.');
            }
        });
    }

    public function deferringAccountDecision(): bool
    {
        return blank($this->input('primary_account_id')) && blank($this->input('secondary_account_id'));
    }
}
