<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Accounting\Reporting\AccountBalance;
use App\Domain\Accounting\Reporting\BalanceSheet;
use App\Domain\Accounting\Reporting\CashFlowLine;
use App\Domain\Accounting\Reporting\CashFlowStatement;
use App\Domain\Accounting\Reporting\EvidenceIndex;
use App\Domain\Accounting\Reporting\EvidenceIndexEntry;
use App\Domain\Accounting\Reporting\GeneralLedgerAccountActivity;
use App\Domain\Accounting\Reporting\GeneralLedgerEntry;
use App\Domain\Accounting\Reporting\GeneralLedgerRunningBalance;
use App\Domain\Accounting\Reporting\NetBalance;
use App\Domain\Accounting\Reporting\ProfitAndLossStatement;
use App\Domain\Accounting\Reporting\TrialBalance;
use App\Domain\Invoicing\Reporting\AgingBucket;
use App\Domain\Invoicing\Reporting\AgingReport;
use App\Domain\Invoicing\Reporting\AgingReportLine;
use App\Domain\Shared\Tenancy\TenantId;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reporting\AsOfDateRequest;
use App\Http\Requests\Reporting\GeneralLedgerRequest;
use App\Http\Requests\Reporting\PeriodRequest;
use App\Http\Support\CsvResponseBuilder;
use App\Http\Support\CurrentTenant;
use App\Http\Support\DocumentPartyFormatter;
use App\Http\Support\DocumentPdfBuilder;
use App\Http\Support\XlsxResponseBuilder;
use App\Http\Support\ZipResponseBuilder;
use App\Infrastructure\Accounting\Reporting\BalanceSheetQuery;
use App\Infrastructure\Accounting\Reporting\CashFlowStatementQuery;
use App\Infrastructure\Accounting\Reporting\EvidenceIndexQuery;
use App\Infrastructure\Accounting\Reporting\GeneralLedgerQuery;
use App\Infrastructure\Accounting\Reporting\ProfitAndLossQuery;
use App\Infrastructure\Accounting\Reporting\SourceDescriptionLookup;
use App\Infrastructure\Accounting\Reporting\TrialBalanceQuery;
use App\Infrastructure\Invoicing\Reporting\AgingReportQuery;
use App\Models\BusinessProfile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\Unit\Domain\Accounting\Reporting\ReportingHasNoWriteEffectTest;

/**
 * Wraps M10's five Accounting Core report Query classes, M22's Aging
 * Report, and (as of AETS-009 §22) {@see CashFlowStatementQuery} over
 * HTTP — read-only, no new business logic (AETS-009 §5 rule 6, proven
 * by {@see ReportingHasNoWriteEffectTest} at the Query layer itself,
 * extended to cover {@see AgingReportQuery} too; this controller adds
 * nothing that layer does not already guarantee).
 *
 * **`?format=csv|xlsx` (M23/AETS-009 §20, Hasil MVP item 8: "Eksport
 * PDF, XLSX dan CSV")** on every endpoint here returns the same
 * already-computed report reshaped into a downloadable CSV or XLSX
 * via {@see CsvResponseBuilder}/{@see XlsxResponseBuilder} instead of
 * JSON — no new Query-layer code, no new business logic, purely an
 * HTTP-layer presentation choice; both formats are built from the
 * identical `(header, rows)` tuple ({@see buildExport()}), so they can
 * never diverge in content.
 *
 * **`?format=pdf` on {@see profitAndLoss()}, {@see self::balanceSheet()},
 * and {@see cashFlow()} only (AETS-009 §21/§22, added 2026-09-17)**
 * returns a formatted, "loan-ready" statement via
 * {@see DocumentPdfBuilder} instead — the identical already-computed
 * figures every other format already returns, never a new computation
 * (`RPT-017`). PDF rendering remains deferred for every other report
 * (AETS-009 §2.2 explains why): Trial Balance, Evidence Index, and
 * Aging share {@see AsOfDateRequest}/{@see PeriodRequest} with
 * Balance Sheet/Profit & Loss/Cash Flow, so `format=pdf` validates but
 * silently falls back to JSON, exactly like any other unrecognized
 * format value; General Ledger's own dedicated
 * {@see GeneralLedgerRequest} does not accept `pdf` at all and rejects
 * it with a validation error instead — no PDF is produced by either
 * path.
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
        private readonly CashFlowStatementQuery $cashFlowStatementQuery,
        private readonly SourceDescriptionLookup $sourceDescriptionLookup,
    ) {}

    public function trialBalance(AsOfDateRequest $request, CurrentTenant $currentTenant): Response
    {
        $trialBalance = $this->trialBalanceQuery->asOf($currentTenant->id(), new \DateTimeImmutable($request->string('as_of')->toString()));

        if ($request->string('format')->toString() === 'pdf') {
            return $this->trialBalancePdf($trialBalance, $currentTenant);
        }

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

        if ($request->string('format')->toString() === 'pdf') {
            return $this->profitAndLossPdf($statement, $currentTenant);
        }

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

        if ($request->string('format')->toString() === 'pdf') {
            return $this->balanceSheetPdf($balanceSheet, $currentTenant);
        }

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

        if ($request->string('format')->toString() === 'pdf') {
            return $this->generalLedgerPdf($activity, $currentTenant);
        }

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

        if ($request->string('format')->toString() === 'pdf') {
            return $this->evidenceIndexPdf($index, $currentTenant);
        }

        $format = $this->requestedExportFormat($request);

        if ($format !== null) {
            [$header, $rows] = $this->evidenceIndexCsvRows($index);

            return $this->buildExport($format, sprintf('evidence-index-%s-to-%s', $index->periodStart()->format('Y-m-d'), $index->periodEnd()->format('Y-m-d')), $header, $rows);
        }

        return response()->json($this->evidenceIndexToArray($index, $currentTenant));
    }

    public function agingReport(AsOfDateRequest $request, CurrentTenant $currentTenant): Response
    {
        $report = $this->agingReportQuery->asOf($currentTenant->id(), new \DateTimeImmutable($request->string('as_of')->toString()));

        if ($request->string('format')->toString() === 'pdf') {
            return $this->agingReportPdf($report, $currentTenant);
        }

        $format = $this->requestedExportFormat($request);

        if ($format !== null) {
            [$header, $rows] = $this->agingReportCsvRows($report);

            return $this->buildExport($format, sprintf('aging-report-%s', $report->asOfDate()->format('Y-m-d')), $header, $rows);
        }

        return response()->json($this->agingReportToArray($report));
    }

    public function cashFlow(PeriodRequest $request, CurrentTenant $currentTenant): Response
    {
        $statement = $this->cashFlowStatementQuery->forPeriod(
            $currentTenant->id(),
            new \DateTimeImmutable($request->string('period_start')->toString()),
            new \DateTimeImmutable($request->string('period_end')->toString()),
        );

        if ($request->string('format')->toString() === 'pdf') {
            return $this->cashFlowPdf($statement, $currentTenant);
        }

        $format = $this->requestedExportFormat($request);

        if ($format !== null) {
            [$header, $rows] = $this->cashFlowCsvRows($statement);

            return $this->buildExport($format, sprintf('cash-flow-%s-to-%s', $statement->periodStart()->format('Y-m-d'), $statement->periodEnd()->format('Y-m-d')), $header, $rows);
        }

        return response()->json($this->cashFlowToArray($statement));
    }

    /**
     * AETS-009 §19 (Compliance Pack export): bundles Trial Balance,
     * Profit & Loss (the current Accounting Period), Balance Sheet,
     * Aging, Evidence Index (the current Accounting Period), and (as
     * of v1.8.0) Cash Flow — six tenant-wide reports this controller
     * already computes — as CSV entries inside one downloadable ZIP
     * archive, reusing each report's own already-proven CSV row
     * mapping unchanged (no new business logic, mirrors this
     * controller's own established rule for `?format=csv`). General
     * Ledger is not included: it requires a specific Account, not a
     * tenant-wide view, so there is no single canonical CSV for it to
     * bundle.
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
        $cashFlow = $this->cashFlowStatementQuery->forPeriod($currentTenant->id(), $periodStart, $periodEnd);

        $entries = [
            'trial-balance.csv' => CsvResponseBuilder::toCsvString(...$this->trialBalanceCsvRows($trialBalance)),
            'profit-and-loss.csv' => CsvResponseBuilder::toCsvString(...$this->profitAndLossCsvRows($profitAndLoss)),
            'balance-sheet.csv' => CsvResponseBuilder::toCsvString(...$this->balanceSheetCsvRows($balanceSheet)),
            'aging-report.csv' => CsvResponseBuilder::toCsvString(...$this->agingReportCsvRows($agingReport)),
            'evidence-index.csv' => CsvResponseBuilder::toCsvString(...$this->evidenceIndexCsvRows($evidenceIndex)),
            // AETS-009 §22: the same Cash Flow Statement §22 exposes
            // standalone — Master Context §7 item 5 names "aliran
            // tunai" explicitly among the reports a Compliance Pack
            // should carry.
            'cash-flow.csv' => CsvResponseBuilder::toCsvString(...$this->cashFlowCsvRows($cashFlow)),
            // AETS-009 §21/§19: the identical "loan-ready" statement
            // format §21 already builds for the standalone PDF
            // endpoints — a bank or accountant handed this Pack gets a
            // human-readable statement alongside the machine-readable
            // CSVs, not just the latter.
            'profit-and-loss.pdf' => DocumentPdfBuilder::renderBytes('pdf.financial-statement', $this->profitAndLossPdfData($profitAndLoss, $currentTenant)),
            'balance-sheet.pdf' => DocumentPdfBuilder::renderBytes('pdf.financial-statement', $this->balanceSheetPdfData($balanceSheet, $currentTenant)),
            'cash-flow.pdf' => DocumentPdfBuilder::renderBytes('pdf.financial-statement', $this->cashFlowPdfData($cashFlow, $currentTenant)),
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
    private function cashFlowCsvRows(CashFlowStatement $statement): array
    {
        return [
            ['Activity', 'Counterparty Account ID', 'Net Cash Flow Amount', 'Net Cash Flow Direction'],
            [
                ...array_map(fn (CashFlowLine $line): array => ['Operating', ...$this->cashFlowLineToCsvRow($line)], $statement->operatingLines()),
                ...array_map(fn (CashFlowLine $line): array => ['Investing', ...$this->cashFlowLineToCsvRow($line)], $statement->investingLines()),
                ...array_map(fn (CashFlowLine $line): array => ['Financing', ...$this->cashFlowLineToCsvRow($line)], $statement->financingLines()),
                ['Cash at Period Start', '', $statement->cashAtPeriodStart()->amount()->toDecimalString(), $statement->cashAtPeriodStart()->direction()->name ?? ''],
                ['Cash at Period End', '', $statement->cashAtPeriodEnd()->amount()->toDecimalString(), $statement->cashAtPeriodEnd()->direction()->name ?? ''],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function cashFlowLineToCsvRow(CashFlowLine $line): array
    {
        $balance = $line->netCashFlow();

        return [$line->accountId()->toString(), $balance->amount()->toDecimalString(), $balance->direction()->name ?? ''];
    }

    /**
     * A plain, uncomputed read of the Tenant's own `accounts` table
     * (AETS-009 §21) — the only HTTP-layer addition {@see profitAndLossPdf()}/
     * {@see balanceSheetPdf()} need beyond already-computed report figures,
     * since {@see AccountBalance} itself carries only an opaque
     * {@see AccountId}, never a human-readable name.
     *
     * @return array<string, string>
     */
    private function accountNamesByTenant(TenantId $tenantId): array
    {
        /** @var array<string, string> */
        return DB::connection('pgsql')->table('accounts')
            ->where('tenant_id', $tenantId->toString())
            ->pluck('account_name', 'account_id')
            ->all();
    }

    /**
     * @param  list<AccountBalance>  $lines
     * @param  array<string, string>  $accountNames
     * @return list<array{name: string, amount: string}>
     */
    private function accountBalancePdfLines(array $lines, array $accountNames): array
    {
        return array_map(static fn (AccountBalance $line): array => [
            'name' => $accountNames[$line->accountId()->toString()] ?? $line->accountId()->toString(),
            'amount' => $line->netBalance()->amount()->toDecimalString(),
        ], $lines);
    }

    private function profitAndLossPdf(ProfitAndLossStatement $statement, CurrentTenant $currentTenant): Response
    {
        return DocumentPdfBuilder::build(
            sprintf('profit-and-loss-%s-to-%s.pdf', $statement->periodStart()->format('Y-m-d'), $statement->periodEnd()->format('Y-m-d')),
            'pdf.financial-statement',
            $this->profitAndLossPdfData($statement, $currentTenant),
        );
    }

    private function balanceSheetPdf(BalanceSheet $balanceSheet, CurrentTenant $currentTenant): Response
    {
        return DocumentPdfBuilder::build(
            sprintf('balance-sheet-%s.pdf', $balanceSheet->asOfDate()->format('Y-m-d')),
            'pdf.financial-statement',
            $this->balanceSheetPdfData($balanceSheet, $currentTenant),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function profitAndLossPdfData(ProfitAndLossStatement $statement, CurrentTenant $currentTenant): array
    {
        $accountNames = $this->accountNamesByTenant($currentTenant->id());
        $businessProfile = BusinessProfile::query()->find($currentTenant->id()->toString());

        return [
            'seller' => DocumentPartyFormatter::sellerFromBusinessProfile($businessProfile),
            'statementTitle' => 'PROFIT & LOSS STATEMENT',
            'periodLabel' => sprintf('For the period %s to %s', $statement->periodStart()->format('d M Y'), $statement->periodEnd()->format('d M Y')),
            'currency' => $statement->totalRevenue()->currency()->identifier(),
            'sections' => [
                ['label' => 'Revenue', 'lines' => $this->accountBalancePdfLines($statement->revenueLines(), $accountNames), 'subtotal' => $statement->totalRevenue()->toDecimalString()],
                ['label' => 'Expenses', 'lines' => $this->accountBalancePdfLines($statement->expenseLines(), $accountNames), 'subtotal' => $statement->totalExpense()->toDecimalString()],
            ],
            'summaryLines' => [
                ['label' => $statement->isProfit() ? 'Net Income' : 'Net Loss', 'amount' => $statement->netIncome()->toDecimalString(), 'emphasized' => true],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceSheetPdfData(BalanceSheet $balanceSheet, CurrentTenant $currentTenant): array
    {
        $accountNames = $this->accountNamesByTenant($currentTenant->id());
        $businessProfile = BusinessProfile::query()->find($currentTenant->id()->toString());
        $netIncome = $balanceSheet->cumulativeNetIncome();

        $liabilityAndEquityLines = [
            ...$this->accountBalancePdfLines($balanceSheet->liabilityLines(), $accountNames),
            ...$this->accountBalancePdfLines($balanceSheet->equityLines(), $accountNames),
            ['name' => 'Cumulative Net Income', 'amount' => $netIncome->amount()->toDecimalString()],
        ];

        return [
            'seller' => DocumentPartyFormatter::sellerFromBusinessProfile($businessProfile),
            'statementTitle' => 'BALANCE SHEET',
            'periodLabel' => sprintf('As of %s', $balanceSheet->asOfDate()->format('d M Y')),
            'currency' => $balanceSheet->totalAssets()->currency()->identifier(),
            'sections' => [
                ['label' => 'Assets', 'lines' => $this->accountBalancePdfLines($balanceSheet->assetLines(), $accountNames), 'subtotal' => $balanceSheet->totalAssets()->toDecimalString()],
                ['label' => 'Liabilities & Equity', 'lines' => $liabilityAndEquityLines, 'subtotal' => $balanceSheet->totalLiabilitiesAndEquity()->toDecimalString()],
            ],
            'summaryLines' => [
                ['label' => 'Total Assets', 'amount' => $balanceSheet->totalAssets()->toDecimalString(), 'emphasized' => false],
                ['label' => 'Total Liabilities & Equity', 'amount' => $balanceSheet->totalLiabilitiesAndEquity()->toDecimalString(), 'emphasized' => true],
            ],
        ];
    }

    private function cashFlowPdf(CashFlowStatement $statement, CurrentTenant $currentTenant): Response
    {
        return DocumentPdfBuilder::build(
            sprintf('cash-flow-%s-to-%s.pdf', $statement->periodStart()->format('Y-m-d'), $statement->periodEnd()->format('Y-m-d')),
            'pdf.financial-statement',
            $this->cashFlowPdfData($statement, $currentTenant),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cashFlowPdfData(CashFlowStatement $statement, CurrentTenant $currentTenant): array
    {
        $accountNames = $this->accountNamesByTenant($currentTenant->id());
        $businessProfile = BusinessProfile::query()->find($currentTenant->id()->toString());

        return [
            'seller' => DocumentPartyFormatter::sellerFromBusinessProfile($businessProfile),
            'statementTitle' => 'CASH FLOW STATEMENT',
            'periodLabel' => sprintf('For the period %s to %s', $statement->periodStart()->format('d M Y'), $statement->periodEnd()->format('d M Y')),
            'currency' => $statement->cashAtPeriodEnd()->amount()->currency()->identifier(),
            'sections' => [
                ['label' => 'Operating Activities', 'lines' => $this->cashFlowPdfLines($statement->operatingLines(), $accountNames), 'subtotal' => $this->signedNetBalance($statement->operatingTotal())],
                ['label' => 'Investing Activities', 'lines' => $this->cashFlowPdfLines($statement->investingLines(), $accountNames), 'subtotal' => $this->signedNetBalance($statement->investingTotal())],
                ['label' => 'Financing Activities', 'lines' => $this->cashFlowPdfLines($statement->financingLines(), $accountNames), 'subtotal' => $this->signedNetBalance($statement->financingTotal())],
            ],
            'summaryLines' => [
                ['label' => 'Net Change in Cash', 'amount' => $this->signedNetBalance($statement->netChangeInCash()), 'emphasized' => false],
                ['label' => 'Cash at Period Start', 'amount' => $statement->cashAtPeriodStart()->amount()->toDecimalString(), 'emphasized' => false],
                ['label' => 'Cash at Period End', 'amount' => $statement->cashAtPeriodEnd()->amount()->toDecimalString(), 'emphasized' => true],
            ],
        ];
    }

    /**
     * @param  list<CashFlowLine>  $lines
     * @param  array<string, string>  $accountNames
     * @return list<array{name: string, amount: string}>
     */
    private function cashFlowPdfLines(array $lines, array $accountNames): array
    {
        return array_map(fn (CashFlowLine $line): array => [
            'name' => $accountNames[$line->accountId()->toString()] ?? $line->accountId()->toString(),
            'amount' => $this->signedNetBalance($line->netCashFlow()),
        ], $lines);
    }

    /**
     * A cash inflow (Debit direction, mirroring Cash's own Asset
     * Normal Balance) renders as a plain positive amount; an outflow
     * (Credit) is prefixed with "-" — unlike Profit & Loss/Balance
     * Sheet, a single Cash Flow section legitimately mixes inflows and
     * outflows (e.g. Sales Revenue in, Rent Expense out both under
     * "Operating"), so the sign must be explicit per line, not implied
     * by which section it appears in.
     */
    private function signedNetBalance(NetBalance $balance): string
    {
        $amount = $balance->amount()->toDecimalString();

        return $balance->direction()?->name === 'Credit' ? sprintf('-%s', $amount) : $amount;
    }

    /**
     * AETS-009 §21 (extended v1.9.0) — Trial Balance's own real
     * Debit/Credit columns (`AccountBalance::totalDebit()`/
     * `totalCredit()`), unlike the collapsed single net amount
     * `financial-statement.blade.php` uses for P&L/Balance Sheet.
     */
    private function trialBalancePdf(TrialBalance $trialBalance, CurrentTenant $currentTenant): Response
    {
        $accountNames = $this->accountNamesByTenant($currentTenant->id());
        $businessProfile = BusinessProfile::query()->find($currentTenant->id()->toString());

        $rows = array_map(fn (AccountBalance $line): array => [
            $accountNames[$line->accountId()->toString()] ?? $line->accountId()->toString(),
            $line->accountType()->name,
            $line->totalDebit()->toDecimalString(),
            $line->totalCredit()->toDecimalString(),
        ], $trialBalance->lines());

        return DocumentPdfBuilder::build(
            sprintf('trial-balance-%s.pdf', $trialBalance->asOfDate()->format('Y-m-d')),
            'pdf.tabular-report',
            [
                'seller' => DocumentPartyFormatter::sellerFromBusinessProfile($businessProfile),
                'reportTitle' => 'TRIAL BALANCE',
                'periodLabel' => sprintf('As of %s', $trialBalance->asOfDate()->format('d M Y')),
                'columns' => [
                    ['label' => 'Account', 'align' => 'left'],
                    ['label' => 'Type', 'align' => 'left'],
                    ['label' => 'Debit', 'align' => 'right'],
                    ['label' => 'Credit', 'align' => 'right'],
                ],
                'rows' => $rows,
                'noteLines' => [$trialBalance->isBalanced() ? 'Balanced: Yes' : 'Balanced: No'],
                'summaryLines' => [
                    ['label' => 'Total Debit', 'amount' => $trialBalance->totalDebit()->toDecimalString(), 'emphasized' => true],
                    ['label' => 'Total Credit', 'amount' => $trialBalance->totalCredit()->toDecimalString(), 'emphasized' => true],
                ],
            ],
        );
    }

    /**
     * AETS-009 §21/`RPT-020` (v1.9.0) — the one genuinely new derived
     * value across all four reports this section adds: a per-row
     * running balance. `GeneralLedgerEntry` carries none, and
     * {@see GeneralLedgerQuery} computes opening/closing balances
     * independently rather than by walking entries — so this method
     * accumulates each entry's already-computed amount+direction onto
     * the previous row's balance, starting from the activity's own
     * `openingBalance()`, in the exact chronological order
     * `entries()` already returns them. The final row MUST tie out
     * exactly to `closingBalance()` — proven by
     * `ReportingQueriesIntegrationTest` (RPT-020), never merely
     * assumed.
     */
    private function generalLedgerPdf(GeneralLedgerAccountActivity $activity, CurrentTenant $currentTenant): Response
    {
        $accountNames = $this->accountNamesByTenant($currentTenant->id());
        $businessProfile = BusinessProfile::query()->find($currentTenant->id()->toString());
        $accountName = $accountNames[$activity->accountId()->toString()] ?? $activity->accountId()->toString();

        $descriptions = $this->sourceDescriptionLookup->forSources(
            $currentTenant->id(),
            array_map(static fn (GeneralLedgerEntry $entry): SourceReference => $entry->source(), $activity->entries()),
        );

        $runningBalances = GeneralLedgerRunningBalance::forEntries($activity->openingBalance(), $activity->entries());

        $rows = [];
        foreach ($activity->entries() as $i => $entry) {
            $rows[] = [
                $entry->financialDate()->format('Y-m-d'),
                $descriptions[$entry->source()->toString()] ?? $entry->source()->toString(),
                $entry->direction()->name,
                $entry->amount()->toDecimalString(),
                $this->formattedNetBalance($runningBalances[$i]),
            ];
        }

        return DocumentPdfBuilder::build(
            sprintf('general-ledger-%s-%s-to-%s.pdf', $activity->accountId()->toString(), $activity->periodStart()->format('Y-m-d'), $activity->periodEnd()->format('Y-m-d')),
            'pdf.tabular-report',
            [
                'seller' => DocumentPartyFormatter::sellerFromBusinessProfile($businessProfile),
                'reportTitle' => sprintf('GENERAL LEDGER - %s', $accountName),
                'periodLabel' => sprintf('For the period %s to %s', $activity->periodStart()->format('d M Y'), $activity->periodEnd()->format('d M Y')),
                'columns' => [
                    ['label' => 'Date', 'align' => 'left'],
                    ['label' => 'Description', 'align' => 'left'],
                    ['label' => 'Direction', 'align' => 'left'],
                    ['label' => 'Amount', 'align' => 'right'],
                    ['label' => 'Balance', 'align' => 'right'],
                ],
                'rows' => $rows,
                'noteLines' => [sprintf('Opening balance: %s', $this->formattedNetBalance($activity->openingBalance()))],
                'summaryLines' => [
                    ['label' => 'Closing Balance', 'amount' => $this->formattedNetBalance($activity->closingBalance()), 'emphasized' => true],
                ],
            ],
        );
    }

    private function formattedNetBalance(NetBalance $balance): string
    {
        if ($balance->direction() === null) {
            return sprintf('%s', $balance->amount()->toDecimalString());
        }

        return sprintf('%s %s', $balance->amount()->toDecimalString(), $balance->direction() === JournalDirection::Debit ? 'Dr' : 'Cr');
    }

    /**
     * AETS-009 §21 (extended v1.9.0). `AgingReportLine` carries only an
     * opaque `CustomerId` — mirrors {@see accountNamesByTenant()}
     * exactly, for the same reason (a human-readable label the domain
     * layer itself deliberately does not carry).
     *
     * @return array<string, string>
     */
    private function customerNamesByTenant(TenantId $tenantId): array
    {
        /** @var array<string, string> */
        return DB::connection('pgsql')->table('customers')
            ->where('tenant_id', $tenantId->toString())
            ->pluck('name', 'id')
            ->all();
    }

    private const AGING_BUCKET_LABELS = [
        'Current' => 'Current',
        'Overdue1To30' => '1-30 days',
        'Overdue31To60' => '31-60 days',
        'Overdue61To90' => '61-90 days',
        'Overdue91Plus' => '91+ days',
    ];

    private function agingReportPdf(AgingReport $report, CurrentTenant $currentTenant): Response
    {
        $customerNames = $this->customerNamesByTenant($currentTenant->id());
        $businessProfile = BusinessProfile::query()->find($currentTenant->id()->toString());

        $rows = array_map(fn (AgingReportLine $line): array => [
            $customerNames[$line->customerId()->toString()] ?? $line->customerId()->toString(),
            $line->invoiceNumber() ?? 'Draft invoice',
            $line->dueDate()->format('Y-m-d'),
            self::AGING_BUCKET_LABELS[$line->bucket()->name],
            $line->outstandingBalance()->toDecimalString(),
        ], $report->lines());

        return DocumentPdfBuilder::build(
            sprintf('aging-report-%s.pdf', $report->asOfDate()->format('Y-m-d')),
            'pdf.tabular-report',
            [
                'seller' => DocumentPartyFormatter::sellerFromBusinessProfile($businessProfile),
                'reportTitle' => 'AGING REPORT (RECEIVABLES)',
                'periodLabel' => sprintf('As of %s', $report->asOfDate()->format('d M Y')),
                'columns' => [
                    ['label' => 'Customer', 'align' => 'left'],
                    ['label' => 'Invoice', 'align' => 'left'],
                    ['label' => 'Due Date', 'align' => 'left'],
                    ['label' => 'Age', 'align' => 'left'],
                    ['label' => 'Outstanding', 'align' => 'right'],
                ],
                'rows' => $rows,
                'summaryLines' => [
                    ['label' => 'Current', 'amount' => $report->totalForBucket(AgingBucket::Current)->toDecimalString(), 'emphasized' => false],
                    ['label' => '1-30 days', 'amount' => $report->totalForBucket(AgingBucket::Overdue1To30)->toDecimalString(), 'emphasized' => false],
                    ['label' => '31-60 days', 'amount' => $report->totalForBucket(AgingBucket::Overdue31To60)->toDecimalString(), 'emphasized' => false],
                    ['label' => '61-90 days', 'amount' => $report->totalForBucket(AgingBucket::Overdue61To90)->toDecimalString(), 'emphasized' => false],
                    ['label' => '91+ days', 'amount' => $report->totalForBucket(AgingBucket::Overdue91Plus)->toDecimalString(), 'emphasized' => false],
                    ['label' => 'Grand Total', 'amount' => $report->grandTotal()->toDecimalString(), 'emphasized' => true],
                ],
            ],
        );
    }

    private function evidenceIndexPdf(EvidenceIndex $index, CurrentTenant $currentTenant): Response
    {
        $businessProfile = BusinessProfile::query()->find($currentTenant->id()->toString());
        $descriptions = $this->sourceDescriptionLookup->forSources(
            $currentTenant->id(),
            array_map(static fn (EvidenceIndexEntry $entry): SourceReference => $entry->source(), $index->entries()),
        );

        $rows = array_map(function (EvidenceIndexEntry $entry) use ($descriptions): array {
            $source = $entry->source()->toString();
            $sourceType = explode(':', $source, 2)[0];

            return [
                $entry->financialDate()->format('Y-m-d'),
                $descriptions[$source] ?? $source,
                $sourceType,
                $entry->hasEvidence() ? 'Yes' : 'No',
            ];
        }, $index->entries());

        $withEvidence = count(array_filter($index->entries(), static fn (EvidenceIndexEntry $entry): bool => $entry->hasEvidence()));

        return DocumentPdfBuilder::build(
            sprintf('evidence-index-%s-to-%s.pdf', $index->periodStart()->format('Y-m-d'), $index->periodEnd()->format('Y-m-d')),
            'pdf.tabular-report',
            [
                'seller' => DocumentPartyFormatter::sellerFromBusinessProfile($businessProfile),
                'reportTitle' => 'EVIDENCE INDEX',
                'periodLabel' => sprintf('For the period %s to %s', $index->periodStart()->format('d M Y'), $index->periodEnd()->format('d M Y')),
                'columns' => [
                    ['label' => 'Date', 'align' => 'left'],
                    ['label' => 'Description', 'align' => 'left'],
                    ['label' => 'Source Type', 'align' => 'left'],
                    ['label' => 'Evidence', 'align' => 'left'],
                ],
                'rows' => $rows,
                'noteLines' => [sprintf('%d of %d entries have evidence attached', $withEvidence, count($index->entries()))],
            ],
        );
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
    private function cashFlowToArray(CashFlowStatement $statement): array
    {
        return [
            'period_start' => $statement->periodStart()->format('Y-m-d'),
            'period_end' => $statement->periodEnd()->format('Y-m-d'),
            'operating_lines' => array_map($this->cashFlowLineToArray(...), $statement->operatingLines()),
            'operating_total' => $this->netBalanceToArray($statement->operatingTotal()),
            'investing_lines' => array_map($this->cashFlowLineToArray(...), $statement->investingLines()),
            'investing_total' => $this->netBalanceToArray($statement->investingTotal()),
            'financing_lines' => array_map($this->cashFlowLineToArray(...), $statement->financingLines()),
            'financing_total' => $this->netBalanceToArray($statement->financingTotal()),
            'net_change_in_cash' => $this->netBalanceToArray($statement->netChangeInCash()),
            'cash_at_period_start' => $this->netBalanceToArray($statement->cashAtPeriodStart()),
            'cash_at_period_end' => $this->netBalanceToArray($statement->cashAtPeriodEnd()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cashFlowLineToArray(CashFlowLine $line): array
    {
        return [
            'account_id' => $line->accountId()->toString(),
            'net_cash_flow' => $this->netBalanceToArray($line->netCashFlow()),
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
    private function evidenceIndexToArray(EvidenceIndex $index, CurrentTenant $currentTenant): array
    {
        $descriptions = $this->sourceDescriptionLookup->forSources(
            $currentTenant->id(),
            array_map(static fn (EvidenceIndexEntry $entry): SourceReference => $entry->source(), $index->entries()),
        );

        return [
            'period_start' => $index->periodStart()->format('Y-m-d'),
            'period_end' => $index->periodEnd()->format('Y-m-d'),
            'entries' => array_map(fn (EvidenceIndexEntry $entry): array => [
                'journal_id' => $entry->journalId()->toString(),
                'financial_date' => $entry->financialDate()->format('Y-m-d'),
                'source' => $entry->source()->toString(),
                'has_evidence' => $entry->hasEvidence(),
                'evidence_references' => $entry->evidenceReferences(),
                'description' => $descriptions[$entry->source()->toString()] ?? null,
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
