<?php

declare(strict_types=1);

namespace App\Http\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds a downloadable XLSX `Response` from a header row and a list
 * of data rows (AETS-009 §20, resolving the XLSX half of SRS
 * RPT-009's own "Eksport PDF, XLSX dan CSV" deferral for every report
 * that already offers `?format=csv`) — a pure HTTP-layer presentation
 * concern, mirroring {@see CsvResponseBuilder}'s own scoping exactly:
 * it accepts already-computed report data and performs no
 * aggregation, filtering, or business logic of its own — only cell
 * formatting via PhpSpreadsheet.
 *
 * **Every cell is written as a string, never a native spreadsheet
 * number.** A Money amount rendered as an Excel numeric cell risks
 * silent floating-point misrepresentation the moment a user's own
 * spreadsheet software recomputes it — writing the identical exact
 * decimal string {@see CsvResponseBuilder} already uses keeps this
 * format byte-for-byte consistent with CSV's own values, never a
 * second, divergent representation of the same report.
 */
final class XlsxResponseBuilder
{
    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     */
    public static function build(string $filename, array $header, array $rows): Response
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($header as $columnIndex => $value) {
            $sheet->setCellValueExplicit([$columnIndex + 1, 1], $value, DataType::TYPE_STRING);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 2], $value, DataType::TYPE_STRING);
            }
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'xlsx-response-');

        if ($tempPath === false) {
            throw new \RuntimeException('Failed to allocate a temporary file for XLSX export.');
        }

        (new Xlsx($spreadsheet))->save($tempPath);

        $bytes = file_get_contents($tempPath);
        @unlink($tempPath);
        $spreadsheet->disconnectWorksheets();

        if ($bytes === false) {
            throw new \RuntimeException('Failed to read the generated XLSX export back from its temporary file.');
        }

        return new Response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }
}
