<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Payments\Exception\CorruptPaymentRecordException;
use App\Domain\Transactions\Income\IncomeRecordingService;
use App\Infrastructure\Payments\PaymentRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The application service that makes Payment recording (M21) an
 * atomic, idempotent, auditable consumer of Accounting Core — mirrors
 * {@see IncomeRecordingService}'s own
 * atomicity/idempotency reasoning exactly.
 */
final class PaymentRecordingService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly PaymentAccountTypeValidator $accountTypeValidator,
        private readonly PaymentToPostingCommandTranslator $translator,
        private readonly PostingCommandTransactionalExecutor $postingExecutor,
        private readonly PaymentRepository $paymentRepository,
    ) {}

    public function record(RecordPaymentCommand $command): PaymentRecordingResult
    {
        $this->accountTypeValidator->validate($command->tenantId(), $command->depositAccountId(), $command->receivableAccountId());

        $postingCommand = $this->translator->translate($command);

        return $this->connection->transaction(function () use ($command, $postingCommand): PaymentRecordingResult {
            $postingResult = $this->postingExecutor->execute($postingCommand);

            if ($postingResult->isReplay()) {
                $existingPayment = $this->paymentRepository->findById($command->tenantId(), $command->paymentId());

                if ($existingPayment === null) {
                    throw CorruptPaymentRecordException::forUnresolvablePayment($command->paymentId(), $postingResult->journal()->id());
                }

                return PaymentRecordingResult::replayed($existingPayment);
            }

            $payment = $this->recordPaymentFor($command, $postingResult->journal());

            $this->paymentRepository->record($payment);

            return PaymentRecordingResult::newlyRecorded($payment);
        });
    }

    private function recordPaymentFor(RecordPaymentCommand $command, Journal $journal): Payment
    {
        return Payment::record(
            $command->paymentId(),
            $command->tenantId(),
            $journal->id(),
            $command->customerId(),
            $command->amount(),
            $command->paymentDate(),
            $command->depositAccountId(),
            $command->receivableAccountId(),
            $command->reference(),
        );
    }
}
