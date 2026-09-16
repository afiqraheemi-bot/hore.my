<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Accounting\ChartOfAccounts\Account;
use App\Domain\Accounting\ChartOfAccounts\AccountCode;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountName;
use App\Domain\Accounting\ChartOfAccounts\AccountOrigin;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Shared\Tenancy\TenantId;
use App\Http\Controllers\Api\BusinessProfileController;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use App\Infrastructure\Accounting\ChartOfAccounts\Exception\DuplicateAccountCodeException;
use App\Models\BusinessProfile;
use Illuminate\Support\Str;

/**
 * SRS IAM-004: "Sistem hendaklah mencadangkan carta akaun dan kategori
 * berdasarkan jenis perniagaan" (the system shall suggest a Chart of
 * Accounts and categories based on business type) — invoked once, by
 * {@see BusinessProfileController}, the first
 * time a Tenant saves its Business Profile.
 *
 * **One universal starter preset, not a preset per {@see BusinessProfile::BUSINESS_TYPES}
 * value.** IAM-004 asks for type-specific presets; this class ships the
 * single generic Malaysian micro-business/SME set every business type
 * can meaningfully use (Cash/Bank, Receivables/Payables, Owner's
 * Equity, Sales Revenue, and common Expense categories) regardless of
 * which `business_type` was chosen. Authoring genuinely distinct
 * per-industry account sets (e.g. a Retail-specific Inventory/COGS
 * split versus a Services-specific set with none) is real content work
 * deferred to a future revision — tracked here, not silently
 * abandoned, so this class must not be read as IAM-004's full
 * satisfaction.
 *
 * **Idempotent by Account Code collision, not a separate "already
 * provisioned" flag.** Every preset entry is inserted through the same
 * {@see AccountRepository::save()} every other Account creation path
 * uses; a `DuplicateAccountCodeException` (the Tenant already has an
 * Account at that Code — whether from a previous provisioning call or
 * from the Tenant's own manual Chart of Accounts setup) is treated as
 * "already present," not an error — this provisioner is always safe to
 * invoke again.
 *
 * **`AccountOrigin::System`** — these Accounts are created by
 * deterministic, hore.my-owned onboarding logic, never by a user
 * through the ordinary Account-creation path (AETS-005 §15), exactly
 * the distinction that origin exists to record.
 */
final class ChartOfAccountsPresetProvisioner
{
    /**
     * @var list<array{code: string, name: string, type: AccountType}>
     */
    private const PRESET = [
        ['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset],
        ['code' => '1010', 'name' => 'Bank', 'type' => AccountType::Asset],
        ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => AccountType::Asset],
        ['code' => '2000', 'name' => 'Accounts Payable', 'type' => AccountType::Liability],
        ['code' => '3000', 'name' => "Owner's Capital", 'type' => AccountType::Equity],
        ['code' => '3100', 'name' => "Owner's Drawings", 'type' => AccountType::Equity],
        ['code' => '4000', 'name' => 'Sales Revenue', 'type' => AccountType::Revenue],
        ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => AccountType::Expense],
        ['code' => '5050', 'name' => 'Raw Materials & Supplies', 'type' => AccountType::Expense],
        ['code' => '5100', 'name' => 'Rent Expense', 'type' => AccountType::Expense],
        ['code' => '5200', 'name' => 'Utilities Expense', 'type' => AccountType::Expense],
        ['code' => '5300', 'name' => 'Salaries and Wages', 'type' => AccountType::Expense],
        ['code' => '5400', 'name' => 'Office Supplies Expense', 'type' => AccountType::Expense],
        ['code' => '5900', 'name' => 'General Expenses', 'type' => AccountType::Expense],
    ];

    public function __construct(
        private readonly AccountRepository $accountRepository,
    ) {}

    public function provisionFor(TenantId $tenantId): void
    {
        foreach (self::PRESET as $entry) {
            $account = Account::create(
                $tenantId,
                AccountId::of((string) Str::uuid()),
                AccountCode::of($entry['code']),
                AccountName::of($entry['name']),
                $entry['type'],
                true,
                AccountOrigin::System,
            );

            try {
                $this->accountRepository->save($account);
            } catch (DuplicateAccountCodeException) {
                // Already present for this Tenant — safe, expected on
                // a repeated or partially-completed provisioning call.
            }
        }
    }
}
