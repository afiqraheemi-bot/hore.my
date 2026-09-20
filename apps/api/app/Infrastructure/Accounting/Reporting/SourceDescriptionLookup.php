<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Reporting;

use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Http\Controllers\Api\ReportingController;
use Illuminate\Database\ConnectionInterface;

/**
 * A purely presentational enrichment for a list of {@see SourceReference}s
 * — a short, human description of what each one actually is ("Printer
 * paper", "Invoice INV-2024-003", "Payment from Kedai Ah Chong"), for
 * activity lists that need more than the generic per-type label
 * `SourceReference` itself deliberately carries no opinion about
 * (AETS-007 §9.1: it "does not itself parse, interpret, or classify
 * what it points to").
 *
 * **Not part of any normative report.** {@see EvidenceIndexQuery}
 * (AETS-009 §10) stays exactly as specified; this class only adds a
 * `description` alongside its output at the HTTP boundary
 * ({@see ReportingController}), for a UI
 * convenience the report itself was never required to carry.
 *
 * One bulk query per source type actually present — never one query
 * per entry — mirroring {@see EvidenceIndexQuery::fetchEvidenceReferences()}'s
 * own reasoning for an activity list that can be arbitrarily long.
 */
final class SourceDescriptionLookup
{
    /**
     * Prefix => backing table, for every source type whose own table
     * carries a genuine free-text `description` column (M7/M9's own
     * schema, identical column name and shape in all four).
     */
    private const DESCRIPTION_TABLES = [
        'expense' => 'expenses',
        'income' => 'incomes',
        'transfer' => 'transfers',
        'owner-equity' => 'owner_equity_transactions',
    ];

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<SourceReference>  $sources
     * @return array<string, string> a description keyed by the Source
     *                               reference's own canonical string — only for
     *                               sources this class could resolve one for; a
     *                               source with no real backing row (e.g.
     *                               `period-closing:...`, which is synthetic, not
     *                               table-backed) or nothing worth showing (e.g. a
     *                               Payment with no reference) is simply absent,
     *                               never a placeholder value.
     */
    public function forSources(TenantId $tenantId, array $sources): array
    {
        $idsByPrefix = [];

        foreach ($sources as $source) {
            [$prefix, $id] = self::split($source->toString());

            if ($prefix !== null) {
                $idsByPrefix[$prefix][] = $id;
            }
        }

        $descriptions = [];

        foreach (self::DESCRIPTION_TABLES as $prefix => $table) {
            if (! isset($idsByPrefix[$prefix])) {
                continue;
            }

            /** @var list<object{id: string, description: string}> $rows */
            $rows = $this->connection->table($table)
                ->where('tenant_id', $tenantId->toString())
                ->whereIn('id', $idsByPrefix[$prefix])
                ->get(['id', 'description'])
                ->all();

            foreach ($rows as $row) {
                $descriptions[$prefix.':'.$row->id] = $row->description;
            }
        }

        if (isset($idsByPrefix['invoice'])) {
            $this->addInvoiceDescriptions($tenantId, $idsByPrefix['invoice'], $descriptions);
        }

        if (isset($idsByPrefix['payment'])) {
            $this->addPaymentDescriptions($tenantId, $idsByPrefix['payment'], $descriptions);
        }

        return $descriptions;
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, string>  $descriptions
     */
    private function addInvoiceDescriptions(TenantId $tenantId, array $ids, array &$descriptions): void
    {
        /** @var list<object{id: string, invoice_number: string|null}> $rows */
        $rows = $this->connection->table('invoices')
            ->where('tenant_id', $tenantId->toString())
            ->whereIn('id', $ids)
            ->get(['id', 'invoice_number'])
            ->all();

        foreach ($rows as $row) {
            if ($row->invoice_number !== null) {
                $descriptions['invoice:'.$row->id] = 'Invoice '.$row->invoice_number;
            }
        }
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, string>  $descriptions
     */
    private function addPaymentDescriptions(TenantId $tenantId, array $ids, array &$descriptions): void
    {
        /** @var list<object{id: string, customer_name: string|null}> $rows */
        $rows = $this->connection->table('payments')
            ->join('customers', function ($join): void {
                $join->on('payments.tenant_id', '=', 'customers.tenant_id')
                    ->on('payments.customer_id', '=', 'customers.id');
            })
            ->where('payments.tenant_id', $tenantId->toString())
            ->whereIn('payments.id', $ids)
            ->get(['payments.id as id', 'customers.name as customer_name'])
            ->all();

        foreach ($rows as $row) {
            if ($row->customer_name !== null) {
                $descriptions['payment:'.$row->id] = 'Payment from '.$row->customer_name;
            }
        }
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private static function split(string $source): array
    {
        $colonPosition = strpos($source, ':');

        if ($colonPosition === false) {
            return [null, ''];
        }

        return [substr($source, 0, $colonPosition), substr($source, $colonPosition + 1)];
    }
}
