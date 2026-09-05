<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Posting;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Audit\AuditEventRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for a Journal's Evidence Linkage (AETS-010
 * §9), through the production `journal_evidence_links` table.
 *
 * **A recorder, not a query API** — see
 * {@see AuditEventRepository}'s
 * own docblock for the identical reasoning: nothing in this milestone
 * reads a linkage back, so no `findBy*()` method is invented
 * speculatively.
 *
 * **No linkage row for an empty list.** {@see link()} performs no
 * `INSERT` at all when given an empty `$evidenceReferences` — a Journal
 * with no independent evidence produces zero linkage rows, never a
 * fabricated one (AETS-010 §9, `AUD-009`).
 *
 * **Transaction participation.** Identical to
 * {@see PostingIdempotencyRepository::record()}
 * and {@see AuditEventRepository::record()}:
 * this method opens no transaction of its own, so it participates in
 * whatever transaction is already open on `$this->connection`.
 */
final class JournalEvidenceLinkRepository
{
    private const TABLE = 'journal_evidence_links';

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<EvidenceReference>  $evidenceReferences
     */
    public function link(TenantId $tenantId, JournalId $journalId, array $evidenceReferences): void
    {
        if ($evidenceReferences === []) {
            return;
        }

        $this->connection->table(self::TABLE)->insert(array_map(
            static fn (EvidenceReference $reference): array => [
                'tenant_id' => $tenantId->toString(),
                'journal_id' => $journalId->toString(),
                'evidence_reference' => $reference->toString(),
            ],
            $evidenceReferences,
        ));
    }
}
