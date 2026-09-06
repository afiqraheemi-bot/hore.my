<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Reporting;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Accounting\Reporting\EvidenceIndex;
use App\Domain\Accounting\Reporting\EvidenceIndexEntry;
use App\Domain\Shared\Tenancy\TenantId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Computes an {@see EvidenceIndex} for a given Period (AETS-009 §10,
 * SRS RPT-008) — every Posted Journal belonging to the Tenant with
 * `financial_date` inside the Period, its Source (`audit_events`), and
 * every linked Evidence Reference (`journal_evidence_links`), `[]` when
 * none — read directly, mirroring {@see GeneralLedgerQuery}'s own
 * reasoning for why a read-only report reads these tables directly
 * rather than through a write-side repository.
 */
final class EvidenceIndexQuery
{
    private const JOURNAL_TABLE = 'journals';

    private const AUDIT_EVENT_TABLE = 'audit_events';

    private const EVIDENCE_LINK_TABLE = 'journal_evidence_links';

    private const POSTED_STATE = 'Posted';

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function forPeriod(TenantId $tenantId, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): EvidenceIndex
    {
        /** @var Collection<int, object{journal_id: string, financial_date: string, source: string}> $rows */
        $rows = $this->connection->table(self::JOURNAL_TABLE)
            ->join(self::AUDIT_EVENT_TABLE, function ($join): void {
                $join->on(self::JOURNAL_TABLE.'.tenant_id', '=', self::AUDIT_EVENT_TABLE.'.tenant_id')
                    ->on(self::JOURNAL_TABLE.'.journal_id', '=', self::AUDIT_EVENT_TABLE.'.journal_id');
            })
            ->where(self::JOURNAL_TABLE.'.tenant_id', $tenantId->toString())
            ->where(self::JOURNAL_TABLE.'.state', self::POSTED_STATE)
            ->where(self::JOURNAL_TABLE.'.financial_date', '>=', $periodStart->format('Y-m-d'))
            ->where(self::JOURNAL_TABLE.'.financial_date', '<=', $periodEnd->format('Y-m-d'))
            ->orderBy(self::JOURNAL_TABLE.'.financial_date')
            ->get([
                self::JOURNAL_TABLE.'.journal_id',
                self::JOURNAL_TABLE.'.financial_date',
                self::AUDIT_EVENT_TABLE.'.source',
            ]);

        /** @var list<string> $journalIds */
        $journalIds = $rows->pluck('journal_id')->unique()->values()->all();
        $evidenceByJournalId = $this->fetchEvidenceReferences($tenantId, $journalIds);

        $entries = [];

        foreach ($rows as $row) {
            $entries[] = new EvidenceIndexEntry(
                JournalId::of($row->journal_id),
                new \DateTimeImmutable($row->financial_date),
                SourceReference::of($row->source),
                $evidenceByJournalId[$row->journal_id] ?? [],
            );
        }

        return new EvidenceIndex($tenantId, $periodStart, $periodEnd, $entries);
    }

    /**
     * @param  list<string>  $journalIds
     * @return array<string, list<string>>
     */
    private function fetchEvidenceReferences(TenantId $tenantId, array $journalIds): array
    {
        if ($journalIds === []) {
            return [];
        }

        /** @var Collection<int, object{journal_id: string, evidence_reference: string}> $rows */
        $rows = $this->connection->table(self::EVIDENCE_LINK_TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->whereIn('journal_id', $journalIds)
            ->get(['journal_id', 'evidence_reference']);

        $byJournalId = [];

        foreach ($rows as $row) {
            $byJournalId[$row->journal_id][] = $row->evidence_reference;
        }

        return $byJournalId;
    }
}
