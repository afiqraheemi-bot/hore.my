<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\ChartOfAccounts\Account;
use App\Domain\Accounting\ChartOfAccounts\AccountCode;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountName;
use App\Domain\Accounting\ChartOfAccounts\AccountOrigin;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreAccountRequest;
use App\Http\Support\CurrentTenant;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use App\Infrastructure\Accounting\ChartOfAccounts\Exception\DuplicateAccountCodeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Exposes the already-built, already-tested Chart of Accounts domain
 * (AETS-005) over HTTP — added beyond M11's originally reviewed GO list
 * because the reviewed Expense/Income/Reporting endpoints are otherwise
 * unusable by a freshly registered Tenant with no Accounts at all. It
 * wraps only {@see Account::create()} and {@see AccountRepository::save()};
 * no new business logic is introduced.
 */
final class AccountController extends Controller
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
    ) {}

    public function index(CurrentTenant $currentTenant): JsonResponse
    {
        $rows = DB::connection('pgsql')->table('accounts')
            ->where('tenant_id', $currentTenant->id()->toString())
            ->orderBy('account_code')
            ->get(['account_id', 'account_code', 'account_name', 'account_type', 'active', 'posting_eligible']);

        return response()->json(['data' => $rows->map(static fn ($row): array => [
            'id' => $row->account_id,
            'account_code' => $row->account_code,
            'account_name' => $row->account_name,
            'account_type' => $row->account_type,
            'active' => (bool) $row->active,
            'posting_eligible' => (bool) $row->posting_eligible,
        ])->all()]);
    }

    public function store(StoreAccountRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $account = Account::create(
            $currentTenant->id(),
            AccountId::of((string) Str::uuid()),
            AccountCode::of($request->string('account_code')->toString()),
            AccountName::of($request->string('account_name')->toString()),
            self::accountTypeFromName($request->string('account_type')->toString()),
            $request->boolean('posting_eligible', true),
            AccountOrigin::UserCreated,
        );

        try {
            $this->accountRepository->save($account);
        } catch (DuplicateAccountCodeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->toArray($account), 201);
    }

    private static function accountTypeFromName(string $value): AccountType
    {
        foreach (AccountType::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        // StoreAccountRequest's `in:` rule already rejects any other
        // value before this method is reached.
        throw new \LogicException(sprintf('Unreachable: "%s" already passed validation.', $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Account $account): array
    {
        return [
            'id' => $account->id()->toString(),
            'account_code' => $account->code()->toString(),
            'account_name' => $account->name()->toString(),
            'account_type' => $account->type()->name,
            'active' => $account->isActive(),
            'posting_eligible' => $account->isPostingEligible(),
        ];
    }
}
