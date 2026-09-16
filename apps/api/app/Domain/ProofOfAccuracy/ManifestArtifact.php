<?php

declare(strict_types=1);

namespace App\Domain\ProofOfAccuracy;

/**
 * One entry in a Golden Dataset manifest (AETS-012 §5.1): a
 * dataset-relative file path, the category of fact it represents, and
 * the SHA-256 digest {@see GoldenDatasetIntegrityVerifier} must confirm
 * before that file is trusted.
 */
final class ManifestArtifact
{
    private const SHA256_PATTERN = '/^[0-9a-fA-F]{64}$/';

    private function __construct(
        private readonly ManifestArtifactCategory $category,
        private readonly string $relativePath,
        private readonly string $sha256Digest,
    ) {}

    /**
     * @throws \InvalidArgumentException if `$relativePath` is empty, or
     *                                   `$sha256Digest` is not exactly 64 lowercase hexadecimal
     *                                   characters. The caller (typically
     *                                   {@see GoldenDatasetManifestLoader}, which knows this
     *                                   entry's position in the manifest) is responsible for
     *                                   translating this into a manifest-level exception.
     */
    public static function of(ManifestArtifactCategory $category, string $relativePath, string $sha256Digest): self
    {
        if (trim($relativePath) === '') {
            throw new \InvalidArgumentException('path must not be empty.');
        }

        if (preg_match(self::SHA256_PATTERN, $sha256Digest) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a 64-character lowercase hexadecimal SHA-256 digest.', $sha256Digest));
        }

        return new self($category, $relativePath, strtolower($sha256Digest));
    }

    public function category(): ManifestArtifactCategory
    {
        return $this->category;
    }

    public function relativePath(): string
    {
        return $this->relativePath;
    }

    public function sha256Digest(): string
    {
        return $this->sha256Digest;
    }
}
