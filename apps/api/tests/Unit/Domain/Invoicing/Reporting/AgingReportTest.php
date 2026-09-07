<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Invoicing\Reporting;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\Reporting\AgingBucket;
use App\Domain\Invoicing\Reporting\AgingReport;
use App\Domain\Invoicing\Reporting\AgingReportLine;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;

final class AgingReportTest extends TestCase
{
    public function test_grand_total_sums_every_line(): void
    {
        $currency = Currency::of('MYR');
        $report = new AgingReport(
            TenantId::of('tenant-0001'),
            new \DateTimeImmutable('2026-09-08'),
            $currency,
            [
                $this->line('100.00', AgingBucket::Current, $currency),
                $this->line('50.00', AgingBucket::Overdue1To30, $currency),
            ],
        );

        $this->assertSame('150.00', $report->grandTotal()->toDecimalString());
    }

    public function test_total_for_bucket_only_sums_matching_lines(): void
    {
        $currency = Currency::of('MYR');
        $report = new AgingReport(
            TenantId::of('tenant-0001'),
            new \DateTimeImmutable('2026-09-08'),
            $currency,
            [
                $this->line('100.00', AgingBucket::Current, $currency),
                $this->line('50.00', AgingBucket::Overdue1To30, $currency),
                $this->line('25.00', AgingBucket::Overdue1To30, $currency),
            ],
        );

        $this->assertSame('75.00', $report->totalForBucket(AgingBucket::Overdue1To30)->toDecimalString());
        $this->assertSame('100.00', $report->totalForBucket(AgingBucket::Current)->toDecimalString());
        $this->assertSame('0.00', $report->totalForBucket(AgingBucket::Overdue91Plus)->toDecimalString());
    }

    public function test_empty_report_has_zero_totals(): void
    {
        $report = new AgingReport(TenantId::of('tenant-0001'), new \DateTimeImmutable('2026-09-08'), Currency::of('MYR'), []);

        $this->assertSame('0.00', $report->grandTotal()->toDecimalString());
        $this->assertSame([], $report->lines());
    }

    private function line(string $amount, AgingBucket $bucket, Currency $currency): AgingReportLine
    {
        return new AgingReportLine(
            InvoiceId::of('invoice-0001'),
            'INV-000001',
            CustomerId::of('customer-0001'),
            new \DateTimeImmutable('2026-08-01'),
            Money::fromDecimalString($amount, $currency),
            $bucket,
        );
    }
}
