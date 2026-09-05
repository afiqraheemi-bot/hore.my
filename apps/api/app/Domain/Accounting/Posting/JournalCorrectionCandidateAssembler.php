<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Accounting\Posting\Exception\UnresolvedCorrectionTargetException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Journal\JournalRepository;

/**
 * Assembles a {@see ReverseJournalCommand} or {@see ReplaceJournalCommand}
 * into a candidate Draft `Journal`, through the Journal domain's own
 * `reverse()`/`createReplacement()` factories (M5, AETS-004 §16, §17).
 *
 * **A boundary, not the correction rule itself.** Structural/financial
 * correction invariants (neutrality, correction-chain consistency,
 * eligibility of the referenced Journal) are enforced entirely by
 * {@see Journal::reverse()} and {@see Journal::createReplacement()}
 * themselves — this class neither catches nor wraps any of their typed
 * exceptions, mirroring {@see DraftJournalAssembler}'s own relationship
 * to `Journal::create()`.
 *
 * **Account validation — both correction types, applied uniformly.**
 * AETS-004 §16/§17 both require a Reversal or Replacement to be
 * "posted through the ordinary Posting Command pipeline (§10–§12) like
 * any other Journal" — §12 is exactly {@see PostingCommandAccountValidator}'s
 * existing four-step Account check. This class reuses it unmodified for
 * both correction types, via a throwaway comparison-only `PostingCommand`
 * (the same technique {@see PostingCommandIdempotencyResolver} already
 * establishes) — never a second, parallel validation rule. A Reversal's
 * derived lines reference the same Accounts the original Journal
 * already used, but an Account's Active/posting-eligible status can
 * change after the original was posted; re-checking it here is the
 * literal consequence of AETS-004 §16's own "ordinary pipeline" wording,
 * not an invented restriction (a real operational implication worth
 * Founder awareness: an Account deactivated after posting can render
 * Journals against it un-reversible, exactly as it already renders them
 * un-postable for a fresh command).
 *
 * **What this does not do.** It performs no idempotency resolution, no
 * transaction, and no persistence — {@see JournalCorrectionTransactionalExecutor}
 * owns all of that.
 */
final class JournalCorrectionCandidateAssembler
{
    /**
     * Never read by {@see PostingCommandAccountValidator} — present
     * only to satisfy `PostingCommand`'s constructor when reusing it as
     * a throwaway Account-validation carrier.
     */
    private const VALIDATION_PLACEHOLDER = 'journal-correction-account-validation-placeholder';

    public function __construct(
        private readonly JournalRepository $journalRepository,
        private readonly PostingCommandAccountValidator $accountValidator,
    ) {}

    /**
     * @throws UnresolvedCorrectionTargetException if `$command`'s
     *                                             original Journal cannot be resolved for its Tenant.
     * @throws RejectedAccountReferenceException if any Account the
     *                                           derived Reversal lines reference fails Account validation.
     */
    public function assembleReversal(ReverseJournalCommand $command): Journal
    {
        $original = $this->journalRepository->findById($command->tenantId(), $command->originalJournalId());

        if ($original === null) {
            throw UnresolvedCorrectionTargetException::forJournalId($command->originalJournalId());
        }

        $candidate = $original->reverse($command->newJournalId());

        $this->validateAccounts($command->tenantId(), $command->newJournalId(), $candidate->lines());

        return $candidate;
    }

    /**
     * @throws UnresolvedCorrectionTargetException if `$command`'s
     *                                             Reversal Journal cannot be resolved for its Tenant.
     * @throws RejectedAccountReferenceException if any Account
     *                                           `$command`'s lines reference fails Account validation.
     */
    public function assembleReplacement(ReplaceJournalCommand $command): Journal
    {
        $reversal = $this->journalRepository->findById($command->tenantId(), $command->reversalJournalId());

        if ($reversal === null) {
            throw UnresolvedCorrectionTargetException::forJournalId($command->reversalJournalId());
        }

        $this->validateAccounts($command->tenantId(), $command->newJournalId(), $command->lines());

        return Journal::createReplacement($command->tenantId(), $command->newJournalId(), $command->lines(), $reversal);
    }

    /**
     * @param  list<JournalLine>  $lines
     */
    private function validateAccounts(TenantId $tenantId, JournalId $journalId, array $lines): void
    {
        $this->accountValidator->validate(new PostingCommand(
            IdempotencyKey::of(self::VALIDATION_PLACEHOLDER),
            $tenantId,
            ActorReference::of(self::VALIDATION_PLACEHOLDER),
            SourceReference::of(self::VALIDATION_PLACEHOLDER),
            $journalId,
            $lines,
        ));
    }
}
