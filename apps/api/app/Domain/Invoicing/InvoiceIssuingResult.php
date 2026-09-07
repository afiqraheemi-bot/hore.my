<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Transactions\Income\IncomeRecordingResult;

/**
 * The deterministic terminal result of issuing an Invoice through
 * {@see InvoiceIssuingService} (M20) — mirrors
 * {@see IncomeRecordingResult}'s own
 * role: either this specific invocation newly issued the Invoice (and
 * posted its Journal), or it matched an already-processed Issue call
 * for the same (Tenant, Idempotency Key) pair and is returning that
 * original result instead.
 */
final class InvoiceIssuingResult
{
    private function __construct(
        private readonly bool $isNewlyIssued,
        private readonly Invoice $invoice,
    ) {}

    public static function newlyIssued(Invoice $invoice): self
    {
        return new self(true, $invoice);
    }

    public static function replayed(Invoice $invoice): self
    {
        return new self(false, $invoice);
    }

    public function isNewlyIssued(): bool
    {
        return $this->isNewlyIssued;
    }

    public function isReplay(): bool
    {
        return ! $this->isNewlyIssued;
    }

    public function invoice(): Invoice
    {
        return $this->invoice;
    }
}
