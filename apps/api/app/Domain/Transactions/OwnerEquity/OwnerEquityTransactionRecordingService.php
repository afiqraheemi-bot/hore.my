<?php

declare(strict_types=1);

namespace App\Domain\Transactions\OwnerEquity;

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Transactions\Income\IncomeRecordingService;
use App\Domain\Transactions\OwnerEquity\Exception\CorruptOwnerEquityTransactionRecordException;
use App\Infrastructure\Transactions\OwnerEquity\OwnerEquityTransactionRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The application service that makes Owner Equity Transaction
 * recording (M15) an atomic, idempotent, auditable first consumer of
 * Accounting Core — without ever bypassing it. Mirrors
 * {@see IncomeRecordingService}'s own
 * reasoning exactly, including the outer-transaction/reentrant-savepoint
 * technique and the identical idempotency-inheritance argument (a
 * record and its Journal always share the same (Tenant, Idempotency
 * Key) pair).
 *
 * **Sequencing.** Account Type validation
 * ({@see OwnerEquityAccountTypeValidator}) runs first, before any
 * transaction opens. Translation to a `PostingCommand`
 * ({@see OwnerEquityTransactionToPostingCommandTranslator}) also
 * happens outside the transaction, since it is a pure, storage-free
 * operation.
 */
final class OwnerEquityTransactionRecordingService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly OwnerEquityAccountTypeValidator $accountTypeValidator,
        private readonly OwnerEquityTransactionToPostingCommandTranslator $translator,
        private readonly PostingCommandTransactionalExecutor $postingExecutor,
        private readonly OwnerEquityTransactionRepository $transactionRepository,
    ) {}

    public function record(RecordOwnerEquityTransactionCommand $command): OwnerEquityTransactionRecordingResult
    {
        $this->accountTypeValidator->validate($command);

        $postingCommand = $this->translator->translate($command);

        return $this->connection->transaction(function () use ($command, $postingCommand): OwnerEquityTransactionRecordingResult {
            $postingResult = $this->postingExecutor->execute($postingCommand);

            if ($postingResult->isReplay()) {
                $existing = $this->transactionRepository->findById($command->tenantId(), $command->transactionId());

                if ($existing === null) {
                    throw CorruptOwnerEquityTransactionRecordException::forUnresolvableTransaction($command->transactionId(), $postingResult->journal()->id());
                }

                return OwnerEquityTransactionRecordingResult::replayed($existing);
            }

            $transaction = $this->recordTransactionFor($command, $postingResult->journal());

            $this->transactionRepository->record($transaction);

            return OwnerEquityTransactionRecordingResult::newlyRecorded($transaction);
        });
    }

    private function recordTransactionFor(RecordOwnerEquityTransactionCommand $command, Journal $journal): OwnerEquityTransaction
    {
        return OwnerEquityTransaction::record(
            $command->transactionId(),
            $command->tenantId(),
            $journal->id(),
            $command->movementType(),
            $command->amount(),
            $command->transactionDate(),
            $command->equityAccountId(),
            $command->cashAccountId(),
            $command->description(),
            $command->evidenceReference(),
        );
    }
}
