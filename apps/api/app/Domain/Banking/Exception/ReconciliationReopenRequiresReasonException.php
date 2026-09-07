<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

/**
 * Thrown when reopening a Completed Reconciliation with an empty reason
 * (M18, SRS BNK-007: "Rekonsiliasi selesai memerlukan tindakan reopen
 * dan audit event sebelum perubahan" — a completed Reconciliation
 * requires a reopen action and an audit event before any change; a
 * reason is the minimum content that audit record must carry).
 */
final class ReconciliationReopenRequiresReasonException extends \InvalidArgumentException
{
    public static function forEmptyReason(): self
    {
        return new self('Reopening a completed Reconciliation requires a non-empty reason.');
    }
}
