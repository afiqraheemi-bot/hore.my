<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Banking\Exception\MalformedBankStatementException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Parses a raw XLSX bank statement file into a list of
 * {@see BankStatementRow} (Import & Export, AETS-008 §5.1) — the
 * `XlsxBankStatementParser` {@see CsvBankStatementParser}'s own
 * docblock had long named as a hypothetical future extension point,
 * now built. Reads the workbook's first (active) worksheet only,
 * extracting each cell's own raw value into a plain string before
 * delegating to {@see BankStatementRowParser}, shared with
 * {@see CsvBankStatementParser} so the two formats can never validate
 * a statement differently.
 *
 * **Normalizes two cell-type quirks Excel/LibreOffice introduce that a
 * plain-text CSV cannot:**
 *
 * 1. A date cell may be stored as an Excel serial-date number, not the
 *    literal string `"2026-09-16"` — detected via
 *    {@see ExcelDate::isDateTime()} and converted to the identical
 *    `Y-m-d` string {@see BankStatementRowParser} already expects,
 *    before that shared parser ever sees it.
 * 2. An amount/balance cell may be stored as a native numeric value
 *    (e.g. `88.5`), not a string — reformatted to the Tenant
 *    Currency's own exact scale (`number_format`) so it satisfies
 *    {@see Money::fromDecimalString()}'s
 *    strict two-decimal grammar exactly as a well-formed CSV cell
 *    already would.
 *
 * Every other cell (description, direction, reference) is read as a
 * plain trimmed string, identically to the CSV parser's own treatment.
 *
 * **Never touches the database.** Pure, storage-free parsing —
 * {@see BankStatementImportService} is the only caller.
 */
final class XlsxBankStatementParser
{
    private readonly BankStatementRowParser $rowParser;

    public function __construct()
    {
        $this->rowParser = new BankStatementRowParser;
    }

    /**
     * @return list<BankStatementRow>
     *
     * @throws MalformedBankStatementException if the file cannot be
     *                                         read as a workbook, is empty, the header row does not
     *                                         match, or any data row fails to parse.
     */
    public function parse(string $xlsxContent, Currency $currency): array
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'xlsx-bank-statement-');

        if ($tempPath === false) {
            throw new \RuntimeException('Failed to allocate a temporary file for XLSX bank statement parsing.');
        }

        file_put_contents($tempPath, $xlsxContent);

        try {
            $sheet = IOFactory::load($tempPath)->getActiveSheet();
        } catch (\Throwable) {
            throw MalformedBankStatementException::forEmptyFile();
        } finally {
            @unlink($tempPath);
        }

        $highestRow = $sheet->getHighestDataRow();

        if ($highestRow < 1) {
            throw MalformedBankStatementException::forEmptyFile();
        }

        $headerCells = $this->rowValues($sheet, 1);
        $normalizedHeader = array_map(static fn (string $column): string => strtolower(trim($column)), $headerCells);
        $this->rowParser->validateHeader($normalizedHeader);

        $rows = [];

        for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
            $columns = $this->rowValues($sheet, $rowNumber);

            if (array_filter($columns, static fn (string $value): bool => $value !== '') === []) {
                continue; // A wholly blank row — mirrors the CSV parser's own blank-line skip.
            }

            $rows[] = $this->rowParser->parseRow($rowNumber, $this->normalizeRow($columns, $sheet, $rowNumber, $currency), $currency);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function rowValues(Worksheet $sheet, int $rowNumber): array
    {
        $values = [];

        for ($column = 1; $column <= count(BankStatementRowParser::EXPECTED_HEADER); $column++) {
            $cell = $sheet->getCell([$column, $rowNumber]);
            $value = $cell->getValue();
            $values[] = trim(is_scalar($value) ? (string) $value : '');
        }

        return $values;
    }

    /**
     * Re-derives the `date` (column 1) and `amount`/`balance`
     * (columns 3/5) cell values from their own native spreadsheet
     * type when the plain string already extracted by
     * {@see rowValues()} would not satisfy
     * {@see BankStatementRowParser}'s strict grammar — see this
     * class's own docblock for why.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function normalizeRow(array $columns, Worksheet $sheet, int $rowNumber, Currency $currency): array
    {
        $dateCell = $sheet->getCell([1, $rowNumber]);
        $dateCellValue = $dateCell->getValue();
        if ((is_int($dateCellValue) || is_float($dateCellValue)) && ExcelDate::isDateTime($dateCell)) {
            $columns[0] = ExcelDate::excelToDateTimeObject($dateCellValue)->format('Y-m-d');
        }

        foreach ([3 => 2, 5 => 4] as $column => $index) {
            $cell = $sheet->getCell([$column, $rowNumber]);
            $rawValue = $cell->getValue();

            if (is_int($rawValue) || is_float($rawValue)) {
                $columns[$index] = number_format((float) $rawValue, $currency->scale(), '.', '');
            }
        }

        return array_values($columns);
    }
}
