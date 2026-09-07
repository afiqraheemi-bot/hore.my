<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

use App\Domain\Banking\ReconciliationId;

/**
 * Thrown when a Reconciliation identifier does not resolve to an
 * existing record for the given Tenant (M18).
 */
final class ReconciliationNotFoundException extends \RuntimeException
{
    public static function forId(ReconciliationId $id): self
    {
        return new self(sprintf('Reconciliation "%s" was not found.', $id->toString()));
    }
}
