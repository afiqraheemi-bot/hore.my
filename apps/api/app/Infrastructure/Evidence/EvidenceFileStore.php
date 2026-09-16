<?php

declare(strict_types=1);

namespace App\Infrastructure\Evidence;

use App\Domain\Evidence\EvidenceId;
use App\Domain\Shared\Tenancy\TenantId;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * The physical storage boundary for an Evidence file (AETS-015 §5) —
 * the only class that writes to, reads from, or deletes on the
 * private filesystem disk. A storage path is always
 * `evidence/<tenant-id>/<evidence-id>`, opaque and never derived from
 * the caller-supplied original filename (`EVI-003`) — collision and
 * path-traversal safety, since neither `$tenantId` nor `$evidenceId`
 * is ever caller-supplied free text (both are already-validated Value
 * Objects by the time this class sees them).
 */
final class EvidenceFileStore
{
    public function __construct(
        private readonly Filesystem $disk,
    ) {}

    public function store(TenantId $tenantId, EvidenceId $evidenceId, string $contents): string
    {
        $path = self::pathFor($tenantId, $evidenceId);

        $this->disk->put($path, $contents);

        return $path;
    }

    public function read(string $storagePath): string
    {
        $contents = $this->disk->get($storagePath);

        if ($contents === null) {
            throw new \RuntimeException(sprintf('Evidence file at "%s" is missing from storage.', $storagePath));
        }

        return $contents;
    }

    public function delete(string $storagePath): void
    {
        $this->disk->delete($storagePath);
    }

    private static function pathFor(TenantId $tenantId, EvidenceId $evidenceId): string
    {
        return sprintf('evidence/%s/%s', $tenantId->toString(), $evidenceId->toString());
    }
}
