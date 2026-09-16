<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Banking\Exception\MalformedBankStatementException;

/**
 * Parses a raw CSV bank statement file into a list of
 * {@see BankStatementRow} (M17, SRS BNK-001/BNK-003) — extracting raw
 * string cell values from the CSV format only; every actual
 * validation rule (header shape, date/amount/direction grammar) lives
 * in {@see BankStatementRowParser}, shared with
 * {@see XlsxBankStatementParser} (Import & Export, AETS-008 §5.1) so
 * the two formats can never validate a statement differently.
 *
 * **One fixed schema, not configurable column mapping.** SRS BNK-002
 * asks for guided mapping of unrecognized formats — this class does not
 * attempt that yet. It requires the exact header row (case-insensitive,
 * trimmed) {@see BankStatementRowParser::EXPECTED_HEADER} and rejects
 * anything else with {@see MalformedBankStatementException}. A
 * configurable-mapping importer for arbitrary bank export formats is
 * real, separate work, deliberately deferred rather than half-built
 * here — see the M17 closure report for the tracked gap.
 *
 * **Never touches the database.** Pure, storage-free parsing —
 * {@see BankStatementImportService} is the only caller, and the only
 * place persistence happens.
 */
final class CsvBankStatementParser
{
    private readonly BankStatementRowParser $rowParser;

    public function __construct()
    {
        $this->rowParser = new BankStatementRowParser;
    }

    /**
     * @return list<BankStatementRow>
     *
     * @throws MalformedBankStatementException if the file is empty, the
     *                                         header row does not match, or any data row fails to parse.
     */
    public function parse(string $csvContent, Currency $currency): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $csvContent);

        if ($lines === false) {
            throw MalformedBankStatementException::forEmptyFile();
        }

        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        if ($lines === []) {
            throw MalformedBankStatementException::forEmptyFile();
        }

        $header = str_getcsv(array_shift($lines), ',', '"', '');
        $normalizedHeader = array_map(static fn (?string $column): string => strtolower(trim((string) $column)), $header);

        $this->rowParser->validateHeader($normalizedHeader);

        $rows = [];

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 2; // +1 for zero-based index, +1 for the consumed header row.
            $columns = array_map(
                static fn (?string $value): string => trim((string) $value),
                str_getcsv($line, ',', '"', ''),
            );
            $rows[] = $this->rowParser->parseRow($lineNumber, $columns, $currency);
        }

        return $rows;
    }
}
