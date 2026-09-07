<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Money\Money;

/**
 * One parsed, but not-yet-persisted, row from an uploaded bank
 * statement (M17) — {@see CsvBankStatementParser}'s own output type,
 * consumed only by {@see BankStatementImportService}. Carries no
 * identity of its own (no {@see BankTransactionId}, no
 * {@see ImportBatchId}) — those are assigned only once a row survives
 * BNK-004's duplicate check and is actually persisted as a
 * {@see BankTransaction}.
 */
final class BankStatementRow
{
    public function __construct(
        private readonly \DateTimeImmutable $transactionDate,
        private readonly string $description,
        private readonly Money $amount,
        private readonly BankTransactionDirection $direction,
        private readonly ?Money $balance,
        private readonly string $reference,
    ) {}

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
}
