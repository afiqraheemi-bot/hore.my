<?php

declare(strict_types=1);

namespace App\Domain\ProofOfAccuracy;

use App\Domain\ProofOfAccuracy\Exception\ManifestIntegrityViolationException;

/**
 * Confirms every artifact a {@see GoldenDatasetManifest} declares
 * actually exists, on disk, with exactly the declared SHA-256 digest
 * (AETS-012 §5.1, §6.10, `POA-001`/`POA-002`). This is the only class
 * in this namespace that reads dataset file *content* — the manifest
 * loader only ever reads `manifest.json` itself.
 *
 * **Fails closed, and reports every violation, not just the first**
 * — see {@see ManifestIntegrityViolationException}'s own docblock for
 * why.
 */
final class GoldenDatasetIntegrityVerifier
{
    /**
     * @throws ManifestIntegrityViolationException if any declared
     *                                             artifact is missing from `$datasetBaseDir` or its actual
     *                                             digest does not match the manifest's declared digest.
     */
    public function verify(GoldenDatasetManifest $manifest, string $datasetBaseDir): void
    {
        $violations = [];

        foreach ($manifest->artifacts() as $artifact) {
            $absolutePath = rtrim($datasetBaseDir, '/').'/'.$artifact->relativePath();

            if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
                $violations[] = sprintf('"%s" (%s) does not exist or is not readable.', $artifact->relativePath(), $artifact->category()->name);

                continue;
            }

            $actualDigest = hash_file('sha256', $absolutePath);

            if ($actualDigest === false) {
                $violations[] = sprintf('"%s" (%s) could not be hashed.', $artifact->relativePath(), $artifact->category()->name);

                continue;
            }

            if (! hash_equals($artifact->sha256Digest(), $actualDigest)) {
                $violations[] = sprintf(
                    '"%s" (%s) digest mismatch: manifest declares %s, actual file is %s.',
                    $artifact->relativePath(),
                    $artifact->category()->name,
                    $artifact->sha256Digest(),
                    $actualDigest,
                );
            }
        }

        if ($violations !== []) {
            throw ManifestIntegrityViolationException::forViolations($violations);
        }
    }
}
