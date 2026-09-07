<?php

declare(strict_types=1);

namespace App\Http\Requests\Banking;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmMatchRequest extends FormRequest
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
            'journal_id' => ['required', 'string', 'max:64'],
        ];
    }
}
