<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Audit;

use App\Domain\Accounting\Audit\AuditAction;
use App\Domain\Accounting\Audit\AuditEvent;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the Audit Event (AETS-010 §7), through
 * the production `audit_events` table.
 *
 * **A recorder, not a query API.** This class exposes exactly one
 * method: {@see record()}. AETS-010 §2.2 explicitly defers Audit Trail
 * query, UI, and export to a future Reporting/Compliance specification
 * — nothing in this milestone reads an Audit Event back, so no
 * `findBy*()` method is invented speculatively.
 *
 * **Append-only, structurally.** There is no `update()` and no
 * `delete()` — AETS-010 §7 requires an Audit Event never be updated or
 * deleted through the application, and this class simply exposes no
 * operation capable of either.
 *
 * **Transaction participation.** This method opens no transaction of
 * its own — the `INSERT` runs directly on `$this->connection`'s current
 * state, so when a caller has already started a transaction on that
 * same connection (the outer Posting or Journal Correction transaction
 * that also persists the Journal itself, per AETS-010 §11's atomicity
 * requirement), this insert becomes part of it: rolled back if that
 * outer transaction rolls back, durable only once it commits. Mirrors
 * {@see PostingIdempotencyRepository::record()}'s
 * own documented participation pattern exactly.
 */
final class AuditEventRepository
{
    private const TABLE = 'audit_events';

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function record(AuditEvent $event): void
    {
        $this->connection->table(self::TABLE)->insert([
            'tenant_id' => $event->tenantId()->toString(),
            'actor' => $event->actor()->toString(),
            'source' => $event->source()->toString(),
            'action' => self::toPersistedAction($event->action()),
            'journal_id' => $event->subjectJournalId()->toString(),
            'policy_version' => null,
        ]);
    }

    private static function toPersistedAction(AuditAction $action): string
    {
        return $action->name;
    }
}
