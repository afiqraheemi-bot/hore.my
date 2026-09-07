<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Customers\CustomerId;
use App\Domain\Payments\Payment;
use App\Domain\Payments\PaymentId;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use App\Infrastructure\Transactions\Income\IncomeRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the Payment record (M21), through the
 * production `payments` table — mirrors
 * {@see IncomeRepository}'s
 * own shape exactly.
 *
 * **Immutable by design.** There is no `update()` and no `delete()` —
 * a Payment is corrected via M5 Reversal/Replacement of its Journal,
 * never by mutating this row.
 */
final class PaymentRepository
{
    private const TABLE = 'payments';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    public function record(Payment $payment): void
    {
        $this->connection->table(self::TABLE)->insert([
            'id' => $payment->id()->toString(),
            'tenant_id' => $payment->tenantId()->toString(),
            'customer_id' => $payment->customerId()->toString(),
            'payment_date' => $payment->paymentDate()->format('Y-m-d'),
            'amount' => $this->money->toPersistedAmount($payment->amount()),
            'currency' => $this->money->toPersistedCurrency($payment->amount()),
            'deposit_account_id' => $payment->depositAccountId()->toString(),
            'receivable_account_id' => $payment->receivableAccountId()->toString(),
            'journal_id' => $payment->journalId()->toString(),
            'reference' => $payment->reference(),
        ]);
    }

    public function findById(TenantId $tenantId, PaymentId $paymentId): ?Payment
    {
        /** @var object{id: string, tenant_id: string, customer_id: string, payment_date: string, amount: int|string, currency: string, deposit_account_id: string, receivable_account_id: string, journal_id: string, reference: string|null}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $paymentId->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    /**
     * @return list<Payment>
     */
    public function findAllByTenant(TenantId $tenantId): array
    {
        /** @var list<object{id: string, tenant_id: string, customer_id: string, payment_date: string, amount: int|string, currency: string, deposit_account_id: string, receivable_account_id: string, journal_id: string, reference: string|null}> $rows */
        $rows = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->orderBy('recorded_at', 'desc')
            ->get()
            ->all();

        return array_map(fn (object $row): Payment => $this->fromPersisted($row), $rows);
    }

    /**
     * @param  object{id: string, tenant_id: string, customer_id: string, payment_date: string, amount: int|string, currency: string, deposit_account_id: string, receivable_account_id: string, journal_id: string, reference: string|null}  $row
     */
    private function fromPersisted(object $row): Payment
    {
        return Payment::reconstitute(
            PaymentId::of($row->id),
            TenantId::of($row->tenant_id),
            JournalId::of($row->journal_id),
            CustomerId::of($row->customer_id),
            $this->money->fromPersisted((string) $row->amount, $row->currency),
            new \DateTimeImmutable($row->payment_date),
            AccountId::of($row->deposit_account_id),
            AccountId::of($row->receivable_account_id),
            $row->reference,
        );
    }
}
