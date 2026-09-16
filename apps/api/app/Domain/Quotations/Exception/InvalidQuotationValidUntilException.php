<?php

declare(strict_types=1);

namespace App\Domain\Quotations\Exception;

use App\Domain\Invoicing\Exception\InvalidInvoiceDueDateException;

/**
 * Thrown when a Quotation's validity date would fall before its issue
 * date (AETS-016 §6, QUO-004) — checked at Send time, mirroring
 * {@see InvalidInvoiceDueDateException}'s
 * own reasoning exactly.
 */
final class InvalidQuotationValidUntilException extends \InvalidArgumentException
{
    public static function forValidUntilBeforeIssueDate(): self
    {
        return new self('A Quotation validity date must not be before its issue date.');
    }
}
