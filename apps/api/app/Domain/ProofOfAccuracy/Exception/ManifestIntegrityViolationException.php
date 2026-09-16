<?php

declare(strict_types=1);

namespace App\Domain\ProofOfAccuracy\Exception;

/**
 * Thrown when a well-formed manifest's declared digest does not match
 * an artifact's actual file content, or the artifact is missing from
 * disk entirely (AETS-012 §5.1/§6.10, `POA-001`/`POA-002`). Collects
 * every violation found in one verification pass rather than only the
 * first, since AETS-012 §6.10 requires failing closed for *any*
 * missing artifact or invalid digest — a caller fixing one violation
 * at a time from a single-violation error would otherwise have to
 * re-run verification repeatedly to discover the rest.
 */
final class ManifestIntegrityViolationException extends \RuntimeException
{
    /**
     * @param  list<string>  $violations
     */
    public static function forViolations(array $violations): self
    {
        return new self(sprintf(
            "Golden Dataset manifest integrity verification failed with %d violation(s):\n%s",
            count($violations),
            implode("\n", array_map(static fn (string $v): string => '  - '.$v, $violations)),
        ));
    }
}
