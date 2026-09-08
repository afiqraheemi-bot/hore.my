<?php

declare(strict_types=1);

namespace App\Infrastructure\Invoicing\Reporting;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\Reporting\AgingBucket;
use App\Domain\Invoicing\Reporting\AgingReport;
use App\Domain\Invoicing\Reporting\AgingReportLine;
use App\Domain\Shared\Tenancy\TenantId;
use Illuminate\Database\ConnectionInterface;

/**
 * Computes an {@see AgingReport} as of a given date (M22) — only
 * Issued Invoices issued on or before `asOfDate` are considered, and
 * only allocations from Payments made on or before `asOfDate` reduce
 * an Invoice's own outstanding balance (a Payment made *after*
 * `asOfDate` cannot retroactively have settled a debt as of that
 * date).
 *
 * **Point-in-time correct across a later deallocation (P1-4, resolved
 * 2026-09-11).** An allocation counts toward a historical `asOfDate`
 * if it had not yet been deallocated as of that date — `deleted_at IS
 * NULL` (never deallocated) or `deleted_at`'s own calendar date is
 * *after* `asOfDate` (deallocated only later than the date being
 * reported on). Re-running this report for the same historical
 * `asOfDate` therefore produces the identical result before and after
 * a later deallocation — the gap AETS-009 §17 previously named as a
 * currently-open limitation. `deleted_at` (a system timestamp, not a
 * user-supplied Financial Date) is used here only to answer "had this
 * fact taken effect yet, as of this date" — it never substitutes for
 * a Financial Date on a financial event, consistent with AETS-009 §5
 * rule 5; deallocation, unlike a Payment or an Invoice, has no
 * business-dated field of its own to prefer instead.
 */
final class AgingReportQuery
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function asOf(TenantId $tenantId, \DateTimeImmutable $asOfDate): AgingReport
    {
        $currency = Currency::of('MYR');
        $asOfDateString = $asOfDate->format('Y-m-d');

        /** @var list<object{id: string, invoice_number: string|null, customer_id: string, due_date: string, total_amount: int|string}> $invoiceRows */
        $invoiceRows = $this->connection->table('invoices')
            ->where('tenant_id', $tenantId->toString())
            ->where('status', 'Issued')
            ->where('issue_date', '<=', $asOfDateString)
            ->get(['id', 'invoice_number', 'customer_id', 'due_date', 'total_amount'])
            ->all();

        if ($invoiceRows === []) {
            return new AgingReport($tenantId, $asOfDate, $currency, []);
        }

        $invoiceIds = array_map(static fn (object $row): string => $row->id, $invoiceRows);

        /** @var array<string, int|string> $allocatedByInvoiceId */
        $allocatedByInvoiceId = $this->connection->table('payment_allocations')
            ->join('payments', function ($join): void {
                $join->on('payment_allocations.tenant_id', '=', 'payments.tenant_id')
                    ->on('payment_allocations.payment_id', '=', 'payments.id');
            })
            ->where('payment_allocations.tenant_id', $tenantId->toString())
            ->whereIn('payment_allocations.invoice_id', $invoiceIds)
            ->where('payments.payment_date', '<=', $asOfDateString)
            // P1-4: an allocation counts if it had not yet been
            // deallocated as of $asOfDate — either never deallocated, or
            // deallocated only after this historical date. See this
            // class's own docblock.
            ->where(function ($query) use ($asOfDateString): void {
                $query->whereNull('payment_allocations.deleted_at')
                    ->orWhereDate('payment_allocations.deleted_at', '>', $asOfDateString);
            })
            ->selectRaw('payment_allocations.invoice_id as invoice_id, sum(payment_allocations.amount) as allocated')
            ->groupBy('payment_allocations.invoice_id')
            ->pluck('allocated', 'invoice_id')
            ->all();

        $lines = [];
        foreach ($invoiceRows as $row) {
            $totalAmount = Money::fromMinorUnits(MinorUnits::of((string) $row->total_amount), $currency);
            $allocated = isset($allocatedByInvoiceId[$row->id])
                ? Money::fromMinorUnits(MinorUnits::of((string) $allocatedByInvoiceId[$row->id]), $currency)
                : Money::fromMinorUnits(MinorUnits::of('0'), $currency);

            $outstanding = $totalAmount->subtract($allocated);

            if ($outstanding->toMinorUnits()->toString() === '0') {
                continue;
            }

            $dueDate = new \DateTimeImmutable($row->due_date);
            $daysOverdue = (int) $asOfDate->diff($dueDate)->format('%r%a') * -1;

            $lines[] = new AgingReportLine(
                InvoiceId::of($row->id),
                $row->invoice_number,
                CustomerId::of($row->customer_id),
                $dueDate,
                $outstanding,
                AgingBucket::forDaysOverdue($daysOverdue),
            );
        }

        return new AgingReport($tenantId, $asOfDate, $currency, $lines);
    }
}
