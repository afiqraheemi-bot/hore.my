<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Expense;

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Transactions\Expense\Exception\CorruptExpenseRecordException;
use App\Infrastructure\Transactions\Expense\ExpenseRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The application service that makes Expense recording (M7) an atomic,
 * idempotent, auditable first consumer of Accounting Core — without
 * ever bypassing it. This is the *only* class in the Transactions
 * domain that opens a database transaction; every other class in this
 * namespace remains a pure, storage-free collaborator.
 *
 * **Atomicity — Expense and Journal commit or roll back together.**
 * {@see PostingCommandTransactionalExecutor::execute()}
 * already opens its own transaction internally for a first submission
 * (Journal, Lines, idempotency mapping, Audit Event, Evidence linkage —
 * M4/M6). This service wraps the *entire* call — including that inner
 * transaction — inside one outer transaction on the same
 * `ConnectionInterface` instance, relying on
 * `Illuminate\Database\Connection::transaction()`'s existing reentrant/
 * savepoint behavior for the inner call to participate in this
 * service's own outer transaction rather than committing independently
 * — the identical technique `PostingCommandTransactionalExecutor`'s own
 * docblock already establishes for `JournalRepository::save()`. If the
 * subsequent Expense-record insert fails for any reason, the entire
 * transaction rolls back, including the Journal that was just posted
 * moments earlier — there is no way to observe a Posted Journal whose
 * Expense record does not exist, or vice versa.
 *
 * **Idempotency — inherited entirely from `PostingCommand`'s own.** No
 * separate Expense-level idempotency mechanism exists, or is needed: an
 * Expense and its Journal always share the same (Tenant, Idempotency
 * Key) pair, so `PostingCommandTransactionalExecutor::execute()`'s own
 * already-proven replay/conflict/race-recovery guarantee (M4-T18,
 * M4-T18B) is the single source of truth for whether this is a first
 * submission or a replay. On first submission, this service inserts the
 * new Expense record, in the same transaction, immediately after the
 * Journal it references. On replay, it looks the existing Expense
 * record up by its own identity and returns it unchanged — performing
 * zero additional writes, mirroring the M4/M6 replay guarantee exactly.
 *
 * **Sequencing.** Account Type validation ({@see ExpenseAccountTypeValidator})
 * runs first, before any transaction opens — a semantically wrong
 * Account (§ that class's own docblock) fails fast, before ever
 * reaching M4. Translation to a `PostingCommand`
 * ({@see ExpenseToPostingCommandTranslator}) also happens outside the
 * transaction, since it is a pure, storage-free operation.
 */
final class ExpenseRecordingService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ExpenseAccountTypeValidator $accountTypeValidator,
        private readonly ExpenseToPostingCommandTranslator $translator,
        private readonly PostingCommandTransactionalExecutor $postingExecutor,
        private readonly ExpenseRepository $expenseRepository,
    ) {}

    public function record(RecordExpenseCommand $command): ExpenseRecordingResult
    {
        $this->accountTypeValidator->validate($command);

        $postingCommand = $this->translator->translate($command);

        return $this->connection->transaction(function () use ($command, $postingCommand): ExpenseRecordingResult {
            $postingResult = $this->postingExecutor->execute($postingCommand);

            if ($postingResult->isReplay()) {
                $existingExpense = $this->expenseRepository->findById($command->tenantId(), $command->expenseId());

                if ($existingExpense === null) {
                    throw CorruptExpenseRecordException::forUnresolvableExpense($command->expenseId(), $postingResult->journal()->id());
                }

                return ExpenseRecordingResult::replayed($existingExpense);
            }

            $expense = $this->recordExpenseFor($command, $postingResult->journal());

            $this->expenseRepository->record($expense);

            return ExpenseRecordingResult::newlyRecorded($expense);
        });
    }

    private function recordExpenseFor(RecordExpenseCommand $command, Journal $journal): Expense
    {
        return Expense::record(
            $command->expenseId(),
            $command->tenantId(),
            $journal->id(),
            $command->amount(),
            $command->transactionDate(),
            $command->expenseAccountId(),
            $command->paymentAccountId(),
            $command->description(),
            $command->evidenceReference(),
        );
    }
}
