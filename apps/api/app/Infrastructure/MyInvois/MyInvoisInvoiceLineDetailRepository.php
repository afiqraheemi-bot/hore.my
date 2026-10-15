<?php

declare(strict_types=1);

namespace App\Infrastructure\MyInvois;

use App\Domain\Invoicing\InvoiceId;
use App\Domain\MyInvois\MyInvoisLineDetail;
use App\Domain\MyInvois\MyInvoisTaxType;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use App\Infrastructure\Invoicing\InvoiceRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * The persistence boundary for `MyInvoisLineDetail` (AETS-013 v0.1.0
 * §6.1), through the production `myinvois_invoice_line_details` table
 * — deliberately separate from {@see InvoiceRepository},
 * which this class never touches or depends on, keeping that
 * repository exactly as unchanged as AETS-013 §6.1 states.
 *
 * **Whole-replace per Invoice, mirroring `InvoiceRepository::replaceLines()`'s
 * own convention** — a caller supplies the complete current set of
 * details (keyed by line position) on every save; this never diffs
 * row-by-row. Safe for the identical reason `replaceLines()` already
 * is: a Draft Invoice's lines (and therefore their MyInvois detail)
 * have no other table referencing an individual line yet.
 *
 * **Not run inside `InvoiceRepository::save()`/`update()`'s own
 * transaction.** A caller (the controller) invokes this right after
 * that call succeeds — an acceptable gap for optional, supplementary
 * data that nothing downstream depends on yet (no live MyInvois
 * submission exists in this version), rather than coupling two
 * otherwise-independent repositories into one cross-aggregate
 * transaction for data no code path is blocked on today.
 */
final class MyInvoisInvoiceLineDetailRepository
{
    private const TABLE = 'myinvois_invoice_line_details';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    /**
     * @param  array<int, MyInvoisLineDetail|null>  $lineDetails  Keyed by 0-based line position, same order as `Invoice::lines()`.
     */
    public function replaceForInvoice(TenantId $tenantId, InvoiceId $invoiceId, array $lineDetails): void
    {
        $this->connection->transaction(function () use ($tenantId, $invoiceId, $lineDetails): void {
            $this->connection->table(self::TABLE)
                ->where('tenant_id', $tenantId->toString())
                ->where('invoice_id', $invoiceId->toString())
                ->delete();

            $lineNumber = 1;
            foreach ($lineDetails as $detail) {
                if ($detail instanceof MyInvoisLineDetail) {
                    $this->connection->table(self::TABLE)->insert([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $tenantId->toString(),
                        'invoice_id' => $invoiceId->toString(),
                        'line_number' => $lineNumber,
                        'tax_type' => $detail->taxType()->value,
                        'tax_rate' => $detail->taxRate(),
                        'tax_amount' => $this->money->toPersistedAmount($detail->taxAmount()),
                        'classification_code' => $detail->classificationCode(),
                        'unit_of_measure' => $detail->unitOfMeasure(),
                    ]);
                }
                $lineNumber++;
            }
        });
    }

    /**
     * @return array<int, MyInvoisLineDetail> Keyed by 0-based line position — a position absent from this array has no MyInvois detail.
     */
    public function findForInvoice(TenantId $tenantId, InvoiceId $invoiceId, string $currencyIdentifier): array
    {
        /** @var list<object{line_number: int, tax_type: string, tax_rate: string, tax_amount: int|string, classification_code: string, unit_of_measure: string}> $rows */
        $rows = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('invoice_id', $invoiceId->toString())
            ->orderBy('line_number')
            ->get(['line_number', 'tax_type', 'tax_rate', 'tax_amount', 'classification_code', 'unit_of_measure'])
            ->all();

        $byPosition = [];

        foreach ($rows as $row) {
            $byPosition[((int) $row->line_number) - 1] = MyInvoisLineDetail::of(
                MyInvoisTaxType::from($row->tax_type),
                $row->tax_rate,
                $this->money->fromPersisted((string) $row->tax_amount, $currencyIdentifier),
                $row->classification_code,
                $row->unit_of_measure,
            );
        }

        return $byPosition;
    }
}
