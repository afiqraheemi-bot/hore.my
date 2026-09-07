<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Reporting;

/**
 * An aging bucket for the Debtors/Aging report (M22) — how overdue an
 * Invoice's own outstanding balance is, as of the report's `asOfDate`,
 * measured against its `dueDate`.
 */
enum AgingBucket
{
    case Current;
    case Overdue1To30;
    case Overdue31To60;
    case Overdue61To90;
    case Overdue91Plus;

    public static function forDaysOverdue(int $daysOverdue): self
    {
        return match (true) {
            $daysOverdue <= 0 => self::Current,
            $daysOverdue <= 30 => self::Overdue1To30,
            $daysOverdue <= 60 => self::Overdue31To60,
            $daysOverdue <= 90 => self::Overdue61To90,
            default => self::Overdue91Plus,
        };
    }
}
