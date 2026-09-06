<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Period;

use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Period\Exception\CorruptPeriodClosureRecordException;
use App\Domain\Accounting\Period\Exception\PeriodAlreadyClosedException;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Transactions\Expense\ExpenseRecordingService;
use App\Infrastructure\Accounting\Period\PeriodClosureRepository;
use App\Infrastructure\Accounting\Reporting\AccountBalanceAggregator;
use Illuminate\Database\ConnectionInterface;

/**
 * The application service that makes Period closing (AETS-014) an
 * atomic, idempotent, auditable operation over Accounting Core — never
 * bypassing it. Mirrors {@see ExpenseRecordingService}'s
 * own structure and atomicity technique exactly: the entire operation,
 * including `PostingCommandTransactionalExecutor::execute()`'s own
 * inner transaction, runs inside one outer transaction on the same
 * `ConnectionInterface`, so the closing Journal and its `period_closures`
 * row commit or roll back together — there is no way to observe one
 * without the other.
 *
 * **Idempotency — recomputing balances on every call is safe, not
 * merely convenient, but only bounded correctly.** Unlike Expense/
 * Income (where the Journal Lines come directly from caller-supplied
 * amounts), this service always re-queries current Revenue/Expense
 * balances via {@see AccountBalanceAggregator} before translating, on
 * every call, replay or not. The lower bound is deliberately the
 * closure strictly *before* this `closedThroughDate`
 * ({@see PeriodClosureRepository::findLatestBefore()}), never account
 * inception unconditionally: on a replay, aggregating from inception
 * would also aggregate the *first* attempt's own closing Journal
 * (dated exactly at `closedThroughDate`), whose zeroing lines would
 * make every Account appear to net to zero and incorrectly report
 * nothing to close. Bounding from the previous closure instead
 * reconstructs byte-for-byte the same balances, and therefore the same
 * `PostingCommand`, the original closing produced —
 * `PostingCommandLogicalEquivalence` then correctly recognizes it as
 * the same logical request. `PostingCommandPeriodLockValidator`
 * guarantees no new Journal Line can ever have entered the range being
 * re-aggregated since it was closed, so this reconstruction is exact,
 * not approximate.
 *
 * **Rejecting a genuine backward/duplicate close, without rejecting a
 * genuine replay.** A request whose `closedThroughDate` is not after
 * the current watermark is allowed to proceed only when an exact
 * `period_closures` row already exists for that precise date (a
 * plausible replay, left for the Posting Command pipeline's own
 * Idempotency Key comparison to confirm or reject as conflicting) —
 * otherwise it is rejected immediately as
 * {@see PeriodAlreadyClosedException}, before any balance is even
 * queried.
 */
final class PeriodClosingService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly RetainedEarningsAccountTypeValidator $retainedEarningsAccountTypeValidator,
        private readonly PeriodClosureRepository $periodClosureRepository,
        private readonly AccountBalanceAggregator $accountBalanceAggregator,
        private readonly PeriodClosingToPostingCommandTranslator $translator,
        private readonly PostingCommandTransactionalExecutor $postingExecutor,
    ) {}

    public function close(PeriodClosingCommand $command): PeriodClosingResult
    {
        $this->retainedEarningsAccountTypeValidator->validate($command);

        return $this->connection->transaction(function () use ($command): PeriodClosingResult {
            $watermark = $this->periodClosureRepository->findLatestForTenant($command->tenantId());

            if ($watermark !== null && $command->closedThroughDate()->format('Y-m-d') <= $watermark->closedThroughDate()->format('Y-m-d')) {
                $exact = $this->periodClosureRepository->findForTenantAndDate($command->tenantId(), $command->closedThroughDate());

                if ($exact === null) {
                    throw PeriodAlreadyClosedException::forDateNotAfterWatermark(
                        $command->tenantId(),
                        $command->closedThroughDate(),
                        $watermark->closedThroughDate(),
                    );
                }
            }

            // The lower bound must be the closure strictly *before* this
            // date, never simply "the latest" — on a replay, "the
            // latest" is this exact closure, which would exclude
            // everything this same computation needs to reconstruct.
            // See {@see PeriodClosureRepository::findLatestBefore()}'s
            // own docblock for the full reasoning.
            $previousClosure = $this->periodClosureRepository->findLatestBefore($command->tenantId(), $command->closedThroughDate());
            $financialDateFrom = $previousClosure?->closedThroughDate()->modify('+1 day');

            // Excludes this exact closing Journal from its own
            // computation — on a replay, that Journal already exists,
            // dated exactly at `closedThroughDate` (the upper bound
            // below is inclusive), and its own zeroing lines would
            // otherwise make every Account appear to net to zero.
            $balances = $this->accountBalanceAggregator->aggregate(
                $command->tenantId(),
                $financialDateFrom,
                $command->closedThroughDate(),
                [AccountType::Revenue, AccountType::Expense],
                $command->closingJournalId(),
            );

            $currency = $balances === [] ? Currency::of('MYR') : $balances[0]->totalDebit()->currency();

            $postingCommand = $this->translator->translate($command, $balances, $currency);

            $postingResult = $this->postingExecutor->execute($postingCommand);

            if ($postingResult->isReplay()) {
                $existingClosure = $this->periodClosureRepository->findForTenantAndDate($command->tenantId(), $command->closedThroughDate());

                if ($existingClosure === null) {
                    throw CorruptPeriodClosureRecordException::forUnresolvableClosure($command->closingJournalId());
                }

                return PeriodClosingResult::replayed($existingClosure);
            }

            $closure = new PeriodClosure(
                $command->tenantId(),
                $command->closedThroughDate(),
                $postingResult->journal()->id(),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );

            $this->periodClosureRepository->record($closure);

            return PeriodClosingResult::newlyClosed($closure);
        });
    }
}
