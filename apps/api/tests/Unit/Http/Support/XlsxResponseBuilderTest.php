<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Support;

use App\Http\Support\XlsxResponseBuilder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

final class XlsxResponseBuilderTest extends TestCase
{
    public function test_builds_an_xlsx_body_with_header_and_rows(): void
    {
        $response = XlsxResponseBuilder::build(
            'report.xlsx',
            ['Account ID', 'Amount'],
            [
                ['account-0001', '100.00'],
                ['account-0002', '200.00'],
            ],
        );

        $sheet = $this->loadSheetFromResponseBody((string) $response->getContent());

        $this->assertSame('Account ID', $sheet->getCell('A1')->getValue());
        $this->assertSame('Amount', $sheet->getCell('B1')->getValue());
        $this->assertSame('account-0001', $sheet->getCell('A2')->getValue());
        $this->assertSame('100.00', $sheet->getCell('B2')->getValue());
        $this->assertSame('account-0002', $sheet->getCell('A3')->getValue());
        $this->assertSame('200.00', $sheet->getCell('B3')->getValue());
    }

    public function test_sets_xlsx_content_type_and_download_disposition(): void
    {
        $response = XlsxResponseBuilder::build('my-report.xlsx', ['A'], [['1']]);

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="my-report.xlsx"', $response->headers->get('Content-Disposition'));
    }

    public function test_builds_an_xlsx_with_only_a_header_when_there_are_no_rows(): void
    {
        $response = XlsxResponseBuilder::build('empty.xlsx', ['A', 'B'], []);

        $sheet = $this->loadSheetFromResponseBody((string) $response->getContent());

        $this->assertSame('A', $sheet->getCell('A1')->getValue());
        $this->assertSame('B', $sheet->getCell('B1')->getValue());
        $this->assertNull($sheet->getCell('A2')->getValue());
    }

    /**
     * A Money amount rendered as a native Excel number would silently
     * lose an exact decimal representation the moment a real
     * spreadsheet recomputes it — every cell must be a string, byte-
     * identical to CSV's own values, never a float.
     */
    public function test_every_cell_is_written_as_an_exact_string_never_a_native_number(): void
    {
        $response = XlsxResponseBuilder::build('amounts.xlsx', ['Amount'], [['100.50'], ['0.00']]);

        $sheet = $this->loadSheetFromResponseBody((string) $response->getContent());

        $this->assertSame('100.50', $sheet->getCell('A2')->getValue());
        $this->assertIsString($sheet->getCell('A2')->getValue());
        $this->assertSame('0.00', $sheet->getCell('A3')->getValue());
    }

    private function loadSheetFromResponseBody(string $body): Worksheet
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'xlsx-response-builder-test-');
        $this->assertNotFalse($tempPath);
        file_put_contents($tempPath, $body);

        $spreadsheet = IOFactory::load($tempPath);
        unlink($tempPath);

        return $spreadsheet->getActiveSheet();
    }
}
