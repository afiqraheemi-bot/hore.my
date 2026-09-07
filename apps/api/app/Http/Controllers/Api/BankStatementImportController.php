<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\BankStatementImportService;
use App\Domain\Banking\Exception\MalformedBankStatementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\ImportBankStatementRequest;
use App\Http\Support\CurrentTenant;
use App\Infrastructure\Banking\BankAccountRepository;
use Illuminate\Http\JsonResponse;

/**
 * Uploads and imports a CSV bank statement for one of the Tenant's own
 * BankAccounts (M17, SRS BNK-001/BNK-003/BNK-004) — the first file
 * upload endpoint in this codebase, hence
 * {@see ImportBankStatementRequest}'s own explicit extension/MIME/size
 * bounds (SEC-005) ahead of any parsing.
 */
final class BankStatementImportController extends Controller
{
    public function __construct(
        private readonly BankAccountRepository $bankAccountRepository,
        private readonly BankStatementImportService $importService,
    ) {}

    public function store(ImportBankStatementRequest $request, CurrentTenant $currentTenant, string $bankAccountId): JsonResponse
    {
        $bankAccount = $this->bankAccountRepository->findById($currentTenant->id(), BankAccountId::of($bankAccountId));

        if ($bankAccount === null) {
            return response()->json(['message' => 'BankAccount not found.'], 404);
        }

        $file = $request->file('statement');
        $contents = $file->get();

        if ($contents === false) {
            return response()->json(['message' => 'The uploaded file could not be read.'], 422);
        }

        try {
            $result = $this->importService->import(
                $currentTenant->id(),
                $bankAccount->id(),
                $file->getClientOriginalName(),
                $contents,
                Currency::of('MYR'),
            );
        } catch (MalformedBankStatementException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $importBatch = $result->importBatch();

        return response()->json([
            'id' => $importBatch->id()->toString(),
            'original_filename' => $importBatch->originalFilename(),
            'row_count' => $importBatch->rowCount(),
            'inserted_count' => $importBatch->insertedCount(),
            'duplicate_count' => $importBatch->duplicateCount(),
            'imported_at' => $importBatch->importedAt()->format(\DateTimeInterface::ATOM),
            'is_new_import' => $result->isNewImport(),
        ], $result->isNewImport() ? 201 : 200);
    }
}
