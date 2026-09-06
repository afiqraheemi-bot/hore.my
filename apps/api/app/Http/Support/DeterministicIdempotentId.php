<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Shared\Tenancy\TenantId;
use Ramsey\Uuid\Uuid;

/**
 * Derives a stable, opaque identifier from `(TenantId, IdempotencyKey,
 * discriminator)` — never a freshly random one — so that every HTTP
 * retry of the same logical request (same Idempotency-Key, same
 * business content) produces the exact same identifier every time.
 *
 * **Why this exists.** `PostingCommandLogicalEquivalence` deliberately
 * treats a Journal's own proposed identity as part of the logical
 * payload two retries of the same command must agree on (AETS-007
 * §6.1) — see that class's own docblock. A naive HTTP controller that
 * mints a fresh random UUID for `ExpenseId`/`IncomeId`/`JournalId` on
 * every request would therefore make every genuine retry look like a
 * *different* logical request, and Accounting Core would correctly —
 * by its own design — reject it as a conflicting reuse rather than
 * replay it. This class is what makes an HTTP client's retry-with-the-
 * same-Idempotency-Key actually safe: deriving the identifier from the
 * one piece of the request that is guaranteed stable across a retry.
 *
 * **Why a discriminator string.** `TenantId` + `IdempotencyKey` alone
 * would produce the same identifier for both the Expense/Income row
 * and its Journal, and would collide between an Expense and an Income
 * sharing a `TenantId`/`IdempotencyKey` pair (which is itself a
 * genuinely different logical request Accounting Core must correctly
 * reject as conflicting reuse, not accidentally alias). Each caller
 * passes its own fixed discriminator (e.g. `'expense'`, `'journal'`)
 * so every identifier space stays independent.
 *
 * **Deterministic, not secret.** UUID v5 (name-based, SHA-1) is used
 * deliberately over UUID v4 (random) — this is namespaced hashing, not
 * a security boundary; the Idempotency Key itself is already the
 * caller-controlled, opaque input this derives from.
 */
final class DeterministicIdempotentId
{
    /**
     * A fixed namespace UUID private to hore.my, generated once and
     * never changed — changing it would silently re-derive every
     * future retry's identifiers differently from identifiers already
     * persisted for past requests, breaking idempotency for any
     * request retried across the change.
     */
    private const NAMESPACE = 'c9d1a139-b1fe-4ac2-8517-6b41c6324fe7';

    public static function derive(TenantId $tenantId, IdempotencyKey $idempotencyKey, string $discriminator): string
    {
        return Uuid::uuid5(
            self::NAMESPACE,
            $tenantId->toString().':'.$idempotencyKey->toString().':'.$discriminator,
        )->toString();
    }
}
