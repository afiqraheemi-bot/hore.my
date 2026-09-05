<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Posting;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\SourceFingerprint;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Posting\Exception\DuplicateSourceFingerprintException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * The persistence boundary for the settled Source Fingerprint
 * duplicate-source association (AETS-007 §6.2): `(TenantId,
 * SourceFingerprint) -> JournalId`, through the production
 * `posting_source_fingerprints` table — mirroring
 * `PostingIdempotencyRepository` exactly, for the entirely separate
 * guarantee AETS-007 §6.2 requires.
 *
 * **What this class is, precisely.** A lookup and a single-row
 * recorder — nothing more. It does not decide *whether* a given
 * `PostingCommand` requires a Source Fingerprint at all (§6.2/§26
 * defer that to a future calling module), does not orchestrate
 * Posting, and does not own the transaction a caller records a
 * mapping within — see {@see record()}'s own docblock.
 *
 * **`record()` never updates or upserts.** The mapping is immutable by
 * design, identical in spirit to `posting_idempotency_keys`: once a
 * (Tenant, Source Fingerprint) pair is recorded, its `journal_id` can
 * never be silently replaced. Both a harmless retry and a genuine
 * duplicate-source attempt are the identical database constraint
 * violation and are surfaced identically, as
 * {@see DuplicateSourceFingerprintException}.
 *
 * **Foreign key violations propagate unmodified.** A `journal_id` that
 * does not exist, or that belongs to a different Tenant than the one
 * `record()` was given, is rejected by the real composite foreign key
 * `(tenant_id, journal_id) -> journals (tenant_id, journal_id)` — this
 * repository translates none of that into a fake success or a
 * swallowed failure; the raw {@see QueryException} propagates.
 */
final class PostingSourceFingerprintRepository
{
    private const TABLE = 'posting_source_fingerprints';

    private const PRIMARY_KEY_CONSTRAINT = 'posting_source_fingerprints_pkey';

    private const UNIQUE_VIOLATION_SQLSTATE = '23505';

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * Resolve the JournalId already recorded for a (Tenant, Source
     * Fingerprint) pair, or `null` if no mapping exists yet. Always
     * tenant-scoped: a mapping recorded under a different Tenant, even
     * one sharing the same literal Source Fingerprint, is never
     * returned.
     */
    public function find(TenantId $tenantId, SourceFingerprint $sourceFingerprint): ?JournalId
    {
        /** @var object{journal_id: string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('source_fingerprint', $sourceFingerprint->toString())
            ->first(['journal_id']);

        return $row === null ? null : JournalId::of($row->journal_id);
    }

    /**
     * Record the settled association for a (Tenant, Source
     * Fingerprint) pair: a single `INSERT`, never an update or upsert.
     *
     * **Transaction participation.** This method opens no transaction
     * of its own — the `INSERT` runs directly on `$this->connection`'s
     * current state, so when a caller has already started a
     * transaction on that same connection, this insert becomes part of
     * it: rolled back if that outer transaction rolls back, and only
     * durable once it commits. This method does not itself own or wrap
     * that outer boundary.
     *
     * @throws DuplicateSourceFingerprintException if a mapping already
     *                                             exists for `$tenantId`/`$sourceFingerprint` — detected via the
     *                                             real database primary key constraint, not an
     *                                             application-level pre-check.
     */
    public function record(TenantId $tenantId, SourceFingerprint $sourceFingerprint, JournalId $journalId): void
    {
        try {
            $this->connection->table(self::TABLE)->insert([
                'tenant_id' => $tenantId->toString(),
                'source_fingerprint' => $sourceFingerprint->toString(),
                'journal_id' => $journalId->toString(),
            ]);
        } catch (QueryException $e) {
            if ($this->isPrimaryKeyViolation($e)) {
                throw DuplicateSourceFingerprintException::forFingerprint($tenantId, $sourceFingerprint);
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
