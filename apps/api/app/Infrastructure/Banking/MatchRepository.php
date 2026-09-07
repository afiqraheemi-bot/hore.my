<?php

declare(strict_types=1);

namespace App\Infrastructure\Banking;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Banking\BankTransactionId;
use App\Domain\Banking\BankTransactionMatch;
use App\Domain\Banking\MatchId;
use App\Domain\Banking\MatchSourceType;
use App\Domain\Shared\Tenancy\TenantId;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the Match aggregate (M18), through the
 * production `matches` table.
 */
final class MatchRepository
{
    private const TABLE = 'matches';

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function record(BankTransactionMatch $match): void
    {
        $this->connection->table(self::TABLE)->insert([
            'id' => $match->id()->toString(),
            'tenant_id' => $match->tenantId()->toString(),
            'bank_transaction_id' => $match->bankTransactionId()->toString(),
            'journal_id' => $match->journalId()->toString(),
            'source_type' => $match->sourceType()->name,
            'rationale' => $match->rationale(),
            'matched_by' => $match->matchedBy()->toString(),
            'matched_at' => $match->matchedAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function findByBankTransactionId(TenantId $tenantId, BankTransactionId $bankTransactionId): ?BankTransactionMatch
    {
        /** @var object{id: string, tenant_id: string, bank_transaction_id: string, journal_id: string, source_type: string, rationale: string, matched_by: string, matched_at: string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('bank_transaction_id', $bankTransactionId->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    /**
     * @param  list<BankTransactionId>  $bankTransactionIds
     * @return list<BankTransactionId>
     */
    public function matchedBankTransactionIds(TenantId $tenantId, array $bankTransactionIds): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->whereIn('bank_transaction_id', array_map(static fn (BankTransactionId $id): string => $id->toString(), $bankTransactionIds))
            ->pluck('bank_transaction_id')
            ->all();

        return array_map(static fn (string $id): BankTransactionId => BankTransactionId::of($id), $ids);
    }

    /**
     * @param  object{id: string, tenant_id: string, bank_transaction_id: string, journal_id: string, source_type: string, rationale: string, matched_by: string, matched_at: string}  $row
     */
    private function fromPersisted(object $row): BankTransactionMatch
    {
        return BankTransactionMatch::reconstitute(
            MatchId::of($row->id),
            TenantId::of($row->tenant_id),
            BankTransactionId::of($row->bank_transaction_id),
            JournalId::of($row->journal_id),
            self::sourceTypeFromPersisted($row->source_type),
            $row->rationale,
            ActorReference::of($row->matched_by),
            new \DateTimeImmutable($row->matched_at),
        );
    }

    private static function sourceTypeFromPersisted(string $value): MatchSourceType
    {
        foreach (MatchSourceType::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw new \RuntimeException(sprintf('Unrecognized persisted Match source type "%s".', $value));
    }
}
