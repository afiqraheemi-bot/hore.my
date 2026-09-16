<?php

declare(strict_types=1);

namespace App\Infrastructure\Evidence;

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Evidence\Evidence;
use App\Domain\Evidence\EvidenceId;
use App\Domain\Shared\Tenancy\TenantId;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the Evidence aggregate (AETS-015 §4),
 * through the production `evidence` table.
 */
final class EvidenceRepository
{
    private const TABLE = 'evidence';

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function record(Evidence $evidence): void
    {
        $this->connection->table(self::TABLE)->insert([
            'id' => $evidence->id()->toString(),
            'tenant_id' => $evidence->tenantId()->toString(),
            'original_filename' => $evidence->originalFilename(),
            'mime_type' => $evidence->mimeType(),
            'byte_size' => $evidence->byteSize(),
            'sha256_digest' => $evidence->sha256Digest(),
            'storage_path' => $evidence->storagePath(),
            'uploaded_by' => $evidence->uploadedBy()->toString(),
            'uploaded_at' => $evidence->uploadedAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function findById(TenantId $tenantId, EvidenceId $id): ?Evidence
    {
        /** @var object{id: string, tenant_id: string, original_filename: string, mime_type: string, byte_size: int, sha256_digest: string, storage_path: string, uploaded_by: string, uploaded_at: string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $id->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return Evidence::reconstitute(
            EvidenceId::of($row->id),
            TenantId::of($row->tenant_id),
            $row->original_filename,
            $row->mime_type,
            (int) $row->byte_size,
            $row->sha256_digest,
            $row->storage_path,
            ActorReference::of($row->uploaded_by),
            new \DateTimeImmutable($row->uploaded_at),
        );
    }

    public function delete(TenantId $tenantId, EvidenceId $id): void
    {
        $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('id', $id->toString())
            ->delete();
    }
}
