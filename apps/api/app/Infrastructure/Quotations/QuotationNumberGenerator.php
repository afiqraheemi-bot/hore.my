<?php

declare(strict_types=1);

namespace App\Infrastructure\Quotations;

use App\Domain\Quotations\QuotationConversionService;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Invoicing\InvoiceNumberGenerator;
use Illuminate\Database\ConnectionInterface;

/**
 * Assigns each Tenant its own sequential Quotation number (AETS-016),
 * backed by the `quotation_number_sequences` table — one row per
 * Tenant. Mirrors
 * {@see InvoiceNumberGenerator} exactly,
 * including its own atomic upsert technique and gap-tolerance
 * reasoning; unlike an Invoice number, a Quotation number has no
 * MyInvois consequence at all (AETS-016 §2.2), but the identical
 * scheme is kept for consistency and because {@see
 * QuotationConversionService} never needs to reconcile the two
 * numbering spaces.
 *
 * **Format: `QUO-000001`, zero-padded to 6 digits.**
 */
final class QuotationNumberGenerator
{
    private const TABLE = 'quotation_number_sequences';

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

        return sprintf('QUO-%06d', (int) $row->next_number);
    }
}
