<?php

declare(strict_types=1);

namespace App\Http\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * Builds a downloadable CSV `Response` from a header row and a list of
 * data rows (M23, Hasil MVP item 8: "Eksport PDF, XLSX dan CSV") — a
 * pure HTTP-layer presentation concern, never a Domain/Query one: it
 * accepts already-computed report data (each Reporting Query class's
 * own return value, reshaped into flat rows by its own controller
 * method) and knows nothing about Journals, Money, or any other
 * Accounting Core concept.
 *
 * **Not this class's job:** the report data itself is already fully
 * computed and validated by the same Query classes the JSON response
 * path uses — this class performs no aggregation, no filtering, and no
 * business logic of its own, only string formatting via PHP's built-in
 * `fputcsv` (never a hand-rolled CSV escaper, to avoid the well-known
 * class of injection/quoting bugs a manual implementation invites).
 */
final class CsvResponseBuilder
{
    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     */
    public static function build(string $filename, array $header, array $rows): Response
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new \RuntimeException('Failed to open a temporary stream for CSV export.');
        }

        fputcsv($stream, $header, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new \RuntimeException('Failed to read the generated CSV export back from its temporary stream.');
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }
}
