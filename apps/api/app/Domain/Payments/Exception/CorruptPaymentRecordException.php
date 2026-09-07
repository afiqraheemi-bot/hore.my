<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exception;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Payments\PaymentId;
use App\Domain\Transactions\Income\Exception\CorruptIncomeRecordException;

/**
 * Thrown when `PostingCommandTransactionalExecutor::execute()` reports
 * an idempotent replay for a Payment's underlying Journal, but no
 * matching Payment record can be found (M21) — mirrors
 * {@see CorruptIncomeRecordException}'s
 * own reasoning exactly.
 */
final class CorruptPaymentRecordException extends \RuntimeException
{
    public static function forUnresolvablePayment(PaymentId $paymentId, JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" was reported as an idempotent replay for Payment "%s", but no matching Payment record could be found.',
            $journalId->toString(),
            $paymentId->toString(),
        ));
    }
}
