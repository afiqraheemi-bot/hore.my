<?php

declare(strict_types=1);

namespace App\Http\Requests\Evidence;

use App\Domain\Evidence\EvidenceUploadService;
use App\Http\Requests\Banking\ImportBankStatementRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SEC-005 (input protection), mirroring
 * {@see ImportBankStatementRequest}'s own
 * bound-before-content-is-touched reasoning: the uploaded file is
 * bounded by both extension/MIME and size before
 * {@see EvidenceUploadService} ever reads its
 * content. A 10MB ceiling comfortably covers a photographed or
 * scanned receipt/invoice while still bounding worst-case memory cost
 * from an arbitrary upload (AETS-015 §5/§6).
 *
 * **Allow-list, not a blanket "any file."** Evidence in this
 * milestone's scope is a receipt, invoice, or similar document —
 * common image formats and PDF, never an executable, script, or
 * archive.
 */
final class UploadEvidenceRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];
    }
}
