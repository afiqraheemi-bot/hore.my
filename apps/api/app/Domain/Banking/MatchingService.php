<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Banking\Exception\MatchConfirmationConflictException;
use App\Domain\Banking\Exception\NoSuchMatchCandidateException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Banking\BankAccountRepository;
use App\Infrastructure\Banking\BankTransactionRepository;
use App\Infrastructure\Banking\MatchRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * The application service that confirms a Match (M18, SRS BNK-005) —
 * the only class in the Matching slice of the Banking domain that
 * writes anything; {@see BankTransactionMatchSuggester} only reads.
 *
 * **Re-validates candidacy at confirmation time, never trusts a
 * client-supplied `(BankTransactionId, JournalId)` pair blindly** — see
 * {@see NoSuchMatchCandidateException}'s own docblock for why. The
 * pair a caller submits must still appear in
 * {@see BankTransactionMatchSuggester::suggestFor()}'s own output at
 * the moment of confirmation; if the underlying data changed since the
 * suggestion was first shown (another BankTransaction claimed the same
 * Journal, for example), confirmation is rejected rather than trusting
 * stale client state.
 *
 * **Concurrency contract (AETS-008 §12.6, `BNK-018`).** `confirm()`
 * never trusts a check-then-insert race: it locks the target Journal's
 * row before revalidating candidacy (closing the two-leg-Transfer race
 * `BNK-014` needs — see {@see BankTransactionMatchSuggester}'s own
 * docblock) and, on a `bank_transaction_id`-uniqueness race it cannot
 * avoid by locking (two different BankTransactions can only collide at
 * insert time), catches the resulting constraint violation and
 * re-checks: the same (BankTransaction, Journal) pair a concurrent
 * winner already confirmed is a deterministic replay
 * ({@see MatchConfirmationResult::replayed()}), never an error; a
 * *different* Journal is an explicit
 * {@see MatchConfirmationConflictException}. Mirrors WTS-001
 * `TSK-011`'s identical replay-vs-conflict pattern.
 */
final class MatchingService
{
    private const MATCHES_BANK_TRANSACTION_UNIQUE_CONSTRAINT = 'matches_bank_transaction_id_unique';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly BankAccountRepository $bankAccountRepository,
        private readonly BankTransactionRepository $bankTransactionRepository,
        private readonly BankTransactionMatchSuggester $suggester,
        private readonly MatchRepository $matchRepository,
    ) {}

    /**
     * @return list<MatchCandidate>
     */
    public function suggestFor(TenantId $tenantId, BankAccountId $bankAccountId): array
    {
        $bankAccount = $this->bankAccountRepository->findById($tenantId, $bankAccountId);

        if ($bankAccount === null) {
            return [];
        }

        $bankTransactions = $this->bankTransactionRepository->findByBankAccount($tenantId, $bankAccountId);
        $matchedIds = $this->matchRepository->matchedBankTransactionIds(
            $tenantId,
            array_map(static fn (BankTransaction $t): BankTransactionId => $t->id(), $bankTransactions),
        );

        $candidates = [];

        foreach ($bankTransactions as $bankTransaction) {
            if (self::containsId($matchedIds, $bankTransaction->id())) {
                continue;
            }

            foreach ($this->suggester->suggestFor($tenantId, $bankAccount, $bankTransaction) as $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * @throws MatchConfirmationConflictException if a concurrent caller
     *                                            already confirmed this BankTransaction against a *different*
     *                                            Journal.
     * @throws NoSuchMatchCandidateException if `$journalId` is not
     *                                       (still) a valid candidate for `$bankTransactionId`.
     */
    public function confirm(TenantId $tenantId, BankTransactionId $bankTransactionId, JournalId $journalId, ActorReference $actor): MatchConfirmationResult
    {
        // Sequential replay/conflict short-circuit: cheaper than the
        // full lock-and-revalidate path below, and necessary
        // correctness-wise too — the suggester (BNK-014) excludes a
        // Journal that already has a Match, which would otherwise make
        // a legitimate replay of an *already-confirmed* pair look like
        // "not a valid candidate" instead of a deterministic no-op
        // success.
        $existing = $this->matchRepository->findByBankTransactionId($tenantId, $bankTransactionId);

        if ($existing !== null) {
            if ($existing->journalId()->equals($journalId)) {
                return MatchConfirmationResult::replayed($existing);
            }

            throw MatchConfirmationConflictException::forBankTransaction($bankTransactionId);
        }

        try {
            $match = $this->connection->transaction(function () use ($tenantId, $bankTransactionId, $journalId, $actor): BankTransactionMatch {
                $bankTransactionRecord = $this->bankTransactionRepository->findById($tenantId, $bankTransactionId);

                if ($bankTransactionRecord === null) {
                    throw NoSuchMatchCandidateException::forPair($bankTransactionId, $journalId);
                }

                $bankAccount = $this->bankAccountRepository->findById($tenantId, $bankTransactionRecord->bankAccountId());

                if ($bankAccount === null) {
                    throw NoSuchMatchCandidateException::forPair($bankTransactionId, $journalId);
                }

                // Locks the Journal row so a concurrent confirmation
                // racing for this same Journal (a Transfer's second
                // leg, or a would-be third leg, BNK-014) serializes
                // instead of both reading a stale existing-match count
                // from the suggester below.
                $this->connection->table('journals')
                    ->where('tenant_id', $tenantId->toString())
                    ->where('journal_id', $journalId->toString())
                    ->lockForUpdate()
                    ->first();

                $candidates = $this->suggester->suggestFor($tenantId, $bankAccount, $bankTransactionRecord);
                $matchedCandidate = null;

                foreach ($candidates as $candidate) {
                    if ($candidate->journalId()->equals($journalId)) {
                        $matchedCandidate = $candidate;

                        break;
                    }
                }

                if ($matchedCandidate === null) {
                    throw NoSuchMatchCandidateException::forPair($bankTransactionId, $journalId);
                }

                $match = BankTransactionMatch::confirm(
                    MatchId::of((string) Str::uuid()),
                    $tenantId,
                    $bankTransactionId,
                    $journalId,
                    $matchedCandidate->sourceType(),
                    $matchedCandidate->rationale(),
                    $actor,
                    new \DateTimeImmutable,
                );

                $this->matchRepository->record($match);

                return $match;
            });
        } catch (QueryException $e) {
            // Lost a genuine race: a concurrent caller committed a
            // Match for this BankTransaction between this call's own
            // upfront check above and its insert attempt here. The
            // transaction Laravel just rolled back on this exception
            // leaves the connection clean, so re-checking now is safe
            // — unlike checking from inside the failed transaction,
            // which would hit PostgreSQL's "current transaction is
            // aborted" error instead of a real answer.
            if (! self::isBankTransactionUniquenessViolation($e)) {
                throw $e;
            }

            $winner = $this->matchRepository->findByBankTransactionId($tenantId, $bankTransactionId);

            if ($winner !== null && $winner->journalId()->equals($journalId)) {
                return MatchConfirmationResult::replayed($winner);
            }

            throw MatchConfirmationConflictException::forBankTransaction($bankTransactionId);
        }

        return MatchConfirmationResult::newlyConfirmed($match);
    }

    private static function isBankTransactionUniquenessViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505' && str_contains($e->getMessage(), self::MATCHES_BANK_TRANSACTION_UNIQUE_CONSTRAINT);
    }

    /**
     * @param  list<BankTransactionId>  $ids
     */
    private static function containsId(array $ids, BankTransactionId $needle): bool
    {
        foreach ($ids as $id) {
            if ($id->equals($needle)) {
                return true;
            }
        }

        return false;
    }
}
