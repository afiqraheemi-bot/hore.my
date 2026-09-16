<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ProofOfAccuracy;

use App\Domain\ProofOfAccuracy\ManifestArtifact;
use App\Domain\ProofOfAccuracy\ManifestArtifactCategory;
use PHPUnit\Framework\TestCase;

final class ManifestArtifactTest extends TestCase
{
    private function validDigest(): string
    {
        return hash('sha256', 'manifest-artifact-test-fixture');
    }

    public function test_a_valid_artifact_is_constructed(): void
    {
        $digest = $this->validDigest();
        $artifact = ManifestArtifact::of(ManifestArtifactCategory::Source, 'sources/receipt.pdf', $digest);

        $this->assertSame(ManifestArtifactCategory::Source, $artifact->category());
        $this->assertSame('sources/receipt.pdf', $artifact->relativePath());
        $this->assertSame($digest, $artifact->sha256Digest());
    }

    public function test_the_digest_is_normalized_to_lowercase(): void
    {
        $digest = $this->validDigest();
        $artifact = ManifestArtifact::of(ManifestArtifactCategory::Source, 'sources/receipt.pdf', strtoupper($digest));

        $this->assertSame($digest, $artifact->sha256Digest());
    }

    public function test_an_empty_path_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ManifestArtifact::of(ManifestArtifactCategory::Source, '   ', $this->validDigest());
    }

    public function test_a_digest_that_is_not_64_hex_characters_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ManifestArtifact::of(ManifestArtifactCategory::Source, 'sources/receipt.pdf', 'not-a-real-digest');
    }

    public function test_a_digest_containing_non_hex_characters_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ManifestArtifact::of(ManifestArtifactCategory::Source, 'sources/receipt.pdf', str_repeat('g', 64));
    }
}
