<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Money\Money;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * One normalized row from an imported bank statement (M17, SRS
 * BNK-003) — transaction date, description, amount, direction (in
 * plain "money in/out" terms, see {@see BankTransactionDirection}'s own
 * docblock), running balance, and reference.
 *
 * **Immutable, produced only by {@see BankStatementImportService}.**
 * There is no public mutator — a bank statement is a historical fact
 * hore.my received, never edited after the fact. A row that turns out
 * to be wrong is corrected by re-importing a corrected statement file,
 * not by mutating this record (M18's Matching layer only ever *links*
 * a BankTransaction to other records, never changes its own fields).
 *
 * **`balance` is optional.** Not every bank export includes a running
 * balance column; when present, it is the account's balance
 * *immediately after* this transaction, exactly as printed on the
 * statement — never computed or inferred by hore.my itself.
 */
final class BankTransaction
{
    private function __construct(
        private readonly BankTransactionId $id,
        private readonly TenantId $tenantId,
        private readonly BankAccountId $bankAccountId,
        private readonly ImportBatchId $importBatchId,
        private readonly \DateTimeImmutable $transactionDate,
        private readonly string $description,
        private readonly Money $amount,
        private readonly BankTransactionDirection $direction,
        private readonly ?Money $balance,
        private readonly string $reference,
    ) {}

    public static function record(
        BankTransactionId $id,
        TenantId $tenantId,
        BankAccountId $bankAccountId,
        ImportBatchId $importBatchId,
        \DateTimeImmutable $transactionDate,
        string $description,
        Money $amount,
        BankTransactionDirection $direction,
        ?Money $balance,
        string $reference,
    ): self {
        return new self($id, $tenantId, $bankAccountId, $importBatchId, $transactionDate, $description, $amount, $direction, $balance, $reference);
    }

    public static function reconstitute(
        BankTransactionId $id,
        TenantId $tenantId,
        BankAccountId $bankAccountId,
        ImportBatchId $importBatchId,
        \DateTimeImmutable $transactionDate,
        string $description,
        Money $amount,
        BankTransactionDirection $direction,
        ?Money $balance,
        string $reference,
    ): self {
        return new self($id, $tenantId, $bankAccountId, $importBatchId, $transactionDate, $description, $amount, $direction, $balance, $reference);
    }

    public function id(): BankTransactionId
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function bankAccountId(): BankAccountId
    {
        return $this->bankAccountId;
    }

    public function importBatchId(): ImportBatchId
    {
        return $this->importBatchId;
    }

    public function transactionDate(): \DateTimeImmutable
    {
        return $this->transactionDate;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function direction(): BankTransactionDirection
    {
        return $this->direction;
    }

    public function balance(): ?Money
    {
        return $this->balance;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    /**
     * The natural, business-meaning key BNK-004's row-level duplicate
     * guard is built on — see the owning migration's own docblock for
     * why `reference` defaults to `''`, never `null`, so this key is
     * always fully determined.
     */
    public function naturalKey(): string
    {
        return implode('|', [
            $this->bankAccountId->toString(),
            $this->transactionDate->format('Y-m-d'),
            $this->description,
            $this->amount->toDecimalString(),
            $this->direction->name,
            $this->reference,
        ]);
    }

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }
}
