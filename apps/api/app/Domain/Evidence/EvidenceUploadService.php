<?php

declare(strict_types=1);

namespace App\Domain\Evidence;

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Evidence\Exception\InvalidEvidenceUploadException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Evidence\EvidenceFileStore;
use App\Infrastructure\Evidence\EvidenceRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * The application service that makes an Evidence upload real
 * (AETS-015 §5) — the only class that writes an Evidence file to disk
 * or its persisted record; {@see EvidenceFileStore} and
 * {@see EvidenceRepository} are its own collaborators, never called
 * directly by an HTTP controller.
 *
 * **Atomicity across two systems (`EVI-001`).** The file write and the
 * database insert cannot share one PostgreSQL transaction — the
 * filesystem is not transactional. The file is written first; if the
 * database insert then fails, the just-written file is deleted before
 * the exception propagates, so a failure never leaves an Evidence
 * record with no file, and a persistence failure never leaves an
 * orphaned file with no record to name it. (A process crash in the
 * narrow window between a successful write and a failed insert's
 * cleanup is an accepted, unsolved edge case shared by every
 * non-transactional-filesystem-plus-database design; this service does
 * not claim to close it.)
 */
final class EvidenceUploadService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly EvidenceFileStore $fileStore,
        private readonly EvidenceRepository $evidenceRepository,
    ) {}

    /**
     * @throws InvalidEvidenceUploadException
     */
    public function upload(TenantId $tenantId, string $originalFilename, string $mimeType, string $contents, ActorReference $actor): Evidence
    {
        $evidenceId = EvidenceId::of((string) Str::uuid());
        $digest = hash('sha256', $contents);
        $byteSize = strlen($contents);

        $storagePath = $this->fileStore->store($tenantId, $evidenceId, $contents);

        $evidence = Evidence::upload(
            $evidenceId,
            $tenantId,
            $originalFilename,
            $mimeType,
            $byteSize,
            $digest,
            $storagePath,
            $actor,
            new \DateTimeImmutable,
        );

        try {
            $this->connection->transaction(function () use ($evidence): void {
                $this->evidenceRepository->record($evidence);
            });
        } catch (\Throwable $e) {
            $this->fileStore->delete($storagePath);

            throw $e;
        }

        return $evidence;
    }
}
