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
use App\Http\Controllers\Controller;
use App\Http\Requests\Reporting\AsOfDateRequest;
use App\Http\Requests\Reporting\GeneralLedgerRequest;
use App\Http\Requests\Reporting\PeriodRequest;
use App\Http\Support\CurrentTenant;
use App\Infrastructure\Accounting\Reporting\BalanceSheetQuery;
use App\Infrastructure\Accounting\Reporting\EvidenceIndexQuery;
use App\Infrastructure\Accounting\Reporting\GeneralLedgerQuery;
use App\Infrastructure\Accounting\Reporting\ProfitAndLossQuery;
use App\Infrastructure\Accounting\Reporting\TrialBalanceQuery;
use Illuminate\Http\JsonResponse;
use Tests\Unit\Domain\Accounting\Reporting\ReportingHasNoWriteEffectTest;

/**
 * Wraps M10's five report Query classes over HTTP — read-only, no new
 * business logic (AETS-009 §5 rule 6, proven by
 * {@see ReportingHasNoWriteEffectTest}
 * at the Query layer itself; this controller adds nothing that layer
 * does not already guarantee).
 */
final class ReportingController extends Controller
{
    public function __construct(
        private readonly TrialBalanceQuery $trialBalanceQuery,
        private readonly ProfitAndLossQuery $profitAndLossQuery,
        private readonly BalanceSheetQuery $balanceSheetQuery,
        private readonly GeneralLedgerQuery $generalLedgerQuery,
        private readonly EvidenceIndexQuery $evidenceIndexQuery,
    ) {}

    public function trialBalance(AsOfDateRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $trialBalance = $this->trialBalanceQuery->asOf($currentTenant->id(), new \DateTimeImmutable($request->string('as_of')->toString()));

        return response()->json($this->trialBalanceToArray($trialBalance));
    }

    public function profitAndLoss(PeriodRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $statement = $this->profitAndLossQuery->forPeriod(
            $currentTenant->id(),
            new \DateTimeImmutable($request->string('period_start')->toString()),
            new \DateTimeImmutable($request->string('period_end')->toString()),
        );

        return response()->json($this->profitAndLossToArray($statement));
    }

    public function balanceSheet(AsOfDateRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $balanceSheet = $this->balanceSheetQuery->asOf($currentTenant->id(), new \DateTimeImmutable($request->string('as_of')->toString()));

        return response()->json($this->balanceSheetToArray($balanceSheet));
    }

    public function generalLedger(GeneralLedgerRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $activity = $this->generalLedgerQuery->forAccountAndPeriod(
            $currentTenant->id(),
            AccountId::of($request->string('account_id')->toString()),
            new \DateTimeImmutable($request->string('period_start')->toString()),
            new \DateTimeImmutable($request->string('period_end')->toString()),
        );

        return response()->json($this->generalLedgerToArray($activity));
    }

    public function evidenceIndex(PeriodRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $index = $this->evidenceIndexQuery->forPeriod(
            $currentTenant->id(),
            new \DateTimeImmutable($request->string('period_start')->toString()),
            new \DateTimeImmutable($request->string('period_end')->toString()),
        );

        return response()->json($this->evidenceIndexToArray($index));
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
}
