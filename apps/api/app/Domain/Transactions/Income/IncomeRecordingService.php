<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Income;

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Transactions\Income\Exception\CorruptIncomeRecordException;
use App\Infrastructure\Transactions\Income\IncomeRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The application service that makes Income recording (M9) an atomic,
 * idempotent, auditable first consumer of Accounting Core — without
 * ever bypassing it. This is the *only* class in the Transactions
 * domain that opens a database transaction; every other class in this
 * namespace remains a pure, storage-free collaborator.
 *
 * **Atomicity — Income and Journal commit or roll back together.**
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
 * subsequent Income-record insert fails for any reason, the entire
 * transaction rolls back, including the Journal that was just posted
 * moments earlier — there is no way to observe a Posted Journal whose
 * Income record does not exist, or vice versa.
 *
 * **Idempotency — inherited entirely from `PostingCommand`'s own.** No
 * separate Income-level idempotency mechanism exists, or is needed: an
 * Income and its Journal always share the same (Tenant, Idempotency
 * Key) pair, so `PostingCommandTransactionalExecutor::execute()`'s own
 * already-proven replay/conflict/race-recovery guarantee (M4-T18,
 * M4-T18B) is the single source of truth for whether this is a first
 * submission or a replay. On first submission, this service inserts the
 * new Income record, in the same transaction, immediately after the
 * Journal it references. On replay, it looks the existing Income
 * record up by its own identity and returns it unchanged — performing
 * zero additional writes, mirroring the M4/M6 replay guarantee exactly.
 *
 * **Sequencing.** Account Type validation ({@see IncomeAccountTypeValidator})
 * runs first, before any transaction opens — a semantically wrong
 * Account (§ that class's own docblock) fails fast, before ever
 * reaching M4. Translation to a `PostingCommand`
 * ({@see IncomeToPostingCommandTranslator}) also happens outside the
 * transaction, since it is a pure, storage-free operation.
 */
final class IncomeRecordingService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly IncomeAccountTypeValidator $accountTypeValidator,
        private readonly IncomeToPostingCommandTranslator $translator,
        private readonly PostingCommandTransactionalExecutor $postingExecutor,
        private readonly IncomeRepository $incomeRepository,
    ) {}

    public function record(RecordIncomeCommand $command): IncomeRecordingResult
    {
        $this->accountTypeValidator->validate($command);

        $postingCommand = $this->translator->translate($command);

        return $this->connection->transaction(function () use ($command, $postingCommand): IncomeRecordingResult {
            $postingResult = $this->postingExecutor->execute($postingCommand);

            if ($postingResult->isReplay()) {
                $existingIncome = $this->incomeRepository->findById($command->tenantId(), $command->incomeId());

                if ($existingIncome === null) {
                    throw CorruptIncomeRecordException::forUnresolvableIncome($command->incomeId(), $postingResult->journal()->id());
                }

                return IncomeRecordingResult::replayed($existingIncome);
            }

            $income = $this->recordIncomeFor($command, $postingResult->journal());

            $this->incomeRepository->record($income);

            return IncomeRecordingResult::newlyRecorded($income);
        });
    }

    private function recordIncomeFor(RecordIncomeCommand $command, Journal $journal): Income
    {
        return Income::record(
            $command->incomeId(),
            $command->tenantId(),
            $journal->id(),
            $command->amount(),
            $command->transactionDate(),
            $command->incomeAccountId(),
            $command->depositAccountId(),
            $command->description(),
            $command->evidenceReference(),
        );
    }
}
