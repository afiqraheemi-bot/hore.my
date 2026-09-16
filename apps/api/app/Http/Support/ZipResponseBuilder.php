<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Http\Controllers\Api\ReportingController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds a downloadable ZIP `Response` from a set of named entries
 * (AETS-009 §19, Compliance Pack export) — a pure HTTP-layer
 * presentation concern, mirroring {@see CsvResponseBuilder}'s own
 * scoping exactly: it accepts already-computed content (each entry's
 * bytes are supplied by the caller, already fully formed) and knows
 * nothing about Reports, Journals, Money, or any other domain
 * concept — no aggregation, no filtering, no business logic of its
 * own.
 */
final class ZipResponseBuilder
{
    /**
     * The exact bytes of a minimal, valid, empty ZIP archive (a bare
     * End Of Central Directory record, no entries) — `ZipArchive`
     * itself refuses to persist an archive with zero entries: calling
     * `close()` on one deletes the temporary file it was writing to
     * rather than leaving behind a valid empty archive (confirmed
     * directly against this image's libzip). `$entries` is empty only
     * in a degenerate caller case (no report data at all), never in
     * {@see ReportingController::compliancePack()}'s
     * own normal five-entry bundle — but this class has no way to
     * know that, so it handles the case correctly rather than letting
     * a caller's edge case throw.
     */
    private const EMPTY_ZIP_BYTES = "PK\x05\x06\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

    /**
     * @param  array<string, string>  $entries  entry filename (inside
     *                                          the archive) => raw file content.
     */
    public static function build(string $filename, array $entries): Response
    {
        if ($entries === []) {
            return new Response(self::EMPTY_ZIP_BYTES, 200, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            ]);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'compliance-pack-');

        if ($tempPath === false) {
            throw new \RuntimeException('Failed to allocate a temporary file for ZIP export.');
        }

        $zip = new \ZipArchive;
        $openResult = $zip->open($tempPath, \ZipArchive::OVERWRITE);

        if ($openResult !== true) {
            @unlink($tempPath);

            throw new \RuntimeException(sprintf('Failed to open a temporary ZIP archive for export (code %d).', $openResult));
        }

        foreach ($entries as $entryName => $content) {
            $zip->addFromString($entryName, $content);
        }

        $zip->close();

        $bytes = file_get_contents($tempPath);
        @unlink($tempPath);

        if ($bytes === false) {
            throw new \RuntimeException('Failed to read the generated ZIP export back from its temporary file.');
        }

        return new Response($bytes, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }
}
