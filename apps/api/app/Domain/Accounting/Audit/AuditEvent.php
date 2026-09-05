<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Audit;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * An Audit Event (AETS-001, Audit Event; AETS-010 §7): the append-only
 * record that a material action — currently, an ordinary Posting, a
 * Reversal, or a Replacement (AETS-010 §10) — happened, capturing at
 * minimum the Tenant, Actor, Source, Audit Action, and the Journal it
 * concerns.
 *
 * **A pure data carrier — construction-level only.** This class
 * performs no I/O, opens no transaction, and decides nothing about
 * *when* an Audit Event is required — that is
 * `PostingCommandTransactionalExecutor`'s and
 * `JournalCorrectionTransactionalExecutor`'s job (AETS-010 §10, §11).
 * It carries no Time field of its own: per AETS-010 §7, an Audit
 * Event's Time is the moment it is durably recorded, and this
 * codebase's own established convention — mirroring
 * `posting_idempotency_keys.created_at` (M4-T15) — is a
 * database-assigned timestamp at insert time, not a domain-supplied
 * value a caller could get wrong or backdate.
 *
 * **No Policy/model version field here either.** AETS-010 §7 reserves
 * that field in the *persisted* schema, "present only where
 * applicable" — since no AI Orchestration module exists yet (AETS-010
 * §2.2), no current producer (AETS-010 §10) has one to supply, so this
 * domain object simply has no field to be `null` in every real case.
 * `AuditEventRepository::record()` persists that column as `null`
 * until a future producer needs it.
 */
final class AuditEvent
{
    public function __construct(
        private readonly TenantId $tenantId,
        private readonly ActorReference $actor,
        private readonly SourceReference $source,
        private readonly AuditAction $action,
        private readonly JournalId $subjectJournalId,
    ) {}

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

    public function action(): AuditAction
    {
        return $this->action;
    }

    public function subjectJournalId(): JournalId
    {
        return $this->subjectJournalId;
    }
}
