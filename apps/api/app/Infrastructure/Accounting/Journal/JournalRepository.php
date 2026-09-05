<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Journal;

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Journal\Exception\DuplicateJournalIdentityException;
use App\Infrastructure\Accounting\Journal\Exception\ImmutableJournalStateException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * Persists and retrieves the Journal aggregate — header and Journal
 * Lines together — through the production `journals`/`journal_lines`
 * tables (M3-T9). {@see JournalPersistenceAdapter} remains the sole
 * mapping boundary between the Journal domain and these row shapes:
 * this class never reads or writes a field the adapter does not
 * already know about, and never returns a raw database row to a
 * caller — every public method here returns either a {@see Journal},
 * `null`, or nothing at all.
 *
 * **What this repository does not do.** It invents no domain
 * mutation this codebase's Journal aggregate does not already expose
 * — {@see save()} never becomes a backdoor around domain immutability
 * (see its own docblock for exactly what "immutable" means here). It
 * has no `delete()` method: nothing in AETS-004 permits a hard delete
 * of Journal history, and no repository operation invents one. It has
 * no line-mutation, list, or search API — the current Journal domain
 * exposes no way to add, remove, reorder, or otherwise change a
 * Line's content after construction, so persistence cannot either.
 * No idempotency, Audit Event, Outbox, Actor/Source/Evidence, or
 * Posting Engine concern is implemented here — those belong to a
 * future task; this repository is a pure persistence boundary.
 *
 * **Concurrency on a brand-new identity (M4-T18B).** {@see save()}'s
 * own existence check and lock apply only to a JournalId that is
 * already persisted — two genuinely concurrent `save()` calls for the
 * exact same, brand-new JournalId can both observe no existing row
 * before either commits. The real `journals_pkey`/
 * `journals_tenant_id_journal_id_unique` constraints are the final,
 * race-safe authority for that specific case, and this repository
 * translates only that one known race into
 * {@see DuplicateJournalIdentityException} — narrowly, by checking
 * the failing constraint's own name, exactly mirroring
 * `AccountRepository::save()`'s already-established Account Code
 * translation. No other {@see QueryException} is touched.
 */
final class JournalRepository
{
    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const POSTED_STATE = 'Posted';

    private const JOURNAL_PRIMARY_KEY_CONSTRAINT = 'journals_pkey';

    private const JOURNAL_TENANT_UNIQUE_CONSTRAINT = 'journals_tenant_id_journal_id_unique';

    private const UNIQUE_VIOLATION_SQLSTATE = '23505';

    private readonly JournalPersistenceAdapter $adapter;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->adapter = new JournalPersistenceAdapter;
    }

    /**
     * Persist a Journal: `INSERT` the header and every Journal Line
     * together, in one transaction, if `$journal`'s identifier is new
     * — or, if a Journal already exists under that identifier, update
     * *only* its `state` column, and only after verifying every other
     * fact about it — TenantId and the exact Journal Line set (each
     * Line's Account, Money, Currency, Direction, and order) — is
     * unchanged from what is already persisted.
     *
     * **Why only `state` may ever change.** The current Journal domain
     * exposes no line-mutation API at all ({@see Journal::create()}
     * and {@see Journal::reconstitute()} are the only ways a Journal
     * Line set is ever assembled) and exactly one transition,
     * {@see Journal::post()} (Draft -> Posted, never the reverse). A
     * `$journal` that disagrees with the persisted row on TenantId or
     * on any Line therefore cannot represent a legitimate state the
     * domain could have produced — it is rejected outright, and the
     * existing row(s) are left completely unchanged. This repository
     * does not decide *how* a Journal became inconsistent (a stale
     * in-memory instance, a programming error); it only refuses to
     * let persistence become a backdoor around invariants the domain
     * itself already protects.
     *
     * **Posted is terminal.** Once a Journal is persisted as Posted,
     * no further call to this method for the same identifier succeeds
     * — not a reversal to Draft, not a re-post with identical data,
     * not a Line change. Posted Journal history is immutable
     * (AETS-004 §9, §15).
     *
     * **Atomicity.** The header insert and every Line insert happen
     * inside one database transaction — a fault partway through (for
     * example, a Line referencing an Account that does not exist)
     * rolls back the entire attempt, including the header; no partial
     * Journal is ever observable.
     *
     * **Concurrency.** The existence check and any resulting update
     * happen inside that same transaction, with the existing header
     * row (if any) locked via `SELECT ... FOR UPDATE`. A second
     * concurrent `save()` for the same {@see JournalId} therefore
     * blocks until the first transaction commits or rolls back, rather
     * than reading stale state and racing past this method's own
     * immutability checks — there is no "check exists, then decide"
     * window a race can land in. A brand-new identifier's insert still
     * relies on the real `journals` primary key as the final,
     * race-safe authority for two concurrent inserts of the same
     * identifier.
     *
     * **`journal_lines.tenant_id`.** {@see JournalPersistenceAdapter}
     * deliberately never reads or writes this column — the production
     * migration's own docblock (M3-T9) is explicit that it exists only
     * to make the schema's same-Tenant Journal/Account composite
     * foreign key possible, not as a `JournalLine` domain property.
     * Bridging that gap is this repository's job, not the adapter's:
     * every line row this method inserts has the header's own
     * `tenant_id` merged in, and every line row it later reads back is
     * stripped back down to the adapter's exact expected shape before
     * comparison or reconstruction.
     *
     * @throws ImmutableJournalStateException if a Journal already
     *                                        persisted under `$journal`'s identifier is already Posted,
     *                                        or disagrees with `$journal` on TenantId or its exact
     *                                        Journal Line set.
     * @throws DuplicateJournalIdentityException if a genuinely
     *                                           concurrent `save()` call for the exact same, brand-new
     *                                           JournalId has already committed — detected via the real
     *                                           `journals_pkey`/`journals_tenant_id_journal_id_unique`
     *                                           database constraints, not an application-level pre-check.
     *                                           Every other persistence failure (a foreign key violation on
     *                                           a Journal Line's Account, a `CHECK` violation, a lock
     *                                           timeout, a serialization failure) propagates as a raw
     *                                           {@see QueryException}, unmodified.
     */
    public function save(Journal $journal): void
    {
        $header = $this->adapter->toPersistedHeader($journal);
        $lines = $this->adapter->toPersistedLines($journal);

        try {
            $this->connection->transaction(function () use ($journal, $header, $lines): void {
                /** @var object{tenant_id: string, journal_id: string, state: string}|null $existingHeader */
                $existingHeader = $this->connection->table(self::JOURNAL_TABLE)
                    ->where('journal_id', $header['journal_id'])
                    ->lockForUpdate()
                    ->first();

                if ($existingHeader === null) {
                    $this->connection->table(self::JOURNAL_TABLE)->insert($header);
                    $this->connection->table(self::LINE_TABLE)->insert(array_map(
                        static fn (array $line): array => ['tenant_id' => $header['tenant_id'], ...$line],
                        $lines,
                    ));

                    return;
                }

                if ($existingHeader->state === self::POSTED_STATE) {
                    throw ImmutableJournalStateException::forPostedJournal($journal->id());
                }

                if ($existingHeader->tenant_id !== $header['tenant_id']) {
                    throw ImmutableJournalStateException::forField($journal->id(), 'TenantId');
                }

                $existingLines = $this->connection->table(self::LINE_TABLE)
                    ->where('journal_id', $header['journal_id'])
                    ->orderBy('line_position')
                    ->get()
                    ->map(fn (object $row): array => $this->lineRowToArray($row))
                    ->all();

                if ($lines !== $existingLines) {
                    throw ImmutableJournalStateException::forField($journal->id(), 'Journal Line set');
                }

                $this->connection->table(self::JOURNAL_TABLE)
                    ->where('journal_id', $header['journal_id'])
                    ->update(['state' => $header['state']]);
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateJournalIdentityViolation($e)) {
                throw DuplicateJournalIdentityException::forJournalId($journal->id());
            }

            throw $e;
        }
    }

    private function isDuplicateJournalIdentityViolation(QueryException $e): bool
    {
        return $e->getCode() === self::UNIQUE_VIOLATION_SQLSTATE
            && (str_contains($e->getMessage(), self::JOURNAL_PRIMARY_KEY_CONSTRAINT)
                || str_contains($e->getMessage(), self::JOURNAL_TENANT_UNIQUE_CONSTRAINT));
    }

    /**
     * Retrieve a Journal by its stable identifier, scoped to the given
     * Tenant — a Journal belonging to a different Tenant than
     * `$tenantId`, even one with the same `$journalId`, is never
     * returned (`JRN-001`). Lines are loaded ordered by their
     * `line_position` and reconstructed through
     * {@see Journal::reconstitute()}, never `create(...)->post()`.
     */
    public function findById(TenantId $tenantId, JournalId $journalId): ?Journal
    {
        /** @var object{tenant_id: string, journal_id: string, state: string}|null $headerRow */
        $headerRow = $this->connection->table(self::JOURNAL_TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('journal_id', $journalId->toString())
            ->first();

        if ($headerRow === null) {
            return null;
        }

        // Lines are looked up by journal_id alone, not re-filtered by
        // tenant_id: the header query above already proved this
        // journal_id belongs to $tenantId (nothing is returned
        // otherwise), and journal_lines.journal_id is foreign-key
        // constrained to reference exactly one journals row, so no
        // cross-tenant line can exist here regardless.
        $lineRows = $this->connection->table(self::LINE_TABLE)
            ->where('journal_id', $journalId->toString())
            ->orderBy('line_position')
            ->get();

        $header = [
            'tenant_id' => $headerRow->tenant_id,
            'journal_id' => $headerRow->journal_id,
            'state' => $headerRow->state,
        ];

        $lines = array_values($lineRows->map(fn (object $row): array => $this->lineRowToArray($row))->all());

        return $this->adapter->fromPersistedJournal($header, $lines);
    }

    /**
     * @return array{journal_id: string, line_position: int, account_id: string, amount: string, currency: string, direction: string}
     */
    private function lineRowToArray(object $row): array
    {
        /** @var object{journal_id: string, line_position: int|string, account_id: string, amount: int|string, currency: string, direction: string} $row */
        return [
            'journal_id' => $row->journal_id,
            'line_position' => (int) $row->line_position,
            'account_id' => $row->account_id,
            'amount' => (string) $row->amount,
            'currency' => $row->currency,
            'direction' => $row->direction,
        ];
    }
}
