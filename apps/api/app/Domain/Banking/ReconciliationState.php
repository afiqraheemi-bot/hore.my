<?php

declare(strict_types=1);

namespace App\Domain\Banking;

/**
 * The lifecycle of a Reconciliation (M18, SRS §10.4): "Rekonsiliasi:
 * Draft -> In Review -> Balanced -> Completed. Reopen memerlukan sebab
 * dan audit event." Exactly these four states — reopening a Completed
 * Reconciliation returns it to Draft (see
 * {@see ReconciliationService::reopen()}), never a fifth state of its
 * own.
 */
enum ReconciliationState
{
    case Draft;
    case InReview;
    case Balanced;
    case Completed;
}
