<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ProofOfAccuracy;

use App\Domain\ProofOfAccuracy\Exception\ManifestIntegrityViolationException;
use App\Domain\ProofOfAccuracy\GoldenDatasetIntegrityVerifier;
use App\Domain\ProofOfAccuracy\GoldenDatasetManifest;
use App\Domain\ProofOfAccuracy\ManifestArtifact;
use App\Domain\ProofOfAccuracy\ManifestArtifactCategory;
use PHPUnit\Framework\TestCase;

final class GoldenDatasetIntegrityVerifierTest extends TestCase
{
    private GoldenDatasetIntegrityVerifier $verifier;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = new GoldenDatasetIntegrityVerifier;
        $this->tempDir = sys_get_temp_dir().'/poa_integrity_test_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);

        parent::tearDown();
    }

    public function test_matching_digests_verify_cleanly(): void
    {
        $content = 'this content is a test fixture, not a real accounting oracle';
        file_put_contents($this->tempDir.'/artifact.txt', $content);

        $manifest = new GoldenDatasetManifest('poa-test-fixture', '1', [
            ManifestArtifact::of(ManifestArtifactCategory::Source, 'artifact.txt', hash('sha256', $content)),
        ]);

        $this->verifier->verify($manifest, $this->tempDir);
        $this->addToAssertionCount(1);
    }

    public function test_a_missing_file_is_a_violation(): void
    {
        $manifest = new GoldenDatasetManifest('poa-test-fixture', '1', [
            ManifestArtifact::of(ManifestArtifactCategory::Source, 'does-not-exist.txt', hash('sha256', 'anything')),
        ]);

        $this->expectException(ManifestIntegrityViolationException::class);

        $this->verifier->verify($manifest, $this->tempDir);
    }

    public function test_a_digest_mismatch_is_a_violation(): void
    {
        file_put_contents($this->tempDir.'/artifact.txt', 'the real content');

        $manifest = new GoldenDatasetManifest('poa-test-fixture', '1', [
            ManifestArtifact::of(ManifestArtifactCategory::Source, 'artifact.txt', hash('sha256', 'a different, tampered-with content')),
        ]);

        $this->expectException(ManifestIntegrityViolationException::class);

        $this->verifier->verify($manifest, $this->tempDir);
    }

    public function test_every_violation_is_reported_not_just_the_first(): void
    {
        file_put_contents($this->tempDir.'/present-but-tampered.txt', 'tampered content');

        $manifest = new GoldenDatasetManifest('poa-test-fixture', '1', [
            ManifestArtifact::of(ManifestArtifactCategory::Source, 'present-but-tampered.txt', hash('sha256', 'original content')),
            ManifestArtifact::of(ManifestArtifactCategory::Expected, 'entirely-missing.txt', hash('sha256', 'anything')),
        ]);

        try {
            $this->verifier->verify($manifest, $this->tempDir);
            $this->fail('Expected a ManifestIntegrityViolationException.');
        } catch (ManifestIntegrityViolationException $e) {
            $this->assertStringContainsString('present-but-tampered.txt', $e->getMessage());
            $this->assertStringContainsString('entirely-missing.txt', $e->getMessage());
        }
    }

    public function test_a_correct_artifact_alongside_a_violating_one_still_fails_the_whole_verification(): void
    {
        $goodContent = 'this one is fine';
        file_put_contents($this->tempDir.'/good.txt', $goodContent);

        $manifest = new GoldenDatasetManifest('poa-test-fixture', '1', [
            ManifestArtifact::of(ManifestArtifactCategory::Source, 'good.txt', hash('sha256', $goodContent)),
            ManifestArtifact::of(ManifestArtifactCategory::Expected, 'missing.txt', hash('sha256', 'anything')),
        ]);

        $this->expectException(ManifestIntegrityViolationException::class);

        $this->verifier->verify($manifest, $this->tempDir);
    }
}
