<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Invoicing\Reporting;

use App\Domain\Invoicing\Reporting\AgingBucket;
use PHPUnit\Framework\TestCase;

final class AgingBucketTest extends TestCase
{
    public function test_not_yet_due_or_due_today_is_current(): void
    {
        $this->assertSame(AgingBucket::Current, AgingBucket::forDaysOverdue(-5));
        $this->assertSame(AgingBucket::Current, AgingBucket::forDaysOverdue(0));
    }

    public function test_boundaries_for_each_bucket(): void
    {
        $this->assertSame(AgingBucket::Overdue1To30, AgingBucket::forDaysOverdue(1));
        $this->assertSame(AgingBucket::Overdue1To30, AgingBucket::forDaysOverdue(30));
        $this->assertSame(AgingBucket::Overdue31To60, AgingBucket::forDaysOverdue(31));
        $this->assertSame(AgingBucket::Overdue31To60, AgingBucket::forDaysOverdue(60));
        $this->assertSame(AgingBucket::Overdue61To90, AgingBucket::forDaysOverdue(61));
        $this->assertSame(AgingBucket::Overdue61To90, AgingBucket::forDaysOverdue(90));
        $this->assertSame(AgingBucket::Overdue91Plus, AgingBucket::forDaysOverdue(91));
        $this->assertSame(AgingBucket::Overdue91Plus, AgingBucket::forDaysOverdue(365));
    }
}
