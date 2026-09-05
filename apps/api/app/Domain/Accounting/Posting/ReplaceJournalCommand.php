<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Posting\Exception\InvalidPostingCommandException;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * A request to replace a Reversal Journal with the correct economic
 * effect (M5, AETS-004 §17): the caller supplies the new Replacement
 * Journal's own identity, which Reversal it replaces, and the
 * Replacement's own lines — unlike a Reversal, no derivation rule
 * exists for "the correct amount", so the caller (business logic
 * upstream of this command) remains the sole source of a Replacement's
 * proposed lines. Structurally distinct from `PostingCommand`: this is
 * not a Posting Command, and none of its fields are ever added to
 * `PostingCommand` (M5 architecture decision #1).
 *
 * **Actor and Source (M6, AETS-010 §10).** Every successful Replacement
 * MUST produce an Audit Event capturing Actor and Source (AETS-010
 * §7, §10) — reusing exactly the same `ActorReference`/`SourceReference`
 * contract `PostingCommand` already carries (§6), never a second
 * representation.
 *
 * Pure data carrier — construction-level only, mirroring
 * `PostingCommand`'s own line-membership guarantee (every member of
 * `$lines` MUST be a {@see JournalLine} instance). It performs no
 * further validation: not Account existence/eligibility, not balance,
 * not whether the referenced Reversal exists or is eligible to be
 * replaced, and it decides nothing about idempotency or persistence —
 * every one of those is {@see JournalCorrectionCandidateAssembler}'s
 * and {@see JournalCorrectionTransactionalExecutor}'s job.
 */
final class ReplaceJournalCommand
{
    /**
     * @var list<JournalLine>
     */
    private readonly array $lines;

    /**
     * @param  list<JournalLine>  $lines
     *
     * @throws InvalidPostingCommandException if `$lines` contains a
     *                                        non-`JournalLine` member.
     */
    public function __construct(
        private readonly IdempotencyKey $idempotencyKey,
        private readonly TenantId $tenantId,
        private readonly ActorReference $actor,
        private readonly SourceReference $source,
        private readonly JournalId $newJournalId,
        private readonly JournalId $reversalJournalId,
        array $lines,
    ) {
        $this->lines = self::assertJournalLines($lines);
    }

    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function actor(): ActorReference
    {
        return $this->actor;
    }

    public function source(): SourceReference
    {
        return $this->source;
    }

    public function newJournalId(): JournalId
    {
        return $this->newJournalId;
    }

    public function reversalJournalId(): JournalId
    {
        return $this->reversalJournalId;
    }

    /**
     * @return list<JournalLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return list<JournalLine>
     *
     * @throws InvalidPostingCommandException
     */
    private static function assertJournalLines(array $lines): array
    {
        $index = 0;
        foreach ($lines as $line) {
            if (! $line instanceof JournalLine) {
                throw InvalidPostingCommandException::forNonJournalLineMember($index);
            }
            $index++;
        }

        return array_values($lines);
    }
}
