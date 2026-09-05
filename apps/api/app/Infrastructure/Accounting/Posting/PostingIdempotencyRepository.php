<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Posting;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Posting\Exception\DuplicatePostingIdempotencyKeyException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * The persistence boundary for the settled Posting idempotency
 * association (M4-T15, AETS-007 §6.1, §15): `(TenantId,
 * IdempotencyKey) -> JournalId`, through the production
 * `posting_idempotency_keys` table.
 *
 * **What this class is, precisely.** A lookup and a single-row
 * recorder — nothing more. It does not decide whether a caller's
 * `PostingCommand` is a safe replay or a conflicting reuse (that is
 * `PostingCommandLogicalEquivalence`'s job, given the Journal
 * {@see find()} resolves), does not orchestrate Posting, and does not
 * own the transaction a caller records a mapping within — see
 * {@see record()}'s own docblock.
 *
 * **`record()` never updates or upserts.** The mapping is immutable by
 * design (M4-T15's migration docblock): once a (Tenant, Idempotency
 * Key) pair is recorded, its `journal_id` can never be silently
 * replaced, whether the second attempt names the same Journal again or
 * a different one. Both cases are the identical database constraint
 * violation and are surfaced identically, as
 * {@see DuplicatePostingIdempotencyKeyException} — this repository
 * does not itself distinguish "harmless retry" from "genuine conflict"
 * (see that exception's own docblock for why).
 *
 * **Foreign key violations propagate unmodified.** A `journal_id` that
 * does not exist, or that belongs to a different Tenant than the one
 * `record()` was given, is rejected by the real composite foreign key
 * `(tenant_id, journal_id) -> journals (tenant_id, journal_id)` — this
 * repository translates none of that into a fake success or a
 * swallowed failure; the raw {@see QueryException} propagates, exactly
 * as `AccountRepository::save()` already leaves every non-duplicate
 * persistence failure unmodified.
 */
final class PostingIdempotencyRepository
{
    private const TABLE = 'posting_idempotency_keys';

    private const PRIMARY_KEY_CONSTRAINT = 'posting_idempotency_keys_pkey';

    private const UNIQUE_VIOLATION_SQLSTATE = '23505';

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * Resolve the JournalId already recorded for a (Tenant, Idempotency
     * Key) pair, or `null` if no mapping exists yet. Always
     * tenant-scoped: a mapping recorded under a different Tenant, even
     * one sharing the same literal Idempotency Key, is never returned.
     */
    public function find(TenantId $tenantId, IdempotencyKey $idempotencyKey): ?JournalId
    {
        /** @var object{journal_id: string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('idempotency_key', $idempotencyKey->toString())
            ->first(['journal_id']);

        return $row === null ? null : JournalId::of($row->journal_id);
    }

    /**
     * Record the settled association for a (Tenant, Idempotency Key)
     * pair: a single `INSERT`, never an update or upsert.
     *
     * **Transaction participation.** This method opens no transaction
     * of its own — the `INSERT` runs directly on `$this->connection`'s
     * current state, so when a caller has already started a
     * transaction on that same connection (e.g. the outer Posting
     * transaction that also persists the Journal itself, per AETS-007
     * §15's "same atomic transaction that posts the Journal"), this
     * insert becomes part of it: it is rolled back if that outer
     * transaction rolls back, and only durable once it commits. This
     * method does not itself own or wrap that outer boundary.
     *
     * @throws DuplicatePostingIdempotencyKeyException if a mapping
     *                                                 already exists for `$tenantId`/`$idempotencyKey` — detected
     *                                                 via the real database primary key constraint, not an
     *                                                 application-level pre-check.
     */
    public function record(TenantId $tenantId, IdempotencyKey $idempotencyKey, JournalId $journalId): void
    {
        try {
            $this->connection->table(self::TABLE)->insert([
                'tenant_id' => $tenantId->toString(),
                'idempotency_key' => $idempotencyKey->toString(),
                'journal_id' => $journalId->toString(),
            ]);
        } catch (QueryException $e) {
            if ($this->isPrimaryKeyViolation($e)) {
                throw DuplicatePostingIdempotencyKeyException::forKey($tenantId, $idempotencyKey);
            }

            throw $e;
        }
    }

    private function isPrimaryKeyViolation(QueryException $e): bool
    {
        return $e->getCode() === self::UNIQUE_VIOLATION_SQLSTATE
            && str_contains($e->getMessage(), self::PRIMARY_KEY_CONSTRAINT);
    }
}
