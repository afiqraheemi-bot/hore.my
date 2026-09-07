<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * A confirmed link between a BankTransaction and the Journal it
 * corresponds to (M18, SRS BNK-005) — see the owning migration's own
 * docblock for why every persisted Match row represents a *confirmed*
 * match only, and why `bank_transaction_id` is unique.
 *
 * **Immutable, append-only.** There is no public mutator — a Match, once
 * confirmed, is a historical fact. Undoing one, if ever needed, is a
 * future explicit "unmatch" feature, not a mutation of this record.
 */
final class BankTransactionMatch
{
    private function __construct(
        private readonly MatchId $id,
        private readonly TenantId $tenantId,
        private readonly BankTransactionId $bankTransactionId,
        private readonly JournalId $journalId,
        private readonly MatchSourceType $sourceType,
        private readonly string $rationale,
        private readonly ActorReference $matchedBy,
        private readonly \DateTimeImmutable $matchedAt,
    ) {}

    public static function confirm(
        MatchId $id,
        TenantId $tenantId,
        BankTransactionId $bankTransactionId,
        JournalId $journalId,
        MatchSourceType $sourceType,
        string $rationale,
        ActorReference $matchedBy,
        \DateTimeImmutable $matchedAt,
    ): self {
        return new self($id, $tenantId, $bankTransactionId, $journalId, $sourceType, $rationale, $matchedBy, $matchedAt);
    }

    public static function reconstitute(
        MatchId $id,
        TenantId $tenantId,
        BankTransactionId $bankTransactionId,
        JournalId $journalId,
        MatchSourceType $sourceType,
        string $rationale,
        ActorReference $matchedBy,
        \DateTimeImmutable $matchedAt,
    ): self {
        return new self($id, $tenantId, $bankTransactionId, $journalId, $sourceType, $rationale, $matchedBy, $matchedAt);
    }

    public function id(): MatchId
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function bankTransactionId(): BankTransactionId
    {
        return $this->bankTransactionId;
    }

    public function journalId(): JournalId
    {
        return $this->journalId;
    }

    public function sourceType(): MatchSourceType
    {
        return $this->sourceType;
    }

    public function rationale(): string
    {
        return $this->rationale;
    }

    public function matchedBy(): ActorReference
    {
        return $this->matchedBy;
    }

    public function matchedAt(): \DateTimeImmutable
    {
        return $this->matchedAt;
    }

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }
}
