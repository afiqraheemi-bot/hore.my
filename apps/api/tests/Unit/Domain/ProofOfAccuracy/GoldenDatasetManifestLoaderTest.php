<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ProofOfAccuracy;

use App\Domain\ProofOfAccuracy\Exception\InvalidManifestException;
use App\Domain\ProofOfAccuracy\GoldenDatasetManifest;
use App\Domain\ProofOfAccuracy\GoldenDatasetManifestLoader;
use App\Domain\ProofOfAccuracy\ManifestArtifactCategory;
use PHPUnit\Framework\TestCase;

final class GoldenDatasetManifestLoaderTest extends TestCase
{
    private GoldenDatasetManifestLoader $loader;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = new GoldenDatasetManifestLoader;
        $this->tempDir = sys_get_temp_dir().'/poa_manifest_test_'.bin2hex(random_bytes(8));
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

    public function test_a_well_formed_manifest_is_loaded(): void
    {
        $digest = hash('sha256', 'source-fixture');
        $manifest = $this->writeAndLoad([
            'dataset_id' => 'poa-test-fixture',
            'version' => '1',
            'artifacts' => [
                ['category' => 'source', 'path' => 'sources/receipt.pdf', 'sha256' => $digest],
                ['category' => 'expected', 'path' => 'expected/results.json', 'sha256' => $digest],
            ],
        ]);

        $this->assertSame('poa-test-fixture', $manifest->datasetId());
        $this->assertSame('1', $manifest->version());
        $this->assertCount(2, $manifest->artifacts());
        $this->assertTrue($manifest->hasAnyIn(ManifestArtifactCategory::Source));
        $this->assertTrue($manifest->hasAnyIn(ManifestArtifactCategory::Expected));
        $this->assertFalse($manifest->hasAnyIn(ManifestArtifactCategory::Canonical));
    }

    public function test_a_missing_file_is_rejected(): void
    {
        $this->expectException(InvalidManifestException::class);

        $this->loader->loadFromFile($this->tempDir.'/does-not-exist.json');
    }

    public function test_malformed_json_is_rejected(): void
    {
        file_put_contents($this->tempDir.'/manifest.json', '{not valid json');

        $this->expectException(InvalidManifestException::class);

        $this->loader->loadFromFile($this->tempDir.'/manifest.json');
    }

    public function test_a_missing_required_field_is_rejected(): void
    {
        $this->expectException(InvalidManifestException::class);

        $this->writeAndLoad(['version' => '1', 'artifacts' => []]);
    }

    public function test_an_empty_artifact_list_is_rejected(): void
    {
        $this->expectException(InvalidManifestException::class);

        $this->writeAndLoad(['dataset_id' => 'poa-test-fixture', 'version' => '1', 'artifacts' => []]);
    }

    public function test_an_unrecognized_category_is_rejected(): void
    {
        $this->expectException(InvalidManifestException::class);

        $this->writeAndLoad([
            'dataset_id' => 'poa-test-fixture',
            'version' => '1',
            'artifacts' => [
                ['category' => 'not-a-real-category', 'path' => 'x', 'sha256' => hash('sha256', 'x')],
            ],
        ]);
    }

    public function test_an_invalid_digest_is_rejected(): void
    {
        $this->expectException(InvalidManifestException::class);

        $this->writeAndLoad([
            'dataset_id' => 'poa-test-fixture',
            'version' => '1',
            'artifacts' => [
                ['category' => 'source', 'path' => 'x', 'sha256' => 'not-a-digest'],
            ],
        ]);
    }

    public function test_a_duplicate_path_is_rejected(): void
    {
        $digest = hash('sha256', 'x');

        $this->expectException(InvalidManifestException::class);

        $this->writeAndLoad([
            'dataset_id' => 'poa-test-fixture',
            'version' => '1',
            'artifacts' => [
                ['category' => 'source', 'path' => 'sources/x', 'sha256' => $digest],
                ['category' => 'expected', 'path' => 'sources/x', 'sha256' => $digest],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function writeAndLoad(array $decoded): GoldenDatasetManifest
    {
        $path = $this->tempDir.'/manifest.json';
        file_put_contents($path, json_encode($decoded, JSON_THROW_ON_ERROR));

        return $this->loader->loadFromFile($path);
    }
}
