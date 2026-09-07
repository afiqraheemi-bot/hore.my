<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Banking\Exception\BankTransactionAlreadyMatchedException;
use App\Domain\Banking\Exception\NoSuchMatchCandidateException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Banking\BankAccountRepository;
use App\Infrastructure\Banking\BankTransactionRepository;
use App\Infrastructure\Banking\MatchRepository;
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
 */
final class MatchingService
{
    public function __construct(
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
     * @throws BankTransactionAlreadyMatchedException if this
     *                                                BankTransaction already has a confirmed Match.
     * @throws NoSuchMatchCandidateException if `$journalId` is not
     *                                       (still) a valid candidate for `$bankTransactionId`.
     */
    public function confirm(TenantId $tenantId, BankTransactionId $bankTransactionId, JournalId $journalId, ActorReference $actor): BankTransactionMatch
    {
        if ($this->matchRepository->findByBankTransactionId($tenantId, $bankTransactionId) !== null) {
            throw BankTransactionAlreadyMatchedException::forBankTransaction($bankTransactionId);
        }

        $bankTransactionRecord = $this->bankTransactionRepository->findById($tenantId, $bankTransactionId);

        if ($bankTransactionRecord === null) {
            throw NoSuchMatchCandidateException::forPair($bankTransactionId, $journalId);
        }

        $bankAccount = $this->bankAccountRepository->findById($tenantId, $bankTransactionRecord->bankAccountId());

        if ($bankAccount === null) {
            throw NoSuchMatchCandidateException::forPair($bankTransactionId, $journalId);
        }

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
