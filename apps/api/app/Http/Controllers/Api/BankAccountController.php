<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountIdException;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\BankAccountLinkedAccountValidator;
use App\Domain\Banking\Exception\InvalidBankAccountIdException;
use App\Domain\Banking\Exception\InvalidBankAccountNameException;
use App\Domain\Banking\Exception\InvalidLinkedAccountTypeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\StoreBankAccountRequest;
use App\Http\Support\CurrentTenant;
use App\Infrastructure\Banking\BankAccountRepository;
use App\Infrastructure\Banking\BankTransactionRepository;
use App\Infrastructure\Banking\MatchRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers and lists a Tenant's own BankAccounts (M17) — mirrors
 * {@see AccountController}'s own shape exactly, since BankAccount is
 * reference data with the identical needs (no Posting Command
 * pipeline, no idempotency key, a freshly random identifier per
 * registration).
 */
final class BankAccountController extends Controller
{
    public function __construct(
        private readonly BankAccountLinkedAccountValidator $linkedAccountValidator,
        private readonly BankAccountRepository $bankAccountRepository,
        private readonly BankTransactionRepository $bankTransactionRepository,
        private readonly MatchRepository $matchRepository,
    ) {}

    public function index(CurrentTenant $currentTenant): JsonResponse
    {
        $rows = DB::connection('pgsql')->table('bank_accounts')
            ->where('tenant_id', $currentTenant->id()->toString())
            ->orderBy('created_at')
            ->get(['id', 'linked_account_id', 'bank_name', 'account_number_last4', 'active']);

        return response()->json(['data' => $rows->map(static fn ($row): array => [
            'id' => $row->id,
            'linked_account_id' => $row->linked_account_id,
            'bank_name' => $row->bank_name,
            'account_number_last4' => $row->account_number_last4,
            'active' => (bool) $row->active,
        ])->all()]);
    }

    public function store(StoreBankAccountRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        try {
            $linkedAccountId = AccountId::of($request->string('linked_account_id')->toString());
        } catch (InvalidAccountIdException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            $this->linkedAccountValidator->validate($currentTenant->id(), $linkedAccountId);
        } catch (RejectedAccountReferenceException|InvalidLinkedAccountTypeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $accountNumberLast4 = $request->string('account_number_last4')->toString();

        try {
            $bankAccount = BankAccount::register(
                BankAccountId::of((string) Str::uuid()),
                $currentTenant->id(),
                $linkedAccountId,
                $request->string('bank_name')->toString(),
                $accountNumberLast4 === '' ? null : $accountNumberLast4,
            );
        } catch (InvalidBankAccountNameException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->bankAccountRepository->save($bankAccount);

        return response()->json($this->toArray($bankAccount), 201);
    }

    public function transactions(CurrentTenant $currentTenant, string $bankAccountId): JsonResponse
    {
        try {
            $id = BankAccountId::of($bankAccountId);
        } catch (InvalidBankAccountIdException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($this->bankAccountRepository->findById($currentTenant->id(), $id) === null) {
            return response()->json(['message' => 'BankAccount not found.'], 404);
        }

        $transactions = $this->bankTransactionRepository->findByBankAccount($currentTenant->id(), $id);

        // Which of these already has a confirmed Match — the same
        // lookup {@see \App\Domain\Banking\MatchingService::suggestFor()}
        // already uses to exclude an already-matched BankTransaction
        // from its own suggestions, reused here so the frontend can
        // tell an unmatched row apart from a matched one (previously
        // impossible: this list carried no such signal at all).
        $matchedIds = array_flip(array_map(
            static fn ($matchedId): string => $matchedId->toString(),
            $this->matchRepository->matchedBankTransactionIds(
                $currentTenant->id(),
                array_map(static fn ($transaction) => $transaction->id(), $transactions),
            ),
        ));

        return response()->json(['data' => array_map(static fn ($transaction): array => [
            'id' => $transaction->id()->toString(),
            'transaction_date' => $transaction->transactionDate()->format('Y-m-d'),
            'description' => $transaction->description(),
            'amount' => $transaction->amount()->toDecimalString(),
            'direction' => $transaction->direction()->name,
            'balance' => $transaction->balance()?->toDecimalString(),
            'reference' => $transaction->reference(),
            'matched' => isset($matchedIds[$transaction->id()->toString()]),
        ], $transactions)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(BankAccount $bankAccount): array
    {
        return [
            'id' => $bankAccount->id()->toString(),
            'linked_account_id' => $bankAccount->linkedAccountId()->toString(),
            'bank_name' => $bankAccount->bankName(),
            'account_number_last4' => $bankAccount->accountNumberLast4(),
            'active' => $bankAccount->isActive(),
        ];
    }
}
