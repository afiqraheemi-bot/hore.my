<?php

declare(strict_types=1);

namespace App\Infrastructure\Banking;

use App\Domain\Banking\Exception\MalformedBankStatementException;
use App\Domain\Banking\MaybankPdfBankStatementParser;
use Symfony\Component\Process\Process;

/**
 * Strips PDF encryption via the `qpdf` system binary before a PDF
 * reaches `smalot/pdfparser` (AETS-008 §12.10) — `smalot/pdfparser`
 * has no decryption support of its own at all (confirmed by reading
 * its source directly: it only checks the xref trailer's own
 * `/Encrypt` key and throws "Secured pdf file are currently not
 * supported" rather than ever attempting to decode an encrypted
 * stream — the exact error a real Maybank statement upload surfaced).
 *
 * **Most real bank statement PDFs are encrypted, but with an *empty*
 * user password.** Permissions-only restrictions (no printing/editing)
 * with no open password is the overwhelmingly common real-world case
 * — such a file opens instantly in any PDF viewer with no password
 * prompt at all. `qpdf --decrypt` strips this with no password
 * argument needed; verified directly against a synthetic PDF encrypted
 * both RC4-128 and AES-256 with an empty user password — `qpdf`
 * decrypts both, and `smalot/pdfparser` then extracts byte-identical
 * text to the original unencrypted source.
 *
 * **Always run, never conditional on smalot's own exception first.**
 * `qpdf --decrypt` against an already-unencrypted PDF is a verified
 * safe no-op passthrough (re-serializes identical content, exit 0) —
 * running it unconditionally is simpler and more robust than
 * string-matching a third-party exception message to decide whether
 * to retry.
 *
 * **A genuinely password-locked PDF (a real, non-empty user password)
 * still fails, honestly.** `qpdf` cannot guess a password it was never
 * given; that case surfaces as
 * {@see MalformedBankStatementException::forPasswordProtectedPdf()},
 * distinct from every other read failure, so the user gets actionable
 * guidance rather than the opaque original error.
 *
 * **Symfony `Process` directly, not the Laravel `Process` facade** —
 * mirrors {@see MaybankPdfBankStatementParser}'s
 * own zero-framework-bootstrap design (directly `new`-able and
 * covered by plain `PHPUnit\Framework\TestCase` unit tests, never
 * `Tests\TestCase`); the facade throws "A facade root has not been
 * set" outside a booted Laravel application.
 */
final class QpdfDecryptor
{
    /**
     * @throws MalformedBankStatementException if `qpdf` is not
     *                                         available, the file is locked with a real password, or
     *                                         `qpdf` otherwise cannot process it.
     */
    public function decrypt(string $pdfBytes): string
    {
        $inputPath = tempnam(sys_get_temp_dir(), 'hore-pdf-in-');
        $outputPath = tempnam(sys_get_temp_dir(), 'hore-pdf-out-');

        if ($inputPath === false || $outputPath === false) {
            throw MalformedBankStatementException::forUnreadablePdf('could not allocate a temporary file.');
        }

        try {
            file_put_contents($inputPath, $pdfBytes);

            $process = new Process(['qpdf', '--decrypt', $inputPath, $outputPath]);
            $process->run();

            if (! $process->isSuccessful()) {
                if (str_contains($process->getErrorOutput(), 'invalid password')) {
                    throw MalformedBankStatementException::forPasswordProtectedPdf();
                }

                throw MalformedBankStatementException::forUnreadablePdf(trim($process->getErrorOutput()));
            }

            $decrypted = file_exists($outputPath) ? file_get_contents($outputPath) : false;

            if ($decrypted === false || $decrypted === '') {
                throw MalformedBankStatementException::forUnreadablePdf('qpdf produced no output.');
            }

            return $decrypted;
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }
}
