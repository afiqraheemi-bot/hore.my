<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Banking;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Banking\BankStatementRowParser;
use App\Domain\Banking\BankTransactionDirection;
use App\Domain\Banking\Exception\MalformedBankStatementException;
use App\Domain\Banking\XlsxBankStatementParser;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see XlsxBankStatementParser} (Import & Export, AETS-008
 * §5.1) — the identical fixed v1 schema and malformed-input rejection
 * paths {@see CsvBankStatementParserTest} already proves for CSV
 * (both delegate to the same {@see BankStatementRowParser}),
 * plus the two cell-type normalizations specific to a real spreadsheet
 * (a native Excel date, a native Excel number) that a CSV file's own
 * plain-text cells can never exercise.
 */
final class XlsxBankStatementParserTest extends TestCase
{
    private XlsxBankStatementParser $parser;

    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new XlsxBankStatementParser;
        $this->myr = Currency::of('MYR');
    }

    public function test_parses_a_well_formed_statement(): void
    {
        $xlsx = $this->buildXlsx([
            ['date', 'description', 'amount', 'direction', 'balance', 'reference'],
            ['2026-08-01', 'Salary credit', '3000.00', 'IN', '3000.00', 'REF001'],
            ['2026-08-02', 'Rent payment', '1200.00', 'OUT', '1800.00', 'REF002'],
        ]);

        $rows = $this->parser->parse($xlsx, $this->myr);

        $this->assertCount(2, $rows);
        $this->assertSame('2026-08-01', $rows[0]->transactionDate()->format('Y-m-d'));
        $this->assertSame('Salary credit', $rows[0]->description());
        $this->assertSame('3000.00', $rows[0]->amount()->toDecimalString());
        $this->assertSame(BankTransactionDirection::MoneyIn, $rows[0]->direction());
        $this->assertSame('3000.00', $rows[0]->balance()?->toDecimalString());
        $this->assertSame('REF001', $rows[0]->reference());
        $this->assertSame(BankTransactionDirection::MoneyOut, $rows[1]->direction());
    }

    public function test_direction_is_case_insensitive(): void
    {
        $xlsx = $this->buildXlsx([
            ['date', 'description', 'amount', 'direction', 'balance', 'reference'],
            ['2026-08-01', 'Salary credit', '3000.00', 'in', '', ''],
        ]);

        $rows = $this->parser->parse($xlsx, $this->myr);

        $this->assertSame(BankTransactionDirection::MoneyIn, $rows[0]->direction());
    }

    public function test_header_row_is_case_insensitive(): void
    {
        $xlsx = $this->buildXlsx([
            ['DATE', 'Description', 'AMOUNT', 'Direction', 'Balance', 'REFERENCE'],
            ['2026-08-01', 'Salary credit', '3000.00', 'IN', '', ''],
        ]);

        $rows = $this->parser->parse($xlsx, $this->myr);

        $this->assertCount(1, $rows);
    }

    public function test_empty_balance_and_reference_are_accepted(): void
    {
        $xlsx = $this->buildXlsx([
            ['date', 'description', 'amount', 'direction', 'balance', 'reference'],
            ['2026-08-01', 'Salary credit', '3000.00', 'IN', '', ''],
        ]);

        $rows = $this->parser->parse($xlsx, $this->myr);

        $this->assertNull($rows[0]->balance());
        $this->assertSame('', $rows[0]->reference());
    }

    public function test_a_wholly_blank_row_is_ignored(): void
    {
        $xlsx = $this->buildXlsx([
            ['date', 'description', 'amount', 'direction', 'balance', 'reference'],
            ['', '', '', '', '', ''],
            ['2026-08-01', 'Salary credit', '3000.00', 'IN', '', ''],
        ]);

        $rows = $this->parser->parse($xlsx, $this->myr);

        $this->assertCount(1, $rows);
    }

    public function test_empty_file_is_rejected(): void
    {
        $spreadsheet = new Spreadsheet;
        $empty = $this->writeToBytes($spreadsheet);

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($empty, $this->myr);
    }

    public function test_missing_header_is_rejected(): void
    {
        $xlsx = $this->buildXlsx([
            ['2026-08-01', 'Salary credit', '3000.00', 'IN', '', ''],
        ]);

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($xlsx, $this->myr);
    }

    public function test_wrong_header_order_is_rejected(): void
    {
        $xlsx = $this->buildXlsx([
            ['description', 'date', 'amount', 'direction', 'balance', 'reference'],
            ['Salary credit', '2026-08-01', '3000.00', 'IN', '', ''],
        ]);

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($xlsx, $this->myr);
    }

    public function test_invalid_date_is_rejected(): void
    {
        $xlsx = $this->buildXlsx([
            ['date', 'description', 'amount', 'direction', 'balance', 'reference'],
            ['01/08/2026', 'Salary credit', '3000.00', 'IN', '', ''],
        ]);

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($xlsx, $this->myr);
    }

    public function test_empty_description_is_rejected(): void
    {
        $xlsx = $this->buildXlsx([
            ['date', 'description', 'amount', 'direction', 'balance', 'reference'],
            ['2026-08-01', '', '3000.00', 'IN', '', ''],
        ]);

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($xlsx, $this->myr);
    }

    public function test_malformed_amount_is_rejected(): void
    {
        $xlsx = $this->buildXlsx([
            ['date', 'description', 'amount', 'direction', 'balance', 'reference'],
            ['2026-08-01', 'Salary credit', 'abc', 'IN', '', ''],
        ]);

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($xlsx, $this->myr);
    }

    public function test_invalid_direction_is_rejected(): void
    {
        $xlsx = $this->buildXlsx([
            ['date', 'description', 'amount', 'direction', 'balance', 'reference'],
            ['2026-08-01', 'Salary credit', '100.00', 'SIDEWAYS', '', ''],
        ]);

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($xlsx, $this->myr);
    }

    /**
     * The first cell-type normalization this format needs and CSV
     * never can: a real spreadsheet's date column, entered as an
     * actual Excel date (not typed as text), is stored as a serial
     * number under a date number format — must still resolve to the
     * identical row a `"2026-08-01"` text cell would.
     */
    public function test_a_native_excel_date_cell_is_normalized_correctly(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValueExplicit([1, 1], 'date', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([2, 1], 'description', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([3, 1], 'amount', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([4, 1], 'direction', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([5, 1], 'balance', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([6, 1], 'reference', DataType::TYPE_STRING);

        $sheet->setCellValue([1, 2], Date::PHPToExcel(new \DateTimeImmutable('2026-08-01')));
        $sheet->getStyle([1, 2, 1, 2])->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $sheet->setCellValueExplicit([2, 2], 'Salary credit', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([3, 2], '3000.00', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([4, 2], 'IN', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([5, 2], '', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([6, 2], '', DataType::TYPE_STRING);

        $rows = $this->parser->parse($this->writeToBytes($spreadsheet), $this->myr);

        $this->assertCount(1, $rows);
        $this->assertSame('2026-08-01', $rows[0]->transactionDate()->format('Y-m-d'));
    }

    /**
     * The second cell-type normalization: an amount/balance entered
     * as a native Excel number (e.g. `88.5`, not the text `"88.50"`)
     * must be reformatted to the Currency's own exact two-decimal
     * grammar before it ever reaches
     * {@see Money::fromDecimalString()}.
     */
    public function test_a_native_excel_number_amount_cell_is_normalized_to_the_currencys_exact_scale(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValueExplicit([1, 1], 'date', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([2, 1], 'description', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([3, 1], 'amount', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([4, 1], 'direction', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([5, 1], 'balance', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([6, 1], 'reference', DataType::TYPE_STRING);

        $sheet->setCellValueExplicit([1, 2], '2026-08-01', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([2, 2], 'Salary credit', DataType::TYPE_STRING);
        $sheet->setCellValue([3, 2], 88.5); // native float, not "88.50"
        $sheet->setCellValueExplicit([4, 2], 'IN', DataType::TYPE_STRING);
        $sheet->setCellValue([5, 2], 100); // native int, not "100.00"
        $sheet->setCellValueExplicit([6, 2], '', DataType::TYPE_STRING);

        $rows = $this->parser->parse($this->writeToBytes($spreadsheet), $this->myr);

        $this->assertCount(1, $rows);
        $this->assertSame('88.50', $rows[0]->amount()->toDecimalString());
        $this->assertSame('100.00', $rows[0]->balance()?->toDecimalString());
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function buildXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 1], $value, DataType::TYPE_STRING);
            }
        }

        return $this->writeToBytes($spreadsheet);
    }

    private function writeToBytes(Spreadsheet $spreadsheet): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'xlsx-bank-statement-test-');
        $this->assertNotFalse($tempPath);

        (new Xlsx($spreadsheet))->save($tempPath);
        $bytes = file_get_contents($tempPath);
        unlink($tempPath);
        $this->assertNotFalse($bytes);

        return $bytes;
    }
}
