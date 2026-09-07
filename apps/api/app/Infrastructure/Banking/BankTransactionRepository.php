<?php

declare(strict_types=1);

namespace App\Infrastructure\Banking;

use App\Domain\Accounting\Money\Money;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\BankTransaction;
use App\Domain\Banking\BankTransactionDirection;
use App\Domain\Banking\BankTransactionId;
use App\Domain\Banking\ImportBatchId;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the BankTransaction aggregate (M17),
 * through the production `bank_transactions` table — mirrors
 * {@see ImportBatchRepository}'s own transaction-participation
 * reasoning exactly.
 */
final class BankTransactionRepository
{
    private const TABLE = 'bank_transactions';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    public function record(BankTransaction $bankTransaction): void
    {
        $this->connection->table(self::TABLE)->insert([
            'id' => $bankTransaction->id()->toString(),
            'tenant_id' => $bankTransaction->tenantId()->toString(),
            'bank_account_id' => $bankTransaction->bankAccountId()->toString(),
            'import_batch_id' => $bankTransaction->importBatchId()->toString(),
            'transaction_date' => $bankTransaction->transactionDate()->format('Y-m-d'),
            'description' => $bankTransaction->description(),
            'amount' => $this->money->toPersistedAmount($bankTransaction->amount()),
            'currency' => $this->money->toPersistedCurrency($bankTransaction->amount()),
            'direction' => $bankTransaction->direction()->name,
            'balance' => $bankTransaction->balance() === null ? null : $this->money->toPersistedAmount($bankTransaction->balance()),
            'reference' => $bankTransaction->reference(),
        ]);
    }

    /**
     * Resolves BNK-004's row-level duplicate guard: whether a
     * BankTransaction with this exact natural key
     * ({@see BankTransaction::naturalKey()}) already exists for this
     * BankAccount, regardless of which ImportBatch produced it.
     */
    public function existsByNaturalKey(TenantId $tenantId, BankAccountId $bankAccountId, \DateTimeImmutable $transactionDate, string $description, Money $amount, BankTransactionDirection $direction, string $reference): bool
    {
        return $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('bank_account_id', $bankAccountId->toString())
            ->where('transaction_date', $transactionDate->format('Y-m-d'))
            ->where('description', $description)
            ->where('amount', $this->money->toPersistedAmount($amount))
            ->where('direction', $direction->name)
            ->where('reference', $reference)
            ->exists();
    }

    /**
     * @return list<BankTransaction>
     */
    public function findByBankAccount(TenantId $tenantId, BankAccountId $bankAccountId): array
    {
        /** @var list<object{id: string, tenant_id: string, bank_account_id: string, import_batch_id: string, transaction_date: string, description: string, amount: int|string, currency: string, direction: string, balance: int|string|null, reference: string}> $rows */
        $rows = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('bank_account_id', $bankAccountId->toString())
            ->orderBy('transaction_date')
            ->get()
            ->all();

        return array_map(fn (object $row): BankTransaction => $this->fromPersisted($row), $rows);
    }

    /**
     * @param  object{id: string, tenant_id: string, bank_account_id: string, import_batch_id: string, transaction_date: string, description: string, amount: int|string, currency: string, direction: string, balance: int|string|null, reference: string}  $row
     */
    private function fromPersisted(object $row): BankTransaction
    {
        return BankTransaction::reconstitute(
            BankTransactionId::of($row->id),
            TenantId::of($row->tenant_id),
            BankAccountId::of($row->bank_account_id),
            ImportBatchId::of($row->import_batch_id),
            new \DateTimeImmutable($row->transaction_date),
            $row->description,
            $this->money->fromPersisted((string) $row->amount, $row->currency),
            $row->direction === 'MoneyIn' ? BankTransactionDirection::MoneyIn : BankTransactionDirection::MoneyOut,
            $row->balance === null ? null : $this->money->fromPersisted((string) $row->balance, $row->currency),
            $row->reference,
        );
    }
}
