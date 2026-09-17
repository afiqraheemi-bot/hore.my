<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Invoicing\Reporting\AgingBucket;
use App\Domain\Invoicing\Reporting\AgingReportLine;
use App\Domain\Workspace\Task;
use App\Domain\Workspace\TaskState;
use App\Http\Controllers\Controller;
use App\Http\Support\CurrentTenant;
use App\Infrastructure\Accounting\Reporting\BalanceSheetQuery;
use App\Infrastructure\Accounting\Reporting\ProfitAndLossQuery;
use App\Infrastructure\Invoicing\Reporting\AgingReportQuery;
use App\Infrastructure\Workspace\TaskRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * A read-only composition of authoritative reporting and workflow
 * queries for the Dashboard. It deliberately performs no independent
 * accounting arithmetic: every monetary figure comes directly from
 * the same Profit & Loss, Balance Sheet, and Aging queries used by the
 * formal Reports surface.
 */
final class DashboardController extends Controller
{
    private const FINANCIAL_TIMEZONE = 'Asia/Kuala_Lumpur';

    public function __construct(
        private readonly ProfitAndLossQuery $profitAndLossQuery,
        private readonly BalanceSheetQuery $balanceSheetQuery,
        private readonly AgingReportQuery $agingReportQuery,
        private readonly TaskRepository $taskRepository,
    ) {}

    public function show(CurrentTenant $currentTenant): JsonResponse
    {
        $generatedAt = now(self::FINANCIAL_TIMEZONE);
        $asOf = $generatedAt->copy()->startOfDay()->toDateTimeImmutable();

        return DB::connection('pgsql')->transaction(function () use ($currentTenant, $generatedAt, $asOf): JsonResponse {
            DB::connection('pgsql')->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            $periods = $this->monthlyPeriods($asOf);
            $trend = [];
            $currentPeriod = null;

            foreach ($periods as $index => [$periodStart, $periodEnd]) {
                $statement = $this->profitAndLossQuery->forPeriod(
                    $currentTenant->id(),
                    $periodStart,
                    $periodEnd,
                );

                $trendEntry = [
                    'period_start' => $periodStart->format('Y-m-d'),
                    'period_end' => $periodEnd->format('Y-m-d'),
                    'total_revenue' => $statement->totalRevenue()->toDecimalString(),
                    'total_expense' => $statement->totalExpense()->toDecimalString(),
                    'net_income' => $statement->netIncome()->toDecimalString(),
                    'is_profit' => $statement->isProfit(),
                ];

                $trend[] = $trendEntry;

                if ($index === count($periods) - 1) {
                    $currentPeriod = $trendEntry;
                }
            }
            $balanceSheet = $this->balanceSheetQuery->asOf($currentTenant->id(), $asOf);
            $aging = $this->agingReportQuery->asOf($currentTenant->id(), $asOf);
            $tasks = $this->taskRepository->findByTenant($currentTenant->id());

            $overdueBuckets = [
                AgingBucket::Overdue1To30,
                AgingBucket::Overdue31To60,
                AgingBucket::Overdue61To90,
                AgingBucket::Overdue91Plus,
            ];
            $overdueInvoiceCount = count(array_filter(
                $aging->lines(),
                static fn (AgingReportLine $line): bool => in_array($line->bucket(), $overdueBuckets, true),
            ));
            $overdueTotal = $aging->totalForBucket(AgingBucket::Overdue1To30)
                ->add($aging->totalForBucket(AgingBucket::Overdue31To60))
                ->add($aging->totalForBucket(AgingBucket::Overdue61To90))
                ->add($aging->totalForBucket(AgingBucket::Overdue91Plus));
            $attentionTaskCount = count(array_filter(
                $tasks,
                static fn (Task $task): bool => in_array(
                    $task->state(),
                    [TaskState::NeedsInformation, TaskState::NeedsReview, TaskState::Failed],
                    true,
                ),
            ));

            return response()->json([
                'generated_at' => $generatedAt->toAtomString(),
                'as_of' => $asOf->format('Y-m-d'),
                'currency' => 'MYR',
                'current_period' => $currentPeriod,
                'financial_position' => [
                    'total_assets' => $balanceSheet->totalAssets()->toDecimalString(),
                ],
                'attention' => [
                    'task_count' => $attentionTaskCount,
                    'overdue_invoice_count' => $overdueInvoiceCount,
                    'overdue_invoice_total' => $overdueTotal->toDecimalString(),
                ],
                'trend' => $trend,
            ]);
        });
    }

    /**
     * Six exact calendar-month ranges ending at `$asOf`. Date strings
     * are never round-tripped through UTC, so Malaysia's +08:00 offset
     * cannot move a financial boundary into the previous day.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function monthlyPeriods(\DateTimeImmutable $asOf): array
    {
        $currentMonthStart = $asOf->modify('first day of this month');
        $periods = [];

        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $periodStart = $currentMonthStart->modify(sprintf('-%d months', $monthsAgo));
            $periodEnd = $monthsAgo === 0 ? $asOf : $periodStart->modify('last day of this month');
            $periods[] = [$periodStart, $periodEnd];
        }

        return $periods;
    }
}
