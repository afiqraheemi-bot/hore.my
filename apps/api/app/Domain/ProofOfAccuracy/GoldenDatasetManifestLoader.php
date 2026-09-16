<?php

declare(strict_types=1);

namespace App\Domain\ProofOfAccuracy;

use App\Domain\ProofOfAccuracy\Exception\InvalidManifestException;

/**
 * Parses one `manifest.json` file into a {@see GoldenDatasetManifest}
 * (AETS-012 §5.1). Pure, storage-free parsing plus structural
 * validation only — never touches whether the referenced files
 * actually exist or match their declared digests
 * ({@see GoldenDatasetIntegrityVerifier}'s job), and never decides
 * whether a dataset's *content* is an approved accounting oracle
 * (AETS-012 §3 — that is a human review outcome this class cannot
 * produce).
 */
final class GoldenDatasetManifestLoader
{
    /**
     * @throws InvalidManifestException if the file is missing, is not
     *                                  valid JSON, is missing a required field, declares zero
     *                                  artifacts, declares an artifact with an invalid category,
     *                                  path, or digest, or declares the same path more than once.
     */
    public function loadFromFile(string $manifestPath): GoldenDatasetManifest
    {
        if (! is_file($manifestPath) || ! is_readable($manifestPath)) {
            throw InvalidManifestException::forMissingFile($manifestPath);
        }

        $contents = file_get_contents($manifestPath);

        if ($contents === false) {
            throw InvalidManifestException::forMissingFile($manifestPath);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw InvalidManifestException::forMalformedJson($manifestPath, $e->getMessage());
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw InvalidManifestException::forMalformedJson($manifestPath, 'top-level JSON value must be an object.');
        }

        /** @var array<string, mixed> $decoded */
        return $this->fromDecoded($decoded);
    }

    /**
     * @param  array<string, mixed>  $decoded
     *
     * @throws InvalidManifestException
     */
    private function fromDecoded(array $decoded): GoldenDatasetManifest
    {
        foreach (['dataset_id', 'version', 'artifacts'] as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw InvalidManifestException::forMissingField($field);
            }
        }

        if (! is_string($decoded['dataset_id']) || trim($decoded['dataset_id']) === '') {
            throw InvalidManifestException::forMissingField('dataset_id');
        }

        if (! is_string($decoded['version']) || trim($decoded['version']) === '') {
            throw InvalidManifestException::forMissingField('version');
        }

        if (! is_array($decoded['artifacts']) || $decoded['artifacts'] === []) {
            throw InvalidManifestException::forEmptyArtifactList();
        }

        $artifacts = [];
        $seenPaths = [];

        foreach (array_values($decoded['artifacts']) as $index => $entry) {
            $artifact = $this->artifactFromEntry($index, $entry);

            if (in_array($artifact->relativePath(), $seenPaths, true)) {
                throw InvalidManifestException::forDuplicatePath($artifact->relativePath());
            }

            $seenPaths[] = $artifact->relativePath();
            $artifacts[] = $artifact;
        }

        return new GoldenDatasetManifest($decoded['dataset_id'], $decoded['version'], $artifacts);
    }

    private function artifactFromEntry(int $index, mixed $entry): ManifestArtifact
    {
        if (! is_array($entry)) {
            throw InvalidManifestException::forInvalidArtifactEntry($index, 'must be an object.');
        }

        foreach (['category', 'path', 'sha256'] as $field) {
            if (! array_key_exists($field, $entry) || ! is_string($entry[$field])) {
                throw InvalidManifestException::forInvalidArtifactEntry($index, sprintf('missing or non-string field "%s".', $field));
            }
        }

        $category = $this->categoryFromString($index, $entry['category']);

        try {
            return ManifestArtifact::of($category, $entry['path'], $entry['sha256']);
        } catch (\InvalidArgumentException $e) {
            throw InvalidManifestException::forInvalidArtifactEntry($index, $e->getMessage());
        }
    }

    private function categoryFromString(int $index, string $value): ManifestArtifactCategory
    {
        foreach (ManifestArtifactCategory::cases() as $case) {
            if (strcasecmp($case->name, $value) === 0) {
                return $case;
            }
        }

        throw InvalidManifestException::forInvalidArtifactEntry($index, sprintf(
            '"%s" is not a recognized category (expected one of: %s).',
            $value,
            implode(', ', array_map(static fn (ManifestArtifactCategory $c): string => $c->name, ManifestArtifactCategory::cases())),
        ));
    }
}
