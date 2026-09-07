<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exception;

/**
 * Thrown when an Invoice's due date would fall before its issue date
 * (M20) — checked at Issue time, since the due date is chosen while
 * still Draft, before an issue date exists to compare it against.
 */
final class InvalidInvoiceDueDateException extends \InvalidArgumentException
{
    public static function forDueDateBeforeIssueDate(): self
    {
        return new self('An Invoice due date must not be before its issue date.');
    }
}
