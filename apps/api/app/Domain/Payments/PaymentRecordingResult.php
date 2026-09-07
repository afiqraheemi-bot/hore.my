<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Transactions\Income\IncomeRecordingResult;

/**
 * The deterministic terminal result of executing a
 * `RecordPaymentCommand` through {@see PaymentRecordingService} (M21)
 * — mirrors
 * {@see IncomeRecordingResult}'s own
 * role.
 */
final class PaymentRecordingResult
{
    private function __construct(
        private readonly bool $isNewlyRecorded,
        private readonly Payment $payment,
    ) {}

    public static function newlyRecorded(Payment $payment): self
    {
        return new self(true, $payment);
    }

    public static function replayed(Payment $payment): self
    {
        return new self(false, $payment);
    }

    public function isNewlyRecorded(): bool
    {
        return $this->isNewlyRecorded;
    }

    public function isReplay(): bool
    {
        return ! $this->isNewlyRecorded;
    }

    public function payment(): Payment
    {
        return $this->payment;
    }
}
