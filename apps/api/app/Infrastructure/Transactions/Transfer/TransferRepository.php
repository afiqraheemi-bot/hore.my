<?php

declare(strict_types=1);

namespace App\Infrastructure\Transactions\Transfer;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Transfer\Transfer;
use App\Domain\Transactions\Transfer\TransferId;
use App\Domain\Transactions\Transfer\TransferRecordingService;
use App\Infrastructure\Accounting\Audit\AuditEventRepository;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the Transfer record (M14), through the
 * production `transfers` table.
 *
 * **Transaction participation.** {@see record()} opens no transaction
 * of its own — the `INSERT` runs directly on `$this->connection`'s
 * current state, so when a caller has already started a transaction on
 * that same connection (the outer transaction
 * {@see TransferRecordingService} manages, which also posts the
 * Journal this Transfer references), this insert becomes part of it —
 * the identical participation pattern
 * {@see PostingIdempotencyRepository::record()} and
 * {@see AuditEventRepository::record()} already establish.
 *
 * **Immutable by design.** There is no `update()` and no `delete()` —
 * a Transfer is corrected via M5 Reversal/Replacement of its Journal,
 * never by mutating this row.
 */
final class TransferRepository
{
    private const TABLE = 'transfers';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    public function record(Transfer $transfer): void
    {
        $evidenceReference = $transfer->evidenceReference();

        $this->connection->table(self::TABLE)->insert([
            'id' => $transfer->id()->toString(),
            'tenant_id' => $transfer->tenantId()->toString(),
            'journal_id' => $transfer->journalId()->toString(),
            'amount' => $this->money->toPersistedAmount($transfer->amount()),
            'currency' => $this->money->toPersistedCurrency($transfer->amount()),
            'transaction_date' => $transfer->transactionDate()->format('Y-m-d'),
            'source_account_id' => $transfer->sourceAccountId()->toString(),
            'destination_account_id' => $transfer->destinationAccountId()->toString(),
            'description' => $transfer->description(),
            'evidence_reference' => $evidenceReference?->toString(),
        ]);
    }

    /**
     * Retrieve a Transfer by its own stable identifier, scoped to the
     * given Tenant — a Transfer belonging to a different Tenant, even
     * one with the same `$transferId`, is never returned.
     */
    public function findById(TenantId $tenantId, TransferId $transferId): ?Transfer
    {
        /** @var object{id: string, tenant_id: string, journal_id: string, amount: int|string, currency: string, transaction_date: string, source_account_id: string, destination_account_id: string, description: string, evidence_reference: string|null}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $transferId->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    /**
     * Retrieve a Transfer by the Journal it produced, scoped to the
     * given Tenant — used to resolve an idempotent replay back to its
     * originally-recorded Transfer.
     */
    public function findByJournalId(TenantId $tenantId, JournalId $journalId): ?Transfer
    {
        /** @var object{id: string, tenant_id: string, journal_id: string, amount: int|string, currency: string, transaction_date: string, source_account_id: string, destination_account_id: string, description: string, evidence_reference: string|null}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('journal_id', $journalId->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    /**
     * @param  object{id: string, tenant_id: string, journal_id: string, amount: int|string, currency: string, transaction_date: string, source_account_id: string, destination_account_id: string, description: string, evidence_reference: string|null}  $row
     */
    private function fromPersisted(object $row): Transfer
    {
        return Transfer::reconstitute(
            TransferId::of($row->id),
            TenantId::of($row->tenant_id),
            JournalId::of($row->journal_id),
            $this->money->fromPersisted((string) $row->amount, $row->currency),
            new \DateTimeImmutable($row->transaction_date),
            AccountId::of($row->source_account_id),
            AccountId::of($row->destination_account_id),
            $row->description,
            $row->evidence_reference === null ? null : EvidenceReference::of($row->evidence_reference),
        );
    }
}
