<?php

declare(strict_types=1);

namespace App\Infrastructure\Banking;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\BankAccountId;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the BankAccount aggregate (M17),
 * through the production `bank_accounts` table — mirrors
 * {@see AccountRepository}'s
 * own shape, since BankAccount is reference data with the identical
 * persistence needs (no transaction participation required — a
 * BankAccount registration never shares a database transaction with
 * anything else, unlike `TransferRepository`/`IncomeRepository`, which
 * always insert inside their owning Recording Service's outer
 * transaction).
 */
final class BankAccountRepository
{
    private const TABLE = 'bank_accounts';

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function save(BankAccount $bankAccount): void
    {
        $this->connection->table(self::TABLE)->insert([
            'id' => $bankAccount->id()->toString(),
            'tenant_id' => $bankAccount->tenantId()->toString(),
            'linked_account_id' => $bankAccount->linkedAccountId()->toString(),
            'bank_name' => $bankAccount->bankName(),
            'account_number_last4' => $bankAccount->accountNumberLast4(),
            'active' => $bankAccount->isActive(),
        ]);
    }

    public function findById(TenantId $tenantId, BankAccountId $bankAccountId): ?BankAccount
    {
        /** @var object{id: string, tenant_id: string, linked_account_id: string, bank_name: string, account_number_last4: string|null, active: bool}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $bankAccountId->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    /**
     * @param  object{id: string, tenant_id: string, linked_account_id: string, bank_name: string, account_number_last4: string|null, active: bool}  $row
     */
    private function fromPersisted(object $row): BankAccount
    {
        return BankAccount::reconstitute(
            BankAccountId::of($row->id),
            TenantId::of($row->tenant_id),
            AccountId::of($row->linked_account_id),
            $row->bank_name,
            $row->account_number_last4,
            (bool) $row->active,
        );
    }
}
