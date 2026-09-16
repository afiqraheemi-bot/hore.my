<?php

declare(strict_types=1);

namespace App\Infrastructure\Quotations;

use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Quotations\Exception\CorruptQuotationRecordException;
use App\Domain\Quotations\Quotation;
use App\Domain\Quotations\QuotationConversionService;
use App\Domain\Quotations\QuotationId;
use App\Domain\Quotations\QuotationLine;
use App\Domain\Quotations\QuotationStatus;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use App\Infrastructure\Invoicing\InvoiceRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * The persistence boundary for the Quotation aggregate (AETS-016),
 * through the production `quotations`/`quotation_lines` tables.
 * Mirrors {@see InvoiceRepository}
 * exactly, including its own "whole-row replace on every Draft edit"
 * convention — safe here for the identical reason: a Draft Quotation
 * has nothing else referencing its individual lines yet.
 */
final class QuotationRepository
{
    private const TABLE = 'quotations';

    private const LINES_TABLE = 'quotation_lines';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    public function save(Quotation $quotation): void
    {
        $this->connection->transaction(function () use ($quotation): void {
            $this->connection->table(self::TABLE)->insert($this->quotationRowFor($quotation));
            $this->replaceLines($quotation);
        });
    }

    public function update(Quotation $quotation): void
    {
        $this->connection->transaction(function () use ($quotation): void {
            $this->connection->table(self::TABLE)
                ->where('tenant_id', $quotation->tenantId()->toString())
                ->where('id', $quotation->id()->toString())
                ->update([
                    'customer_id' => $quotation->customerId()->toString(),
                    'valid_until' => $quotation->validUntil()->format('Y-m-d'),
                    'total_amount' => $this->money->toPersistedAmount($quotation->totalAmount()),
                    'updated_at' => now(),
                ]);
            $this->replaceLines($quotation);
        });
    }

    public function markSent(Quotation $quotation): void
    {
        $this->connection->table(self::TABLE)
            ->where('tenant_id', $quotation->tenantId()->toString())
            ->where('id', $quotation->id()->toString())
            ->update([
                'quotation_number' => $quotation->quotationNumber(),
                'status' => QuotationStatus::Sent->name,
                'issue_date' => $quotation->issueDate()?->format('Y-m-d'),
                'updated_at' => now(),
            ]);
    }

    /**
     * Persists the outcome of `accept()`, `reject()`, or `convert()` —
     * each changes only `status` and, for `convert()`, the resulting
     * `converted_invoice_id`.
     */
    public function updateStatus(Quotation $quotation): void
    {
        $this->connection->table(self::TABLE)
            ->where('tenant_id', $quotation->tenantId()->toString())
            ->where('id', $quotation->id()->toString())
            ->update([
                'status' => $quotation->status()->name,
                'converted_invoice_id' => $quotation->convertedInvoiceId()?->toString(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Alias for {@see updateStatus()} used from
     * {@see QuotationConversionService} for
     * readability at its own call site.
     */
    public function markConverted(Quotation $quotation): void
    {
        $this->updateStatus($quotation);
    }

    public function delete(TenantId $tenantId, QuotationId $quotationId): void
    {
        $this->connection->transaction(function () use ($tenantId, $quotationId): void {
            $this->connection->table(self::LINES_TABLE)
                ->where('tenant_id', $tenantId->toString())
                ->where('quotation_id', $quotationId->toString())
                ->delete();

            $this->connection->table(self::TABLE)
                ->where('tenant_id', $tenantId->toString())
                ->where('id', $quotationId->toString())
                ->delete();
        });
    }

    public function findById(TenantId $tenantId, QuotationId $quotationId): ?Quotation
    {
        /** @var object{id: string, tenant_id: string, customer_id: string, quotation_number: string|null, status: string, issue_date: string|null, valid_until: string, converted_invoice_id: string|null, total_amount: int|string, currency: string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $quotationId->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->fromPersisted($row);
    }

    /**
     * @return list<Quotation>
     */
    public function findAllByTenant(TenantId $tenantId): array
    {
        /** @var list<object{id: string, tenant_id: string, customer_id: string, quotation_number: string|null, status: string, issue_date: string|null, valid_until: string, converted_invoice_id: string|null, total_amount: int|string, currency: string}> $rows */
        $rows = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->orderBy('created_at', 'desc')
            ->get()
            ->all();

        return array_map(fn (object $row): Quotation => $this->fromPersisted($row), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function quotationRowFor(Quotation $quotation): array
    {
        return [
            'id' => $quotation->id()->toString(),
            'tenant_id' => $quotation->tenantId()->toString(),
            'customer_id' => $quotation->customerId()->toString(),
            'quotation_number' => $quotation->quotationNumber(),
            'status' => $quotation->status()->name,
            'issue_date' => $quotation->issueDate()?->format('Y-m-d'),
            'valid_until' => $quotation->validUntil()->format('Y-m-d'),
            'converted_invoice_id' => $quotation->convertedInvoiceId()?->toString(),
            'total_amount' => $this->money->toPersistedAmount($quotation->totalAmount()),
            'currency' => $this->money->toPersistedCurrency($quotation->totalAmount()),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function replaceLines(Quotation $quotation): void
    {
        $this->connection->table(self::LINES_TABLE)
            ->where('tenant_id', $quotation->tenantId()->toString())
            ->where('quotation_id', $quotation->id()->toString())
            ->delete();

        $lineNumber = 1;
        foreach ($quotation->lines() as $line) {
            $this->connection->table(self::LINES_TABLE)->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $quotation->tenantId()->toString(),
                'quotation_id' => $quotation->id()->toString(),
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
     * @param  object{id: string, tenant_id: string, customer_id: string, quotation_number: string|null, status: string, issue_date: string|null, valid_until: string, converted_invoice_id: string|null, total_amount: int|string, currency: string}  $row
     */
    private function fromPersisted(object $row): Quotation
    {
        $quotationId = QuotationId::of($row->id);

        $status = self::statusFromPersisted($quotationId, $row->status);

        return Quotation::reconstitute(
            $quotationId,
            TenantId::of($row->tenant_id),
            CustomerId::of($row->customer_id),
            $row->quotation_number,
            $status,
            $row->issue_date === null ? null : new \DateTimeImmutable($row->issue_date),
            new \DateTimeImmutable($row->valid_until),
            $row->converted_invoice_id === null ? null : InvoiceId::of($row->converted_invoice_id),
            $this->linesFor($row->tenant_id, $row->id, $row->currency),
            $this->money->fromPersisted((string) $row->total_amount, $row->currency),
        );
    }

    private static function statusFromPersisted(QuotationId $quotationId, string $value): QuotationStatus
    {
        foreach (QuotationStatus::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw CorruptQuotationRecordException::forUnrecognizedStatus($quotationId, $value);
    }

    /**
     * @return list<QuotationLine>
     */
    private function linesFor(string $tenantId, string $quotationId, string $currencyIdentifier): array
    {
        /** @var list<object{description: string, quantity: int, unit_price: int|string, line_amount: int|string}> $rows */
        $rows = $this->connection->table(self::LINES_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('quotation_id', $quotationId)
            ->orderBy('line_number')
            ->get(['description', 'quantity', 'unit_price', 'line_amount'])
            ->all();

        return array_map(fn (object $row): QuotationLine => QuotationLine::reconstitute(
            $row->description,
            (int) $row->quantity,
            $this->money->fromPersisted((string) $row->unit_price, $currencyIdentifier),
            $this->money->fromPersisted((string) $row->line_amount, $currencyIdentifier),
        ), $rows);
    }
}
