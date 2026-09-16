<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Evidence;

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Evidence\Evidence;
use App\Domain\Evidence\EvidenceId;
use App\Domain\Evidence\Exception\InvalidEvidenceUploadException;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;

final class EvidenceTest extends TestCase
{
    public function test_a_valid_evidence_record_is_constructed(): void
    {
        $uploadedAt = new \DateTimeImmutable('2026-09-16T03:00:00Z');
        $digest = hash('sha256', 'receipt-fixture');

        $evidence = Evidence::upload(
            EvidenceId::of('evidence-0001'),
            TenantId::of('tenant-0001'),
            'receipt.jpg',
            'image/jpeg',
            12345,
            $digest,
            'evidence/tenant-0001/evidence-0001',
            ActorReference::of('actor-0001'),
            $uploadedAt,
        );

        $this->assertSame('receipt.jpg', $evidence->originalFilename());
        $this->assertSame('image/jpeg', $evidence->mimeType());
        $this->assertSame(12345, $evidence->byteSize());
        $this->assertSame($digest, $evidence->sha256Digest());
        $this->assertSame('evidence/tenant-0001/evidence-0001', $evidence->storagePath());
        $this->assertSame($uploadedAt, $evidence->uploadedAt());
    }

    public function test_an_empty_filename_is_rejected(): void
    {
        $this->expectException(InvalidEvidenceUploadException::class);

        Evidence::upload(
            EvidenceId::of('evidence-0001'),
            TenantId::of('tenant-0001'),
            '',
            'image/jpeg',
            1,
            hash('sha256', 'x'),
            'evidence/tenant-0001/evidence-0001',
            ActorReference::of('actor-0001'),
            new \DateTimeImmutable,
        );
    }

    public function test_an_empty_mime_type_is_rejected(): void
    {
        $this->expectException(InvalidEvidenceUploadException::class);

        Evidence::upload(
            EvidenceId::of('evidence-0001'),
            TenantId::of('tenant-0001'),
            'receipt.jpg',
            '',
            1,
            hash('sha256', 'x'),
            'evidence/tenant-0001/evidence-0001',
            ActorReference::of('actor-0001'),
            new \DateTimeImmutable,
        );
    }

    public function test_a_zero_byte_size_is_rejected(): void
    {
        $this->expectException(InvalidEvidenceUploadException::class);

        Evidence::upload(
            EvidenceId::of('evidence-0001'),
            TenantId::of('tenant-0001'),
            'receipt.jpg',
            'image/jpeg',
            0,
            hash('sha256', 'x'),
            'evidence/tenant-0001/evidence-0001',
            ActorReference::of('actor-0001'),
            new \DateTimeImmutable,
        );
    }

    public function test_a_negative_byte_size_is_rejected(): void
    {
        $this->expectException(InvalidEvidenceUploadException::class);

        Evidence::upload(
            EvidenceId::of('evidence-0001'),
            TenantId::of('tenant-0001'),
            'receipt.jpg',
            'image/jpeg',
            -1,
            hash('sha256', 'x'),
            'evidence/tenant-0001/evidence-0001',
            ActorReference::of('actor-0001'),
            new \DateTimeImmutable,
        );
    }

    public function test_a_malformed_digest_is_rejected(): void
    {
        $this->expectException(InvalidEvidenceUploadException::class);

        Evidence::upload(
            EvidenceId::of('evidence-0001'),
            TenantId::of('tenant-0001'),
            'receipt.jpg',
            'image/jpeg',
            1,
            'not-a-real-digest',
            'evidence/tenant-0001/evidence-0001',
            ActorReference::of('actor-0001'),
            new \DateTimeImmutable,
        );
    }

    public function test_equals_compares_by_id_only(): void
    {
        $digest = hash('sha256', 'x');
        $a = Evidence::reconstitute(EvidenceId::of('evidence-0001'), TenantId::of('tenant-0001'), 'a.jpg', 'image/jpeg', 1, $digest, 'p', ActorReference::of('actor-0001'), new \DateTimeImmutable);
        $b = Evidence::reconstitute(EvidenceId::of('evidence-0001'), TenantId::of('tenant-0002'), 'b.png', 'image/png', 2, $digest, 'q', ActorReference::of('actor-0002'), new \DateTimeImmutable('-1 day'));

        $this->assertTrue($a->equals($b));
    }
}
