<?php

declare(strict_types=1);

use App\Domain\Banking\BankAccountLinkedAccountValidator;
use App\Http\Support\ChartOfAccountsPresetProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for BankAccount (M17, SRS §4.5 Banking &
 * Reconciliation) — a Tenant's own real-world bank account, linked to
 * exactly one Asset Account in its Chart of Accounts (typically the
 * "Bank" preset Account {@see ChartOfAccountsPresetProvisioner}
 * auto-provisions at onboarding, but any Asset Account qualifies).
 *
 * **`linked_account_id` is a composite foreign key onto `accounts`**,
 * mirroring the tenant-safe composite-FK convention already
 * established throughout this schema. A BankAccount's Account Type is
 * validated (must be `Asset`) at the Domain layer
 * ({@see BankAccountLinkedAccountValidator}), not
 * by a schema-level constraint — the same division of responsibility
 * `TransferAccountTypeValidator`/`OwnerEquityAccountTypeValidator`
 * already establish for their own Account Type checks.
 *
 * **`account_number_last4` only, never a full account number.** SRS
 * §5.1's data-minimisation rule ("Identifier sensitif dienkripsi atau
 * ditoken mengikut klasifikasi data") is satisfied here by never
 * storing the sensitive value at all — the last four digits are enough
 * for a Tenant to tell two of their own bank accounts apart in a list,
 * and hore.my has no operational need for the full number.
 *
 * **No FK onto `journals`.** Registering a BankAccount produces no
 * Journal — it is pure reference data, exactly like an ordinary
 * Chart-of-Accounts `Account` itself.
 */
return new class extends Migration
{
    private const TABLE = 'bank_accounts';

    private const ACCOUNT_TABLE = 'accounts';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('linked_account_id', 64);
            $table->string('bank_name', 100);
            $table->string('account_number_last4', 4)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->primary('id');

            $table->foreign(['tenant_id', 'linked_account_id'])
                ->references(['tenant_id', 'account_id'])
                ->on(self::ACCOUNT_TABLE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
