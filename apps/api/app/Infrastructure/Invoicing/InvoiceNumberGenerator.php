<?php

declare(strict_types=1);

namespace App\Infrastructure\Invoicing;

use App\Domain\Invoicing\InvoiceIssuingService;
use App\Domain\Shared\Tenancy\TenantId;
use Illuminate\Database\ConnectionInterface;

/**
 * Assigns each Tenant its own sequential Invoice number (M20), backed
 * by the `invoice_number_sequences` table — one row per Tenant.
 *
 * **Atomic get-and-increment via a single `INSERT ... ON CONFLICT DO
 * UPDATE ... RETURNING` statement** — PostgreSQL's own row-level
 * locking on the upserted row makes this safe under concurrent calls
 * for the same Tenant without an explicit application-level lock.
 * Always called from inside {@see InvoiceIssuingService::issue()}'s
 * own outer transaction, so a rolled-back Issue attempt does not
 * permanently burn a number in the common failure case — though a
 * genuinely concurrent Issue race still can (two transactions each
 * reserve a number, one then rolls back for an unrelated reason): this
 * is accepted, since Malaysian invoicing/MyInvois requires unique,
 * monotonically increasing numbers per Tenant, not strictly gapless
 * ones.
 *
 * **Format: `INV-000001`, zero-padded to 6 digits** — human-readable
 * and lexicographically sortable up to 999,999 invoices per Tenant; a
 * Tenant that exceeds that is expected to be well past MVP scale, at
 * which point the format itself becomes a Founder-level product
 * decision, not an engineering default to guess now.
 */
final class InvoiceNumberGenerator
{
    private const TABLE = 'invoice_number_sequences';

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function next(TenantId $tenantId): string
    {
        /** @var object{next_number: int|string} $row */
        $row = $this->connection->selectOne(
            sprintf(
                'insert into %s (tenant_id, next_number) values (?, 2) '
                .'on conflict (tenant_id) do update set next_number = %s.next_number + 1 '
                .'returning next_number - 1 as next_number',
                self::TABLE,
                self::TABLE,
            ),
            [$tenantId->toString()],
        );

        return sprintf('INV-%06d', (int) $row->next_number);
    }
}
