<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Exception;

use App\Domain\Evidence\EvidenceId;

/**
 * Thrown when no Evidence record exists for a given Tenant and
 * {@see EvidenceId} — including when a record exists but belongs to a
 * different Tenant (AETS-015 §6, `EVI-004`): tenant mismatch fails
 * closed as not found, never as a distinguishable "exists but
 * forbidden" response.
 */
final class EvidenceNotFoundException extends \RuntimeException
{
    public static function forId(EvidenceId $id): self
    {
        return new self(sprintf('No Evidence record "%s" exists for this Tenant.', $id->toString()));
    }
}
