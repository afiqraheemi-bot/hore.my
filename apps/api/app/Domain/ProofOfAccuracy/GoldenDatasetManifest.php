<?php

declare(strict_types=1);

namespace App\Domain\ProofOfAccuracy;

/**
 * A parsed, structurally valid Golden Dataset manifest (AETS-012
 * §5.1) — a stable dataset identity plus every declared artifact's
 * category, path, and expected digest. Structural validity alone is
 * not trust: {@see GoldenDatasetIntegrityVerifier} must still confirm
 * every digest against the real files before this manifest's claims
 * are relied on, and no manifest — however well-formed — makes an
 * accounting oracle approved (AETS-012 §3).
 */
final class GoldenDatasetManifest
{
    /**
     * @param  list<ManifestArtifact>  $artifacts
     */
    public function __construct(
        private readonly string $datasetId,
        private readonly string $version,
        private readonly array $artifacts,
    ) {}

    public function datasetId(): string
    {
        return $this->datasetId;
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * @return list<ManifestArtifact>
     */
    public function artifacts(): array
    {
        return $this->artifacts;
    }

    /**
     * @return list<ManifestArtifact>
     */
    public function artifactsIn(ManifestArtifactCategory $category): array
    {
        return array_values(array_filter(
            $this->artifacts,
            static fn (ManifestArtifact $artifact): bool => $artifact->category() === $category,
        ));
    }

    public function hasAnyIn(ManifestArtifactCategory $category): bool
    {
        return $this->artifactsIn($category) !== [];
    }

    /**
     * A stable digest of the manifest's own declared content — every
     * artifact's category, path, and digest, in manifest order —
     * suitable for a {@see CertificationRecord}
     * to cite so the record identifies exactly which manifest state it
     * certified against (AETS-012 §8's "dataset version/manifest
     * digest").
     */
    public function contentDigest(): string
    {
        $canonical = array_map(
            static fn (ManifestArtifact $artifact): string => sprintf(
                '%s:%s:%s',
                $artifact->category()->name,
                $artifact->relativePath(),
                $artifact->sha256Digest(),
            ),
            $this->artifacts,
        );

        return hash('sha256', $this->datasetId.':'.$this->version.':'.implode('|', $canonical));
    }
}
