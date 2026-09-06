<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Reporting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Accounting\Reporting\GeneralLedgerAccountActivity;
use App\Domain\Accounting\Reporting\GeneralLedgerEntry;
use App\Domain\Accounting\Reporting\NetBalance;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use App\Infrastructure\Accounting\Posting\JournalEvidenceLinkRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Computes a {@see GeneralLedgerAccountActivity} for a single Account
 * over a given Period (AETS-009 §9, SRS RPT-005) — every Posted Journal
 * Line referencing that Account with `financial_date` inside the
 * Period, in `financial_date` order (ties broken by `posted_at`),
 * together with each entry's Source ({@see SourceReference}, read from
 * `audit_events` — every Posted Journal has exactly one, `AUD-001`) and
 * linked Evidence References ({@see JournalEvidenceLinkRepository}'s
 * own `journal_evidence_links` table, read directly here since a
 * read-only report has no reason to route through a write-side
 * repository), plus the opening/closing {@see NetBalance}
 * ({@see AccountBalanceAggregator::netBalanceForAccount()}).
 */
final class GeneralLedgerQuery
{
    private const LINE_TABLE = 'journal_lines';

    private const JOURNAL_TABLE = 'journals';

    private const AUDIT_EVENT_TABLE = 'audit_events';

    private const EVIDENCE_LINK_TABLE = 'journal_evidence_links';

    private const POSTED_STATE = 'Posted';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly AccountBalanceAggregator $aggregator,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    public function forAccountAndPeriod(TenantId $tenantId, AccountId $accountId, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): GeneralLedgerAccountActivity
    {
        $openingBalance = $this->aggregator->netBalanceForAccount(
            $tenantId,
            $accountId,
            null,
            $periodStart->modify('-1 day'),
        );

        $closingBalance = $this->aggregator->netBalanceForAccount($tenantId, $accountId, null, $periodEnd);

        /** @var Collection<int, object{journal_id: string, financial_date: string, posted_at: string, amount: int|string, currency: string, direction: string, source: string}> $rows */
        $rows = $this->connection->table(self::LINE_TABLE)
            ->join(self::JOURNAL_TABLE, function ($join): void {
                $join->on(self::LINE_TABLE.'.tenant_id', '=', self::JOURNAL_TABLE.'.tenant_id')
                    ->on(self::LINE_TABLE.'.journal_id', '=', self::JOURNAL_TABLE.'.journal_id');
            })
            ->join(self::AUDIT_EVENT_TABLE, function ($join): void {
                $join->on(self::JOURNAL_TABLE.'.tenant_id', '=', self::AUDIT_EVENT_TABLE.'.tenant_id')
                    ->on(self::JOURNAL_TABLE.'.journal_id', '=', self::AUDIT_EVENT_TABLE.'.journal_id');
            })
            ->where(self::JOURNAL_TABLE.'.tenant_id', $tenantId->toString())
            ->where(self::JOURNAL_TABLE.'.state', self::POSTED_STATE)
            ->where(self::LINE_TABLE.'.account_id', $accountId->toString())
            ->where(self::JOURNAL_TABLE.'.financial_date', '>=', $periodStart->format('Y-m-d'))
            ->where(self::JOURNAL_TABLE.'.financial_date', '<=', $periodEnd->format('Y-m-d'))
            ->orderBy(self::JOURNAL_TABLE.'.financial_date')
            ->orderBy(self::JOURNAL_TABLE.'.posted_at')
            ->get([
                self::JOURNAL_TABLE.'.journal_id',
                self::JOURNAL_TABLE.'.financial_date',
                self::JOURNAL_TABLE.'.posted_at',
                self::LINE_TABLE.'.amount',
                self::LINE_TABLE.'.currency',
                self::LINE_TABLE.'.direction',
                self::AUDIT_EVENT_TABLE.'.source',
            ]);

        /** @var list<string> $journalIds */
        $journalIds = $rows->pluck('journal_id')->unique()->values()->all();
        $evidenceByJournalId = $this->fetchEvidenceReferences($tenantId, $journalIds);

        $entries = [];

        foreach ($rows as $row) {
            $entries[] = new GeneralLedgerEntry(
                JournalId::of($row->journal_id),
                new \DateTimeImmutable($row->financial_date),
                new \DateTimeImmutable($row->posted_at),
                $this->money->fromPersisted((string) $row->amount, $row->currency),
                $row->direction === JournalDirection::Debit->name ? JournalDirection::Debit : JournalDirection::Credit,
                SourceReference::of($row->source),
                $evidenceByJournalId[$row->journal_id] ?? [],
            );
        }

        return new GeneralLedgerAccountActivity($accountId, $periodStart, $periodEnd, $openingBalance, $entries, $closingBalance);
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
