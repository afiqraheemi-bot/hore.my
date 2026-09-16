<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Reporting\AccountBalance;
use App\Domain\Accounting\Reporting\BalanceSheet;
use App\Domain\Accounting\Reporting\EvidenceIndex;
use App\Domain\Accounting\Reporting\EvidenceIndexEntry;
use App\Domain\Accounting\Reporting\GeneralLedgerAccountActivity;
use App\Domain\Accounting\Reporting\GeneralLedgerEntry;
use App\Domain\Accounting\Reporting\NetBalance;
use App\Domain\Accounting\Reporting\ProfitAndLossStatement;
use App\Domain\Accounting\Reporting\TrialBalance;
use App\Domain\Invoicing\Reporting\AgingBucket;
use App\Domain\Invoicing\Reporting\AgingReport;
use App\Domain\Invoicing\Reporting\AgingReportLine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reporting\AsOfDateRequest;
use App\Http\Requests\Reporting\GeneralLedgerRequest;
use App\Http\Requests\Reporting\PeriodRequest;
use App\Http\Support\CsvResponseBuilder;
use App\Http\Support\CurrentTenant;
use App\Http\Support\XlsxResponseBuilder;
use App\Http\Support\ZipResponseBuilder;
use App\Infrastructure\Accounting\Reporting\BalanceSheetQuery;
use App\Infrastructure\Accounting\Reporting\EvidenceIndexQuery;
use App\Infrastructure\Accounting\Reporting\GeneralLedgerQuery;
use App\Infrastructure\Accounting\Reporting\ProfitAndLossQuery;
use App\Infrastructure\Accounting\Reporting\TrialBalanceQuery;
use App\Infrastructure\Invoicing\Reporting\AgingReportQuery;
use Symfony\Component\HttpFoundation\Response;
use Tests\Unit\Domain\Accounting\Reporting\ReportingHasNoWriteEffectTest;

/**
 * Wraps M10's five Accounting Core report Query classes plus M22's
 * Aging Report over HTTP — read-only, no new business logic (AETS-009
 * §5 rule 6, proven by {@see ReportingHasNoWriteEffectTest} at the
 * Query layer itself, extended to cover {@see AgingReportQuery} too;
 * this controller adds nothing that layer does not already guarantee).
 *
 * **`?format=csv|xlsx` (M23/AETS-009 §20, Hasil MVP item 8: "Eksport
 * PDF, XLSX dan CSV")** on every endpoint here returns the same
 * already-computed report reshaped into a downloadable CSV or XLSX
 * via {@see CsvResponseBuilder}/{@see XlsxResponseBuilder} instead of
 * JSON — no new Query-layer code, no new business logic, purely an
 * HTTP-layer presentation choice; both formats are built from the
 * identical `(header, rows)` tuple ({@see buildExport()}), so they can
 * never diverge in content. PDF rendering of a *report* (as opposed to
 * an Invoice/Quotation, see AETS-017) remains its own deferred item —
 * AETS-009 §2.2 explains why.
 *
 * **{@see compliancePack()} (AETS-009 §19, added 2026-09-16)** bundles
 * five of the reports above into one downloadable ZIP of CSVs —
 * itself only a presentation-layer combination of already-proven CSV
 * rows, never a new report or a new guarantee.
 */
final class ReportingController extends Controller
{
    public function __construct(
        private readonly TrialBalanceQuery $trialBalanceQuery,
        private readonly ProfitAndLossQuery $profitAndLossQuery,
        private readonly BalanceSheetQuery $balanceSheetQuery,
        private readonly GeneralLedgerQuery $generalLedgerQuery,
        private readonly EvidenceIndexQuery $evidenceIndexQuery,
        private readonly AgingReportQuery $agingReportQuery,
    ) {}

    public function trialBalance(AsOfDateRequest $request, CurrentTenant $currentTenant): Response
    {
        $trialBalance = $this->trialBalanceQuery->asOf($currentTenant->id(), new \DateTimeImmutable($request->string('as_of')->toString()));
        $format = $this->requestedExportFormat($request);

        if ($format !== null) {
            [$header, $rows] = $this->trialBalanceCsvRows($trialBalance);

            return $this->buildExport($format, sprintf('trial-balance-%s', $trialBalance->asOfDate()->format('Y-m-d')), $header, $rows);
        }

        return response()->json($this->trialBalanceToArray($trialBalance));
    }

    public function profitAndLoss(PeriodRequest $request, CurrentTenant $currentTenant): Response
    {
        $statement = $this->profitAndLossQuery->forPeriod(
            $currentTenant->id(),
            new \DateTimeImmutable($request->string('period_start')->toString()),
            new \DateTimeImmutable($request->string('period_end')->toString()),
        );
        $format = $this->requestedExportFormat($request);

        if ($format !== null) {
            [$header, $rows] = $this->profitAndLossCsvRows($statement);

            return $this->buildExport($format, sprintf('profit-and-loss-%s-to-%s', $statement->periodStart()->format('Y-m-d'), $statement->periodEnd()->format('Y-m-d')), $header, $rows);
        }

        return response()->json($this->profitAndLossToArray($statement));
    }

    public function balanceSheet(AsOfDateRequest $request, CurrentTenant $currentTenant): Response
    {
        $balanceSheet = $this->balanceSheetQuery->asOf($currentTenant->id(), new \DateTimeImmutable($request->string('as_of')->toString()));
        $format = $this->requestedExportFormat($request);

        if ($format !== null) {
            [$header, $rows] = $this->balanceSheetCsvRows($balanceSheet);

            return $this->buildExport($format, sprintf('balance-sheet-%s', $balanceSheet->asOfDate()->format('Y-m-d')), $header, $rows);
        }

        return response()->json($this->balanceSheetToArray($balanceSheet));
    }

    public function generalLedger(GeneralLedgerRequest $request, CurrentTenant $currentTenant): Response
    {
        $activity = $this->generalLedgerQuery->forAccountAndPeriod(
            $currentTenant->id(),
            AccountId::of($request->string('account_id')->toString()),
            new \DateTimeImmutable($request->string('period_start')->toString()),
            new \DateTimeImmutable($request->string('period_end')->toString()),
        );
        $format = $this->requestedExportFormat($request);

        if ($format !== null) {
            [$header, $rows] = $this->generalLedgerCsvRows($activity);

            return $this->buildExport($format, sprintf('general-ledger-%s-%s-to-%s', $activity->accountId()->toString(), $activity->periodStart()->format('Y-m-d'), $activity->periodEnd()->format('Y-m-d')), $header, $rows);
        }

        return response()->json($this->generalLedgerToArray($activity));
    }

    public function evidenceIndex(PeriodRequest $request, CurrentTenant $currentTenant): Response
    {
        $index = $this->evidenceIndexQuery->forPeriod(
            $currentTenant->id(),
            new \DateTimeImmutable($request->string('period_start')->toString()),
            new \DateTimeImmutable($request->string('period_end')->toString()),
        );
        $format = $this->requestedExportFormat($request);

        if ($format !== null) {
            [$header, $rows] = $this->evidenceIndexCsvRows($index);

            return $this->buildExport($format, sprintf('evidence-index-%s-to-%s', $index->periodStart()->format('Y-m-d'), $index->periodEnd()->format('Y-m-d')), $header, $rows);
        }

        return response()->json($this->evidenceIndexToArray($index));
    }

    public function agingReport(AsOfDateRequest $request, CurrentTenant $currentTenant): Response
    {
        $report = $this->agingReportQuery->asOf($currentTenant->id(), new \DateTimeImmutable($request->string('as_of')->toString()));
        $format = $this->requestedExportFormat($request);

        if ($format !== null) {
            [$header, $rows] = $this->agingReportCsvRows($report);

            return $this->buildExport($format, sprintf('aging-report-%s', $report->asOfDate()->format('Y-m-d')), $header, $rows);
        }

        return response()->json($this->agingReportToArray($report));
    }

    /**
     * AETS-009 §19 (Compliance Pack export): bundles Trial Balance,
     * Profit & Loss (the current Accounting Period), Balance Sheet,
     * Aging, and Evidence Index (the current Accounting Period) — the
     * five tenant-wide reports this controller already computes — as
     * CSV entries inside one downloadable ZIP archive, reusing each
     * report's own already-proven CSV row mapping unchanged (no new
     * business logic, mirrors this controller's own established rule
     * for `?format=csv`). General Ledger is not included: it requires
     * a specific Account, not a tenant-wide view, so there is no
     * single canonical CSV for it to bundle.
     *
     * **Not a compliance guarantee.** Per
     * [`HORE_MY_MASTER_CONTEXT.md`](../../../../../../docs/product/reference/HORE_MY_MASTER_CONTEXT.md)
     * §7's own definition: "compliance-ready" means organized,
     * consistent, traceable, exportable records for professional
     * review — never an automatic guarantee that an audit, tax filing,
     * or submission will be accepted.
     */
    public function compliancePack(PeriodRequest $request, CurrentTenant $currentTenant): Response
    {
        $periodStart = new \DateTimeImmutable($request->string('period_start')->toString());
        $periodEnd = new \DateTimeImmutable($request->string('period_end')->toString());

        $trialBalance = $this->trialBalanceQuery->asOf($currentTenant->id(), $periodEnd);
        $profitAndLoss = $this->profitAndLossQuery->forPeriod($currentTenant->id(), $periodStart, $periodEnd);
        $balanceSheet = $this->balanceSheetQuery->asOf($currentTenant->id(), $periodEnd);
        $agingReport = $this->agingReportQuery->asOf($currentTenant->id(), $periodEnd);
        $evidenceIndex = $this->evidenceIndexQuery->forPeriod($currentTenant->id(), $periodStart, $periodEnd);

        $entries = [
            'trial-balance.csv' => CsvResponseBuilder::toCsvString(...$this->trialBalanceCsvRows($trialBalance)),
            'profit-and-loss.csv' => CsvResponseBuilder::toCsvString(...$this->profitAndLossCsvRows($profitAndLoss)),
            'balance-sheet.csv' => CsvResponseBuilder::toCsvString(...$this->balanceSheetCsvRows($balanceSheet)),
            'aging-report.csv' => CsvResponseBuilder::toCsvString(...$this->agingReportCsvRows($agingReport)),
            'evidence-index.csv' => CsvResponseBuilder::toCsvString(...$this->evidenceIndexCsvRows($evidenceIndex)),
        ];

        return ZipResponseBuilder::build(
            sprintf('compliance-pack-%s-to-%s.zip', $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')),
            $entries,
        );
    }

    /**
     * `null` for a JSON request (no `format`, or `format=json`);
     * `'csv'` or `'xlsx'` otherwise — each Request class's own
     * validation rule (`in:json,csv,xlsx`) already guarantees no other
     * value ever reaches here.
     *
     * @return 'csv'|'xlsx'|null
     */
    private function requestedExportFormat(AsOfDateRequest|PeriodRequest|GeneralLedgerRequest $request): ?string
    {
        $format = $request->string('format')->toString();

        return match ($format) {
            'csv' => 'csv',
            'xlsx' => 'xlsx',
            default => null,
        };
    }

    /**
     * The single choke point every report's CSV/XLSX export passes
     * through (AETS-009 §20) — dispatches to
     * {@see CsvResponseBuilder}/{@see XlsxResponseBuilder} on the
     * identical `(header, rows)` tuple either way, so the two formats
     * can never diverge in content, only in file format.
     *
     * @param  'csv'|'xlsx'  $format
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     */
    private function buildExport(string $format, string $baseFilename, array $header, array $rows): Response
    {
        return match ($format) {
            'csv' => CsvResponseBuilder::build($baseFilename.'.csv', $header, $rows),
            'xlsx' => XlsxResponseBuilder::build($baseFilename.'.xlsx', $header, $rows),
        };
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function trialBalanceCsvRows(TrialBalance $trialBalance): array
    {
        return [
            ['Account ID', 'Account Type', 'Total Debit', 'Total Credit', 'Net Balance Amount', 'Net Balance Direction'],
            array_map($this->accountBalanceToCsvRow(...), $trialBalance->lines()),
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function profitAndLossCsvRows(ProfitAndLossStatement $statement): array
    {
        return [
            ['Section', 'Account ID', 'Account Type', 'Total Debit', 'Total Credit', 'Net Balance Amount', 'Net Balance Direction'],
            [
                ...array_map(fn (AccountBalance $line): array => ['Revenue', ...$this->accountBalanceToCsvRow($line)], $statement->revenueLines()),
                ...array_map(fn (AccountBalance $line): array => ['Expense', ...$this->accountBalanceToCsvRow($line)], $statement->expenseLines()),
            ],
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function balanceSheetCsvRows(BalanceSheet $balanceSheet): array
    {
        $netIncome = $balanceSheet->cumulativeNetIncome();

        return [
            ['Section', 'Account ID', 'Account Type', 'Total Debit', 'Total Credit', 'Net Balance Amount', 'Net Balance Direction'],
            [
                ...array_map(fn (AccountBalance $line): array => ['Asset', ...$this->accountBalanceToCsvRow($line)], $balanceSheet->assetLines()),
                ...array_map(fn (AccountBalance $line): array => ['Liability', ...$this->accountBalanceToCsvRow($line)], $balanceSheet->liabilityLines()),
                ...array_map(fn (AccountBalance $line): array => ['Equity', ...$this->accountBalanceToCsvRow($line)], $balanceSheet->equityLines()),
                ['Cumulative Net Income', '(Cumulative Net Income)', '', '', '', $netIncome->amount()->toDecimalString(), $netIncome->direction() === null ? '' : $netIncome->direction()->name],
            ],
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function generalLedgerCsvRows(GeneralLedgerAccountActivity $activity): array
    {
        return [
            ['Journal ID', 'Financial Date', 'Posted At', 'Amount', 'Direction', 'Source', 'Evidence References'],
            array_map(static fn (GeneralLedgerEntry $entry): array => [
                $entry->journalId()->toString(),
                $entry->financialDate()->format('Y-m-d'),
                $entry->postedAt()->format(DATE_ATOM),
                $entry->amount()->toDecimalString(),
                $entry->direction()->name,
                $entry->source()->toString(),
                implode('; ', $entry->evidenceReferences()),
            ], $activity->entries()),
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function evidenceIndexCsvRows(EvidenceIndex $index): array
    {
        return [
            ['Journal ID', 'Financial Date', 'Source', 'Has Evidence', 'Evidence References'],
            array_map(static fn (EvidenceIndexEntry $entry): array => [
                $entry->journalId()->toString(),
                $entry->financialDate()->format('Y-m-d'),
                $entry->source()->toString(),
                $entry->hasEvidence() ? 'Yes' : 'No',
                implode('; ', $entry->evidenceReferences()),
            ], $index->entries()),
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function agingReportCsvRows(AgingReport $report): array
    {
        return [
            ['Invoice ID', 'Invoice Number', 'Customer ID', 'Due Date', 'Outstanding Balance', 'Bucket'],
            array_map(static fn (AgingReportLine $line): array => [
                $line->invoiceId()->toString(),
                $line->invoiceNumber() ?? '',
                $line->customerId()->toString(),
                $line->dueDate()->format('Y-m-d'),
                $line->outstandingBalance()->toDecimalString(),
                $line->bucket()->name,
            ], $report->lines()),
        ];
    }

    /**
     * @return list<string>
     */
    private function accountBalanceToCsvRow(AccountBalance $line): array
    {
        return [
            $line->accountId()->toString(),
            $line->accountType()->name,
            $line->totalDebit()->toDecimalString(),
            $line->totalCredit()->toDecimalString(),
            $line->netBalance()->amount()->toDecimalString(),
            $line->netBalance()->direction() === null ? '' : $line->netBalance()->direction()->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trialBalanceToArray(TrialBalance $trialBalance): array
    {
        return [
            'as_of' => $trialBalance->asOfDate()->format('Y-m-d'),
            'is_balanced' => $trialBalance->isBalanced(),
            'total_debit' => $trialBalance->totalDebit()->toDecimalString(),
            'total_credit' => $trialBalance->totalCredit()->toDecimalString(),
            'lines' => array_map($this->accountBalanceToArray(...), $trialBalance->lines()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profitAndLossToArray(ProfitAndLossStatement $statement): array
    {
        return [
            'period_start' => $statement->periodStart()->format('Y-m-d'),
            'period_end' => $statement->periodEnd()->format('Y-m-d'),
            'total_revenue' => $statement->totalRevenue()->toDecimalString(),
            'total_expense' => $statement->totalExpense()->toDecimalString(),
            'net_income' => $statement->netIncome()->toDecimalString(),
            'is_profit' => $statement->isProfit(),
            'revenue_lines' => array_map($this->accountBalanceToArray(...), $statement->revenueLines()),
            'expense_lines' => array_map($this->accountBalanceToArray(...), $statement->expenseLines()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceSheetToArray(BalanceSheet $balanceSheet): array
    {
        return [
            'as_of' => $balanceSheet->asOfDate()->format('Y-m-d'),
            'is_balanced' => $balanceSheet->isBalanced(),
            'total_assets' => $balanceSheet->totalAssets()->toDecimalString(),
            'total_liabilities_and_equity' => $balanceSheet->totalLiabilitiesAndEquity()->toDecimalString(),
            'cumulative_net_income' => $this->netBalanceToArray($balanceSheet->cumulativeNetIncome()),
            'asset_lines' => array_map($this->accountBalanceToArray(...), $balanceSheet->assetLines()),
            'liability_lines' => array_map($this->accountBalanceToArray(...), $balanceSheet->liabilityLines()),
            'equity_lines' => array_map($this->accountBalanceToArray(...), $balanceSheet->equityLines()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generalLedgerToArray(GeneralLedgerAccountActivity $activity): array
    {
        return [
            'account_id' => $activity->accountId()->toString(),
            'period_start' => $activity->periodStart()->format('Y-m-d'),
            'period_end' => $activity->periodEnd()->format('Y-m-d'),
            'opening_balance' => $this->netBalanceToArray($activity->openingBalance()),
            'closing_balance' => $this->netBalanceToArray($activity->closingBalance()),
            'entries' => array_map(static fn (GeneralLedgerEntry $entry): array => [
                'journal_id' => $entry->journalId()->toString(),
                'financial_date' => $entry->financialDate()->format('Y-m-d'),
                'posted_at' => $entry->postedAt()->format(DATE_ATOM),
                'amount' => $entry->amount()->toDecimalString(),
                'direction' => $entry->direction()->name,
                'source' => $entry->source()->toString(),
                'evidence_references' => $entry->evidenceReferences(),
            ], $activity->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evidenceIndexToArray(EvidenceIndex $index): array
    {
        return [
            'period_start' => $index->periodStart()->format('Y-m-d'),
            'period_end' => $index->periodEnd()->format('Y-m-d'),
            'entries' => array_map(static fn (EvidenceIndexEntry $entry): array => [
                'journal_id' => $entry->journalId()->toString(),
                'financial_date' => $entry->financialDate()->format('Y-m-d'),
                'source' => $entry->source()->toString(),
                'has_evidence' => $entry->hasEvidence(),
                'evidence_references' => $entry->evidenceReferences(),
            ], $index->entries()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accountBalanceToArray(AccountBalance $line): array
    {
        return [
            'account_id' => $line->accountId()->toString(),
            'account_type' => $line->accountType()->name,
            'total_debit' => $line->totalDebit()->toDecimalString(),
            'total_credit' => $line->totalCredit()->toDecimalString(),
            'net_balance' => $this->netBalanceToArray($line->netBalance()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function netBalanceToArray(NetBalance $balance): array
    {
        return [
            'amount' => $balance->amount()->toDecimalString(),
            'direction' => $balance->direction()?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function agingReportToArray(AgingReport $report): array
    {
        return [
            'as_of' => $report->asOfDate()->format('Y-m-d'),
            'grand_total' => $report->grandTotal()->toDecimalString(),
            'bucket_totals' => [
                'current' => $report->totalForBucket(AgingBucket::Current)->toDecimalString(),
                'overdue_1_to_30' => $report->totalForBucket(AgingBucket::Overdue1To30)->toDecimalString(),
                'overdue_31_to_60' => $report->totalForBucket(AgingBucket::Overdue31To60)->toDecimalString(),
                'overdue_61_to_90' => $report->totalForBucket(AgingBucket::Overdue61To90)->toDecimalString(),
                'overdue_91_plus' => $report->totalForBucket(AgingBucket::Overdue91Plus)->toDecimalString(),
            ],
            'lines' => array_map($this->agingReportLineToArray(...), $report->lines()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function agingReportLineToArray(AgingReportLine $line): array
    {
        return [
            'invoice_id' => $line->invoiceId()->toString(),
            'invoice_number' => $line->invoiceNumber(),
            'customer_id' => $line->customerId()->toString(),
            'due_date' => $line->dueDate()->format('Y-m-d'),
            'outstanding_balance' => $line->outstandingBalance()->toDecimalString(),
            'bucket' => $line->bucket()->name,
        ];
    }
}
