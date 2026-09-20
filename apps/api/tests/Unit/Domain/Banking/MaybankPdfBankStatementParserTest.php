<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Banking;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Banking\BankTransactionDirection;
use App\Domain\Banking\Exception\MalformedBankStatementException;
use App\Domain\Banking\MaybankPdfBankStatementParser;
use App\Infrastructure\Banking\QpdfDecryptor;
use Dompdf\Dompdf;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers {@see MaybankPdfBankStatementParser} (AETS-008 §12.10) —
 * every fixture here is a synthetic PDF built from scratch via
 * `dompdf` (never a real bank statement; no real personal or
 * financial data appears anywhere in this file), constructed to
 * exercise the exact structural quirks a real Maybank e-statement was
 * directly observed to have: a `BEGINNING BALANCE` opening line,
 * `DD/MM/YY` dates, an amount encoded as magnitude-plus-trailing-sign,
 * multi-line narrative continuation (including across a page break),
 * and terminal `ENDING BALANCE`/`TOTAL CREDIT`/`TOTAL DEBIT` summary
 * lines this parser cross-validates against.
 */
final class MaybankPdfBankStatementParserTest extends TestCase
{
    private MaybankPdfBankStatementParser $parser;

    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new MaybankPdfBankStatementParser;
        $this->myr = Currency::of('MYR');
    }

    public function test_parses_a_well_formed_statement_with_narrative_continuation(): void
    {
        $pdf = $this->buildPdf($this->page([
            $this->row('', 'BEGINNING BALANCE', '', '', '1,000.00'),
            $this->row('01/07/20', 'SALE DEBIT', '15.00', '-', '985.00'),
            $this->narrative('FAKE MERCHANT SDN BHD *'),
            $this->narrative('Sample purchase'),
            $this->row('02/07/20', 'TRANSFER FROM A/C', '200.00', '+', '1,185.00'),
        ]).$this->summary('1,185.00', '200.00', '15.00'));

        $rows = $this->parser->parse($pdf, $this->myr);

        $this->assertCount(2, $rows);

        $this->assertSame('2020-07-01', $rows[0]->transactionDate()->format('Y-m-d'));
        $this->assertSame('SALE DEBIT', $rows[0]->description());
        $this->assertSame('15.00', $rows[0]->amount()->toDecimalString());
        $this->assertSame(BankTransactionDirection::MoneyOut, $rows[0]->direction());
        $this->assertSame('985.00', $rows[0]->balance()?->toDecimalString());
        $this->assertSame('FAKE MERCHANT SDN BHD * Sample purchase', $rows[0]->reference());

        $this->assertSame('2020-07-02', $rows[1]->transactionDate()->format('Y-m-d'));
        $this->assertSame('200.00', $rows[1]->amount()->toDecimalString());
        $this->assertSame(BankTransactionDirection::MoneyIn, $rows[1]->direction());
        $this->assertSame('', $rows[1]->reference());
    }

    /**
     * A real user-uploaded statement failed with "Line 1 could not be
     * recognized..." on a line smalot/pdfparser's own text extraction
     * rendered as `01/01/26IBK FUND TFR FR A/C 66.60-257.05` — no
     * space at all between the date and the description, nor between
     * the sign and the balance, despite every synthetic dompdf-built
     * fixture elsewhere in this file always producing one. Built via
     * {@see rawZoneLine()} (one contiguous text run, not separate
     * table cells) specifically to reproduce that exact missing-space
     * condition, not the normal spaced-out case every other test here
     * already covers.
     */
    public function test_a_transaction_line_with_no_whitespace_at_column_boundaries_still_parses(): void
    {
        $pdf = $this->buildPdf($this->page([
            $this->row('', 'BEGINNING BALANCE', '', '', '323.65'),
            $this->rawZoneLine('01/01/26IBK FUND TFR FR A/C 66.60-257.05'),
        ]).$this->summary('257.05', '0.00', '66.60'));

        $rows = $this->parser->parse($pdf, $this->myr);

        $this->assertCount(1, $rows);
        $this->assertSame('2026-01-01', $rows[0]->transactionDate()->format('Y-m-d'));
        $this->assertSame('IBK FUND TFR FR A/C', $rows[0]->description());
        $this->assertSame('66.60', $rows[0]->amount()->toDecimalString());
        $this->assertSame(BankTransactionDirection::MoneyOut, $rows[0]->direction());
        $this->assertSame('257.05', $rows[0]->balance()?->toDecimalString());
    }

    public function test_a_transactions_continuation_spans_a_page_break(): void
    {
        $pageOne = $this->page([
            $this->row('', 'BEGINNING BALANCE', '', '', '1,000.00'),
            $this->row('05/07/20', 'IBK FUND TFR FR A/C', '350.00', '-', '650.00'),
            $this->narrative('REF016474245952 *'),
            $this->narrative('MANAGEPAY SERVICES S'),
        ]);

        $pageTwo = $this->page([
            $this->narrative('2008061750430110'),
            $this->row('06/07/20', 'SALE DEBIT', '50.00', '-', '600.00'),
        ]);

        $pdf = $this->buildPdf($pageOne.$this->pageBreak().$pageTwo.$this->summary('600.00', '0.00', '400.00'));

        $rows = $this->parser->parse($pdf, $this->myr);

        $this->assertCount(2, $rows);
        $this->assertSame('REF016474245952 * MANAGEPAY SERVICES S 2008061750430110', $rows[0]->reference());
        $this->assertSame('2020-07-06', $rows[1]->transactionDate()->format('Y-m-d'));
    }

    public function test_a_totals_mismatch_is_rejected(): void
    {
        $pdf = $this->buildPdf($this->page([
            $this->row('', 'BEGINNING BALANCE', '', '', '1,000.00'),
            $this->row('01/07/20', 'SALE DEBIT', '15.00', '-', '985.00'),
        ]).$this->summary('985.00', '0.00', '999.00'));

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($pdf, $this->myr);
    }

    public function test_an_unrecognized_line_before_any_transaction_row_is_rejected(): void
    {
        $pdf = $this->buildPdf($this->page([
            'SOME UNEXPECTED LINE WITH NO DATE OR AMOUNT AT ALL',
            $this->row('01/07/20', 'SALE DEBIT', '15.00', '-', '985.00'),
        ]).$this->summary('985.00', '0.00', '15.00'));

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($pdf, $this->myr);
    }

    public function test_a_pdf_with_no_transaction_table_at_all_is_rejected(): void
    {
        $pdf = $this->buildPdf('<p>Not a bank statement.</p>');

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($pdf, $this->myr);
    }

    /**
     * The real-world case that surfaced this test: a genuine Maybank
     * statement PDF failed with "Secured pdf file are currently not
     * supported" — `smalot/pdfparser` has no decryption support of its
     * own, but the bank's own PDF is permissions-only encrypted with
     * an *empty* user password (opens with no prompt in any real PDF
     * viewer). {@see QpdfDecryptor} strips
     * this transparently before parsing — this parses identically to
     * the unencrypted fixture in the first test above.
     */
    public function test_a_statement_encrypted_with_an_empty_user_password_still_parses(): void
    {
        $pdf = $this->buildPdf($this->page([
            $this->row('', 'BEGINNING BALANCE', '', '', '1,000.00'),
            $this->row('01/07/20', 'SALE DEBIT', '15.00', '-', '985.00'),
            $this->row('02/07/20', 'TRANSFER FROM A/C', '200.00', '+', '1,185.00'),
        ]).$this->summary('1,185.00', '200.00', '15.00'));

        $encrypted = $this->encryptWithEmptyUserPassword($pdf);
        $this->assertNotSame($pdf, $encrypted, 'the fixture must actually be encrypted for this test to prove anything');

        $rows = $this->parser->parse($encrypted, $this->myr);

        $this->assertCount(2, $rows);
        $this->assertSame('SALE DEBIT', $rows[0]->description());
        $this->assertSame(BankTransactionDirection::MoneyIn, $rows[1]->direction());
    }

    /**
     * A PDF locked with a genuine, non-empty password is honestly
     * unrecoverable (`qpdf` cannot guess a password it was never
     * given) — but this is a distinct, actionable failure from the
     * opaque original "Secured pdf file are currently not supported."
     */
    public function test_a_statement_locked_with_a_real_password_is_rejected_with_actionable_guidance(): void
    {
        $pdf = $this->buildPdf($this->page([
            $this->row('', 'BEGINNING BALANCE', '', '', '1,000.00'),
            $this->row('01/07/20', 'SALE DEBIT', '15.00', '-', '985.00'),
        ]).$this->summary('985.00', '0.00', '15.00'));

        $locked = $this->encryptWithRealPassword($pdf, 'super-secret-password');

        try {
            $this->parser->parse($locked, $this->myr);
            $this->fail('Expected MalformedBankStatementException.');
        } catch (MalformedBankStatementException $e) {
            $this->assertStringContainsString('password', $e->getMessage());
        }
    }

    // --- Synthetic fixture builders ------------------------------------

    private function encryptWithEmptyUserPassword(string $pdfBytes): string
    {
        return $this->encryptViaQpdf($pdfBytes, '', 'owner-password-not-secret', '256');
    }

    private function encryptWithRealPassword(string $pdfBytes, string $userPassword): string
    {
        return $this->encryptViaQpdf($pdfBytes, $userPassword, 'owner-password-not-secret', '256');
    }

    private function encryptViaQpdf(string $pdfBytes, string $userPassword, string $ownerPassword, string $keyLength): string
    {
        $inputPath = tempnam(sys_get_temp_dir(), 'maybank-pdf-test-in-');
        $outputPath = tempnam(sys_get_temp_dir(), 'maybank-pdf-test-out-');

        try {
            file_put_contents($inputPath, $pdfBytes);

            $process = new Process(['qpdf', '--encrypt', $userPassword, $ownerPassword, $keyLength, '--', $inputPath, $outputPath]);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->markTestSkipped('qpdf is not available or failed to encrypt the test fixture: '.$process->getErrorOutput());
            }

            $encrypted = file_get_contents($outputPath);
            $this->assertIsString($encrypted);

            return $encrypted;
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }

    private function row(string $date, string $description, string $amount, string $sign, string $balance): string
    {
        $amountCell = $amount === '' ? '' : $amount.$sign;

        return sprintf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', $date, $description, $amountCell, $balance);
    }

    /**
     * A zone line as one contiguous text run (a single cell spanning
     * every column), rather than {@see row()}'s own separate `<td>`
     * cells — dompdf/smalot's own extraction reliably inserts a gap
     * between adjacent cells, which every other fixture in this file
     * relies on; this helper exists specifically to construct a line
     * with no gap at all at a given boundary, verbatim.
     */
    private function rawZoneLine(string $line): string
    {
        return sprintf('<tr><td colspan="4">%s</td></tr>', $line);
    }

    private function narrative(string $text): string
    {
        return sprintf('<tr><td></td><td>&nbsp;&nbsp;%s</td><td></td><td></td></tr>', $text);
    }

    /**
     * @param  list<string>  $rowsHtml
     */
    private function page(array $rowsHtml): string
    {
        return '<table style="width:100%; border-collapse: collapse;">'
            .'<tr><td colspan="4">URUSNIAGA AKAUN/ 戶口進支項 /ACCOUNT TRANSACTIONS</td></tr>'
            .'<tr><td>TARIKH MASUK</td><td>BUTIR URUSNIAGA</td><td>JUMLAH URUSNIAGA</td><td>BAKI PENYATA</td></tr>'
            .'<tr><td colspan="4">進支日期 進支項說明 银碼 結單存餘</td></tr>'
            .'<tr><td>ENTRY DATE</td><td>TRANSACTION DESCRIPTION</td><td>TRANSACTION AMOUNT</td><td>STATEMENT BALANCE</td></tr>'
            .implode('', $rowsHtml)
            .'</table>'
            .'<p>Maybank Islamic Berhad (787435-M)</p>'
            .'<p>SYNTHETIC TEST BANK ADDRESS — NOT A REAL BRANCH</p>';
    }

    private function pageBreak(): string
    {
        return '<div style="page-break-after: always;"></div>';
    }

    private function summary(string $endingBalance, string $totalCredit, string $totalDebit): string
    {
        return sprintf(
            '<p>ENDING BALANCE : %s</p><p>TOTAL CREDIT : %s</p><p>TOTAL DEBIT : %s</p>',
            $endingBalance,
            $totalCredit,
            $totalDebit,
        );
    }

    private function buildPdf(string $bodyHtml): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<html><body style="font-family: sans-serif; font-size: 10px;">'.$bodyHtml.'</body></html>');
        $dompdf->render();

        return $dompdf->output();
    }
}
