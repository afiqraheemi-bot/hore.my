<?php

declare(strict_types=1);

namespace App\Infrastructure\Invoicing;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\Exception\CorruptInvoiceRecordException;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceLine;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * The persistence boundary for the Invoice aggregate (M20), through
 * the production `invoices`/`invoice_lines` tables.
 *
 * **A Draft's lines are wholly replaced on every edit**, never diffed
 * line-by-line — {@see save()} and {@see update()} both delete every
 * existing `invoice_lines` row for the Invoice and re-insert the
 * current set, safe only because a Draft Invoice has no
 * Payment/Allocation referencing individual lines yet (a future
 * milestone's own concern, once Payments exist).
 */
final class InvoiceRepository
{
    private const TABLE = 'invoices';

    private const LINES_TABLE = 'invoice_lines';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    public function save(Invoice $invoice): void
    {
        $this->connection->transaction(function () use ($invoice): void {
            $this->connection->table(self::TABLE)->insert($this->invoiceRowFor($invoice));
            $this->replaceLines($invoice);
        });
    }

    public function update(Invoice $invoice): void
    {
        $this->connection->transaction(function () use ($invoice): void {
            $this->connection->table(self::TABLE)
                ->where('tenant_id', $invoice->tenantId()->toString())
                ->where('id', $invoice->id()->toString())
                ->update([
                    'due_date' => $invoice->dueDate()->format('Y-m-d'),
                    'receivable_account_id' => $invoice->receivableAccountId()->toString(),
                    'revenue_account_id' => $invoice->revenueAccountId()->toString(),
                    'total_amount' => $this->money->toPersistedAmount($invoice->totalAmount()),
                    'updated_at' => now(),
                ]);
            $this->replaceLines($invoice);
        });
    }

    public function markIssued(Invoice $invoice): void
    {
        $journalId = $invoice->journalId();

        $this->connection->table(self::TABLE)
            ->where('tenant_id', $invoice->tenantId()->toString())
            ->where('id', $invoice->id()->toString())
            ->update([
                'invoice_number' => $invoice->invoiceNumber(),
                'status' => InvoiceStatus::Issued->name,
                'issue_date' => $invoice->issueDate()?->format('Y-m-d'),
                'journal_id' => $journalId?->toString(),
                'updated_at' => now(),
            ]);
    }

    public function delete(TenantId $tenantId, InvoiceId $invoiceId): void
    {
        $this->connection->transaction(function () use ($tenantId, $invoiceId): void {
            $this->connection->table(self::LINES_TABLE)
                ->where('tenant_id', $tenantId->toString())
                ->where('invoice_id', $invoiceId->toString())
                ->delete();

            $this->connection->table(self::TABLE)
                ->where('tenant_id', $tenantId->toString())
                ->where('id', $invoiceId->toString())
                ->delete();
        });
    }

    public function findById(TenantId $tenantId, InvoiceId $invoiceId): ?Invoice
    {
        /** @var object{id: string, tenant_id: string, customer_id: string, invoice_number: string|null, status: string, issue_date: string|null, due_date: string, receivable_account_id: string, revenue_account_id: string, journal_id: string|null, total_amount: int|string, currency: string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $invoiceId->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    /**
     * @return list<Invoice>
     */
    public function findAllByTenant(TenantId $tenantId): array
    {
        /** @var list<object{id: string, tenant_id: string, customer_id: string, invoice_number: string|null, status: string, issue_date: string|null, due_date: string, receivable_account_id: string, revenue_account_id: string, journal_id: string|null, total_amount: int|string, currency: string}> $rows */
        $rows = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->orderBy('created_at', 'desc')
            ->get()
            ->all();

        return array_map(fn (object $row): Invoice => $this->fromPersisted($row), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceRowFor(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id()->toString(),
            'tenant_id' => $invoice->tenantId()->toString(),
            'customer_id' => $invoice->customerId()->toString(),
            'invoice_number' => $invoice->invoiceNumber(),
            'status' => $invoice->status()->name,
            'issue_date' => $invoice->issueDate()?->format('Y-m-d'),
            'due_date' => $invoice->dueDate()->format('Y-m-d'),
            'receivable_account_id' => $invoice->receivableAccountId()->toString(),
            'revenue_account_id' => $invoice->revenueAccountId()->toString(),
            'journal_id' => $invoice->journalId()?->toString(),
            'total_amount' => $this->money->toPersistedAmount($invoice->totalAmount()),
            'currency' => $this->money->toPersistedCurrency($invoice->totalAmount()),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function replaceLines(Invoice $invoice): void
    {
        $this->connection->table(self::LINES_TABLE)
            ->where('tenant_id', $invoice->tenantId()->toString())
            ->where('invoice_id', $invoice->id()->toString())
            ->delete();

        $lineNumber = 1;
        foreach ($invoice->lines() as $line) {
            $this->connection->table(self::LINES_TABLE)->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $invoice->tenantId()->toString(),
                'invoice_id' => $invoice->id()->toString(),
                'line_number' => $lineNumber,
                'description' => $line->description(),
                'quantity' => $line->quantity(),
                'unit_price' => $this->money->toPersistedAmount($line->unitPrice()),
                'line_amount' => $this->money->toPersistedAmount($line->lineAmount()),
            ]);
            $lineNumber++;
        }
    }

    /**
     * @param  object{id: string, tenant_id: string, customer_id: string, invoice_number: string|null, status: string, issue_date: string|null, due_date: string, receivable_account_id: string, revenue_account_id: string, journal_id: string|null, total_amount: int|string, currency: string}  $row
     */
    private function fromPersisted(object $row): Invoice
    {
        $invoiceId = InvoiceId::of($row->id);

        $status = match ($row->status) {
            InvoiceStatus::Draft->name => InvoiceStatus::Draft,
            InvoiceStatus::Issued->name => InvoiceStatus::Issued,
            default => throw CorruptInvoiceRecordException::forUnrecognizedStatus($invoiceId, $row->status),
        };

        return Invoice::reconstitute(
            $invoiceId,
            TenantId::of($row->tenant_id),
            CustomerId::of($row->customer_id),
            $row->invoice_number,
            $status,
            $row->issue_date === null ? null : new \DateTimeImmutable($row->issue_date),
            new \DateTimeImmutable($row->due_date),
            AccountId::of($row->receivable_account_id),
            AccountId::of($row->revenue_account_id),
            $row->journal_id === null ? null : JournalId::of($row->journal_id),
            $this->linesFor($row->tenant_id, $row->id, $row->currency),
            $this->money->fromPersisted((string) $row->total_amount, $row->currency),
        );
    }

    /**
     * @return list<InvoiceLine>
     */
    private function linesFor(string $tenantId, string $invoiceId, string $currencyIdentifier): array
    {
        /** @var list<object{description: string, quantity: int, unit_price: int|string, line_amount: int|string}> $rows */
        $rows = $this->connection->table(self::LINES_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('invoice_id', $invoiceId)
            ->orderBy('line_number')
            ->get(['description', 'quantity', 'unit_price', 'line_amount'])
            ->all();

        return array_map(fn (object $row): InvoiceLine => InvoiceLine::reconstitute(
            $row->description,
            (int) $row->quantity,
            $this->money->fromPersisted((string) $row->unit_price, $currencyIdentifier),
            $this->money->fromPersisted((string) $row->line_amount, $currencyIdentifier),
        ), $rows);
    }
}
