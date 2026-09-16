<?php

declare(strict_types=1);

namespace App\Domain\ProofOfAccuracy\Exception;

/**
 * Thrown when a manifest.json cannot be parsed, or is missing a
 * required field, or declares a structurally invalid artifact entry
 * (AETS-012 §5.1, `POA-001`) — this is a defect in the manifest file
 * itself, distinct from {@see ManifestIntegrityViolationException},
 * which is a mismatch between an otherwise well-formed manifest and
 * the actual files on disk.
 */
final class InvalidManifestException extends \RuntimeException
{
    public static function forMissingFile(string $path): self
    {
        return new self(sprintf('Manifest file "%s" does not exist or is not readable.', $path));
    }

    public static function forMalformedJson(string $path, string $jsonError): self
    {
        return new self(sprintf('Manifest file "%s" is not valid JSON: %s', $path, $jsonError));
    }

    public static function forMissingField(string $field): self
    {
        return new self(sprintf('Manifest is missing required field "%s".', $field));
    }

    public static function forEmptyArtifactList(): self
    {
        return new self('Manifest declares no artifacts at all; a Golden Dataset manifest with zero artifacts can never satisfy AETS-012 §5.2\'s connected coverage.');
    }

    public static function forInvalidArtifactEntry(int $index, string $reason): self
    {
        return new self(sprintf('Manifest artifact entry #%d is invalid: %s', $index, $reason));
    }

    public static function forDuplicatePath(string $path): self
    {
        return new self(sprintf('Manifest declares artifact path "%s" more than once.', $path));
    }
}
