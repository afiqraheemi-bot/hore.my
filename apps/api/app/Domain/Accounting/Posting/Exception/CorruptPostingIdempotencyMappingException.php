<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Exception;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * Thrown when a settled Posting idempotency mapping exists for a
 * (TenantId, Idempotency Key) pair, but the Journal it names cannot be
 * loaded for that same Tenant.
 *
 * **This is not "first submission" wearing a different name.** The
 * production `posting_idempotency_keys` table's own composite foreign
 * key (M4-T15) already makes this an impossible state under normal
 * operation — a mapping row cannot reference a nonexistent Journal or
 * one belonging to a different Tenant. If this exception is ever
 * observed, something has bypassed that constraint or corrupted data
 * outside of it; silently treating it as a fresh, unused key would
 * hide that fact and risk creating a second Journal for an Idempotency
 * Key that already has one. This fails loudly instead.
 */
final class CorruptPostingIdempotencyMappingException extends \RuntimeException
{
    public static function forUnresolvableJournal(TenantId $tenantId, IdempotencyKey $idempotencyKey, JournalId $journalId): self
    {
        return new self(sprintf(
            'Posting idempotency mapping for Tenant "%s" and Idempotency Key "%s" references Journal "%s", '
            .'which could not be loaded for that Tenant.',
            $tenantId->toString(),
            $idempotencyKey->toString(),
            $journalId->toString(),
        ));
    }
}
