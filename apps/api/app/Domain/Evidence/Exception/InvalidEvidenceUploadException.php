<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Exception;

use App\Http\Requests\Evidence\UploadEvidenceRequest;

/**
 * Thrown when an Evidence record's own construction-time fields
 * violate AETS-015 §4 — an empty original filename, a byte size that
 * is not strictly positive, or a digest that is not a 64-character
 * hexadecimal SHA-256 value. Distinct from HTTP-layer upload
 * validation (missing file, disallowed MIME type, size ceiling), which
 * {@see UploadEvidenceRequest} rejects
 * before this class is ever reached.
 */
final class InvalidEvidenceUploadException extends \InvalidArgumentException
{
    public static function forEmptyFilename(): self
    {
        return new self('Evidence original filename must not be empty.');
    }

    public static function forNonPositiveByteSize(int $byteSize): self
    {
        return new self(sprintf('Evidence byte size must be strictly positive, got %d.', $byteSize));
    }

    public static function forInvalidDigest(string $digest): self
    {
        return new self(sprintf('"%s" is not a 64-character lowercase hexadecimal SHA-256 digest.', $digest));
    }

    public static function forEmptyMimeType(): self
    {
        return new self('Evidence MIME type must not be empty.');
    }
}
