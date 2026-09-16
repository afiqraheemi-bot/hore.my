<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Evidence\EvidenceId;
use App\Domain\Evidence\EvidenceUploadService;
use App\Domain\Evidence\Exception\EvidenceNotFoundException;
use App\Domain\Evidence\Exception\InvalidEvidenceIdException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Evidence\UploadEvidenceRequest;
use App\Http\Support\CurrentTenant;
use App\Infrastructure\Evidence\EvidenceFileStore;
use App\Infrastructure\Evidence\EvidenceRepository;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Uploads and retrieves Evidence (AETS-015 §5/§6) — the real
 * upload/download boundary that document specifies, mirroring
 * {@see BankStatementImportController}'s own file-handling shape.
 */
final class EvidenceController extends Controller
{
    public function __construct(
        private readonly EvidenceUploadService $uploadService,
        private readonly EvidenceRepository $evidenceRepository,
        private readonly EvidenceFileStore $fileStore,
    ) {}

    public function store(UploadEvidenceRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $file = $request->file('file');
        $contents = $file->get();

        if ($contents === false) {
            return response()->json(['message' => 'The uploaded file could not be read.'], 422);
        }

        $evidence = $this->uploadService->upload(
            $currentTenant->id(),
            $file->getClientOriginalName(),
            (string) $file->getClientMimeType(),
            $contents,
            ActorReference::of($user->id),
        );

        return response()->json([
            'id' => $evidence->id()->toString(),
            'original_filename' => $evidence->originalFilename(),
            'mime_type' => $evidence->mimeType(),
            'byte_size' => $evidence->byteSize(),
            'sha256_digest' => $evidence->sha256Digest(),
            'uploaded_at' => $evidence->uploadedAt()->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    public function show(CurrentTenant $currentTenant, string $evidenceId): Response|JsonResponse
    {
        try {
            $id = EvidenceId::of($evidenceId);
        } catch (InvalidEvidenceIdException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $evidence = $this->evidenceRepository->findById($currentTenant->id(), $id);

        if ($evidence === null) {
            return response()->json(['message' => EvidenceNotFoundException::forId($id)->getMessage()], 404);
        }

        $contents = $this->fileStore->read($evidence->storagePath());

        return response($contents, 200)
            ->header('Content-Type', $evidence->mimeType())
            ->header('Content-Disposition', sprintf('inline; filename="%s"', addslashes($evidence->originalFilename())));
    }
}
