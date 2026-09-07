<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

use App\Domain\Banking\CsvBankStatementParser;

/**
 * Thrown when an uploaded file does not conform to
 * {@see CsvBankStatementParser}'s single fixed v1 schema (M17) — a
 * missing/incorrect header row, a row with the wrong column count, or a
 * cell that fails its own column's format (date, amount, direction).
 *
 * **Fails the whole import, never partially.** A statement that is
 * malformed partway through is rejected in full, before any row is
 * persisted — there is no "import what parsed, skip the rest" mode.
 * Silently skipping unparseable rows would defeat SRS BNK-003's own
 * normalization guarantee by letting a bank statement disagree with
 * what hore.my actually recorded from it.
 */
final class MalformedBankStatementException extends \RuntimeException
{
    public static function forMissingOrIncorrectHeader(string $expectedHeader): self
    {
        return new self(sprintf(
            'The uploaded file\'s header row does not match the required format "%s".',
            $expectedHeader,
        ));
    }

    public static function forRow(int $lineNumber, string $reason): self
    {
        return new self(sprintf('Row %d is malformed: %s', $lineNumber, $reason));
    }

    public static function forEmptyFile(): self
    {
        return new self('The uploaded file is empty.');
    }
}
