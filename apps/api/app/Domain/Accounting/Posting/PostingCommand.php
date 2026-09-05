<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Posting\Exception\InvalidPostingCommandException;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * A Posting Command (AETS-001, Accounting Command; AETS-007 §4–§5):
 * the concrete, immutable request Accounting Core requires before it
 * will consider transitioning a Journal from Draft to Posted.
 *
 * **This class is a pure data carrier — construction-level only.**
 * It implements exactly AETS-007 §5's field list and §7's
 * construction-level guarantees (`POST-T001`–`POST-T010`): every
 * always-required field is present, and its two collection fields
 * (proposed Journal Lines, Evidence references) contain only members
 * of the correct type, in the order supplied. It performs no I/O,
 * calls no repository, and enforces none of the Posting Validation
 * Pipeline's own rules — tenant ownership, Actor authorization,
 * Source classification, whether a Source Fingerprint is required,
 * Source Fingerprint/Idempotency Key derivation, minimum Journal Line
 * count, Debit/Credit balance, Account existence/status, Money
 * validity, duplicate detection, idempotent-replay resolution, or any
 * persistence effect. Every one of those remains the future Posting
 * Engine's responsibility (AETS-007 §14 onward), never this class's.
 *
 * **Not a Journal, not an Accounting Proposal.** Constructing a
 * PostingCommand has no ledger effect of any kind (`POST-T009`) — it
 * is a request *to* Accounting Core, never the authoritative record
 * itself. Its mandatory {@see ActorReference} field structurally
 * distinguishes it from a bare Accounting Proposal, which carries no
 * confirming Actor (`POST-T010`) — an AI-originated proposal cannot
 * become a PostingCommand until an Actor is supplied.
 *
 * **Always-required vs. conditionally-required fields.** Idempotency
 * Key, TenantId, Actor, Source, the proposed Journal identity, and the
 * proposed Journal Lines are always required (AETS-007 §5) — PHP's own
 * strict constructor typing enforces their presence; there is no
 * runtime "missing field" check to write, because a caller cannot
 * construct this class without supplying all of them (`POST-002`).
 * Source Fingerprint and Evidence references are each conditionally
 * required by the future Posting Command contract (AETS-007 §6.2,
 * §10) — *whether* a given command needs either is not this class's
 * decision, so both default to their "absent" form (`null`, `[]`)
 * rather than being enforced here.
 *
 * **Proposed Journal identity, one field for both shapes.** AETS-007
 * §11 permits a Posting Command's proposed Journal identity to be
 * either a fresh, not-yet-persisted identifier or a reference to an
 * existing Draft Journal. Both shapes are the same {@see JournalId}
 * value; which case applies is determined later, by whether a Journal
 * already exists under that identifier — a repository lookup the
 * future Posting Engine performs, never something this class decides
 * or records itself (`POST-T007`, `POST-T008`).
 */
final class PostingCommand
{
    private readonly IdempotencyKey $idempotencyKey;

    private readonly TenantId $tenantId;

    private readonly ActorReference $actor;

    private readonly SourceReference $source;

    private readonly JournalId $journalId;

    /**
     * @var list<JournalLine>
     */
    private readonly array $lines;

    private readonly ?SourceFingerprint $sourceFingerprint;

    /**
     * @var list<string>
     */
    private readonly array $evidenceReferences;

    /**
     * @param  list<JournalLine>  $lines  The ordered set of proposed
     *                                    Journal Lines this command carries. Every member MUST be a
     *                                    {@see JournalLine} instance; order is preserved exactly as
     *                                    supplied. This constructor does not validate line count, Money
     *                                    validity, or balance — those are future Posting Engine
     *                                    responsibilities (AETS-007 §13, §14).
     * @param  list<string>  $evidenceReferences  Opaque Evidence reference strings, `[]` when this
     *                                            command carries none (AETS-007 §10). Every member MUST be a
     *                                            string; no further grammar, format, or identifier semantics is
     *                                            validated here — Evidence's own reference contract remains
     *                                            entirely deferred (AETS-007 §26), unlike Actor/Source's minimal
     *                                            contract (§8.1, §9.1).
     *
     * @throws InvalidPostingCommandException if `$lines` contains a
     *                                        non-`JournalLine` member, or `$evidenceReferences` contains a
     *                                        non-string member.
     */
    public function __construct(
        IdempotencyKey $idempotencyKey,
        TenantId $tenantId,
        ActorReference $actor,
        SourceReference $source,
        JournalId $journalId,
        array $lines,
        ?SourceFingerprint $sourceFingerprint = null,
        array $evidenceReferences = [],
    ) {
        $this->idempotencyKey = $idempotencyKey;
        $this->tenantId = $tenantId;
        $this->actor = $actor;
        $this->source = $source;
        $this->journalId = $journalId;
        $this->lines = self::assertJournalLines($lines);
        $this->sourceFingerprint = $sourceFingerprint;
        $this->evidenceReferences = self::assertEvidenceReferences($evidenceReferences);
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

    public function journalId(): JournalId
    {
        return $this->journalId;
    }

    /**
     * @return list<JournalLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function sourceFingerprint(): ?SourceFingerprint
    {
        return $this->sourceFingerprint;
    }

    /**
     * @return list<string>
     */
    public function evidenceReferences(): array
    {
        return $this->evidenceReferences;
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

    /**
     * @param  array<array-key, mixed>  $evidenceReferences
     * @return list<string>
     *
     * @throws InvalidPostingCommandException
     */
    private static function assertEvidenceReferences(array $evidenceReferences): array
    {
        $index = 0;
        foreach ($evidenceReferences as $evidenceReference) {
            if (! is_string($evidenceReference)) {
                throw InvalidPostingCommandException::forNonStringEvidenceReference($index);
            }
            $index++;
        }

        return array_values($evidenceReferences);
    }
}
