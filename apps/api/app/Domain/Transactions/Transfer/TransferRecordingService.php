<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Transfer;

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Transactions\Income\IncomeRecordingService;
use App\Domain\Transactions\Transfer\Exception\CorruptTransferRecordException;
use App\Infrastructure\Transactions\Transfer\TransferRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The application service that makes Transfer recording (M14) an
 * atomic, idempotent, auditable first consumer of Accounting Core —
 * without ever bypassing it. Mirrors
 * {@see IncomeRecordingService}'s own
 * reasoning exactly, including the outer-transaction/reentrant-savepoint
 * technique and the identical idempotency-inheritance argument (a
 * Transfer and its Journal always share the same (Tenant, Idempotency
 * Key) pair).
 *
 * **Sequencing.** Account Type/distinctness validation
 * ({@see TransferAccountTypeValidator}) runs first, before any
 * transaction opens. Translation to a `PostingCommand`
 * ({@see TransferToPostingCommandTranslator}) also happens outside the
 * transaction, since it is a pure, storage-free operation.
 */
final class TransferRecordingService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly TransferAccountTypeValidator $accountTypeValidator,
        private readonly TransferToPostingCommandTranslator $translator,
        private readonly PostingCommandTransactionalExecutor $postingExecutor,
        private readonly TransferRepository $transferRepository,
    ) {}

    public function record(RecordTransferCommand $command): TransferRecordingResult
    {
        $this->accountTypeValidator->validate($command);

        $postingCommand = $this->translator->translate($command);

        return $this->connection->transaction(function () use ($command, $postingCommand): TransferRecordingResult {
            $postingResult = $this->postingExecutor->execute($postingCommand);

            if ($postingResult->isReplay()) {
                $existingTransfer = $this->transferRepository->findById($command->tenantId(), $command->transferId());

                if ($existingTransfer === null) {
                    throw CorruptTransferRecordException::forUnresolvableTransfer($command->transferId(), $postingResult->journal()->id());
                }

                return TransferRecordingResult::replayed($existingTransfer);
            }

            $transfer = $this->recordTransferFor($command, $postingResult->journal());

            $this->transferRepository->record($transfer);

            return TransferRecordingResult::newlyRecorded($transfer);
        });
    }

    private function recordTransferFor(RecordTransferCommand $command, Journal $journal): Transfer
    {
        return Transfer::record(
            $command->transferId(),
            $command->tenantId(),
            $journal->id(),
            $command->amount(),
            $command->transactionDate(),
            $command->sourceAccountId(),
            $command->destinationAccountId(),
            $command->description(),
            $command->evidenceReference(),
        );
    }
}
