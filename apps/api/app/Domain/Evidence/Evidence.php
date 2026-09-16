<?php

declare(strict_types=1);

namespace App\Domain\Evidence;

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Banking\BankAccount;
use App\Domain\Evidence\Exception\InvalidEvidenceUploadException;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * The Evidence aggregate root (AETS-015 §4) — a Tenant-owned,
 * immutable record of one uploaded source file (a receipt, invoice,
 * bank statement, or other supporting document). This document's own
 * `EvidenceId` is a valid AETS-010 §8 Evidence Reference.
 *
 * **Reference data, not a transactional aggregate** — mirrors
 * {@see BankAccount} exactly: uploading Evidence
 * produces no Journal and carries no lifecycle event stream.
 *
 * **Immutable, append-only** (`EVI-005`). No field changes after
 * creation; there is no update or delete operation (AETS-015 §2.2,
 * §4).
 */
final class Evidence
{
    private const SHA256_PATTERN = '/^[0-9a-f]{64}$/';

    private function __construct(
        private readonly EvidenceId $id,
        private readonly TenantId $tenantId,
        private readonly string $originalFilename,
        private readonly string $mimeType,
        private readonly int $byteSize,
        private readonly string $sha256Digest,
        private readonly string $storagePath,
        private readonly ActorReference $uploadedBy,
        private readonly \DateTimeImmutable $uploadedAt,
    ) {}

    /**
     * @throws InvalidEvidenceUploadException if `$originalFilename` or
     *                                        `$mimeType` is empty, `$byteSize` is not strictly
     *                                        positive, or `$sha256Digest` is not a 64-character
     *                                        lowercase hexadecimal digest.
     */
    public static function upload(
        EvidenceId $id,
        TenantId $tenantId,
        string $originalFilename,
        string $mimeType,
        int $byteSize,
        string $sha256Digest,
        string $storagePath,
        ActorReference $uploadedBy,
        \DateTimeImmutable $uploadedAt,
    ): self {
        if (trim($originalFilename) === '') {
            throw InvalidEvidenceUploadException::forEmptyFilename();
        }

        if (trim($mimeType) === '') {
            throw InvalidEvidenceUploadException::forEmptyMimeType();
        }

        if ($byteSize <= 0) {
            throw InvalidEvidenceUploadException::forNonPositiveByteSize($byteSize);
        }

        if (preg_match(self::SHA256_PATTERN, $sha256Digest) !== 1) {
            throw InvalidEvidenceUploadException::forInvalidDigest($sha256Digest);
        }

        return new self($id, $tenantId, $originalFilename, $mimeType, $byteSize, $sha256Digest, $storagePath, $uploadedBy, $uploadedAt);
    }

    /**
     * Reconstruct an already-uploaded Evidence record from persisted
     * state — no validation beyond each field's own Value Object,
     * mirroring {@see BankAccount::reconstitute()}'s
     * own reasoning.
     */
    public static function reconstitute(
        EvidenceId $id,
        TenantId $tenantId,
        string $originalFilename,
        string $mimeType,
        int $byteSize,
        string $sha256Digest,
        string $storagePath,
        ActorReference $uploadedBy,
        \DateTimeImmutable $uploadedAt,
    ): self {
        return new self($id, $tenantId, $originalFilename, $mimeType, $byteSize, $sha256Digest, $storagePath, $uploadedBy, $uploadedAt);
    }

    public function id(): EvidenceId
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function originalFilename(): string
    {
        return $this->originalFilename;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function byteSize(): int
    {
        return $this->byteSize;
    }

    public function sha256Digest(): string
    {
        return $this->sha256Digest;
    }

    public function storagePath(): string
    {
        return $this->storagePath;
    }

    public function uploadedBy(): ActorReference
    {
        return $this->uploadedBy;
    }

    public function uploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }
}
