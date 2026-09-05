<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Posting\Exception;

use App\Domain\Accounting\Posting\SourceFingerprint;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * Thrown when `PostingSourceFingerprintRepository::record()` attempts
 * to insert a (Tenant, Source Fingerprint) mapping that already
 * exists — surfaced from the real `PRIMARY KEY (tenant_id,
 * source_fingerprint)` PostgreSQL constraint on
 * `posting_source_fingerprints`, never from an application-level
 * pre-check the repository invents on its own.
 *
 * A Source Fingerprint identifies duplicate *source data*, never a
 * duplicate *command* (AETS-007 §6.2) — this exception is therefore
 * never a substitute for, and never conflated with,
 * `DuplicatePostingIdempotencyKeyException`. The two mechanisms
 * operate together, on entirely separate database constraints; a
 * caller may legitimately see one without the other.
 */
final class DuplicateSourceFingerprintException extends \RuntimeException
{
    public static function forFingerprint(TenantId $tenantId, SourceFingerprint $sourceFingerprint): self
    {
        return new self(sprintf(
            'A Posting source fingerprint mapping already exists for Tenant "%s" and Source Fingerprint "%s".',
            $tenantId->toString(),
            $sourceFingerprint->toString(),
        ));
    }
}
