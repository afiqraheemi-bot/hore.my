<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Exception\InvalidMoneyAmountException;
use App\Domain\Accounting\Money\Exception\UnresolvedMoneySignPolicyException;
use App\Domain\Accounting\Money\Money;
use App\Domain\Banking\Exception\MalformedBankStatementException;
use Smalot\PdfParser\Parser as PdfTextParser;

/**
 * Parses Maybank's own native PDF e-statement layout into a list of
 * {@see BankStatementRow} (AETS-008 §12.10, decided 2026-09-19) — the
 * identical fixed v1 schema {@see BankStatementRowParser} already
 * validates for CSV/XLSX, translated from Maybank's own layout,
 * never a second schema and never configurable mapping (§12.5
 * otherwise unchanged).
 *
 * **Built and verified against exactly one real Maybank Islamic
 * savings-account e-statement's layout.** A different Maybank product
 * (conventional current/savings vs. Islamic), a redesigned statement
 * template, or a business account variant may use different fixed
 * wording than the anchors below expect and will fail closed — safe,
 * but not yet supported — rather than misparse. Extending this parser
 * to a newly observed real layout is real, separate work, not
 * something this class attempts to generalize in advance.
 *
 * **Anchor-based, not whitelist-based, boilerplate exclusion.** A
 * statement's letterhead/footer includes the customer's own name and
 * address — genuinely unbounded free text no finite marker list could
 * safely enumerate. This parser never tries: it locates the *start*
 * of each page's transaction table by the fixed, never-customer-data
 * heading `"URUSNIAGA AKAUN"`, and its *end* by the fixed bank name
 * (`"Maybank Islamic Berhad"`) or the fixed `"ENDING BALANCE"`
 * keyword — both anchors are wording this bank's own statement design
 * fixes, never data. Everything between those two anchors, across
 * every page in document order, is transaction-table content; nothing
 * outside that window is ever inspected, so the parser never has to
 * "recognize" a customer's name/address to safely ignore it.
 *
 * **A transaction's narrative continuation lines may span a page
 * break** (observed directly in the real sample: a reference code
 * belonging to one page's last transaction reappears at the very top
 * of the next page's own transaction-table window, before that page's
 * first dated row). Concatenating every page's extracted window in
 * document order before classifying rows/continuations handles this
 * correctly with no special-case code — the page boundary is erased
 * before classification ever runs.
 *
 * **Fails closed on its own declared totals, as a safety net beyond
 * per-row validation.** Every real Maybank statement states its own
 * `BEGINNING BALANCE`, `ENDING BALANCE`, `TOTAL CREDIT`, and
 * `TOTAL DEBIT`. This parser recomputes all four from the rows it
 * actually parsed and rejects the whole file if they disagree —
 * bounding the blast radius of any text-layout misparse (a dropped or
 * misread row) to a safe rejection, never a silently wrong import.
 */
final class MaybankPdfBankStatementParser
{
    private const TRANSACTION_ZONE_START_MARKER = 'URUSNIAGA AKAUN';

    private const TRANSACTION_ZONE_END_MARKERS = ['Maybank Islamic Berhad', 'ENDING BALANCE'];

    private const HEADER_LINES_TO_SKIP_AFTER_START = 3;

    private const TRANSACTION_LINE_PATTERN = '/^(\d{2}\/\d{2}\/\d{2})\s+(.+?)\s+([\d,]+\.\d{2})([+-])\s+([\d,]+\.\d{2})$/u';

    private const BEGINNING_BALANCE_PATTERN = '/^BEGINNING BALANCE\s+([\d,]+\.\d{2})$/u';

    private const ENDING_BALANCE_PATTERN = '/^ENDING BALANCE\s*:\s*([\d,]+\.\d{2})$/u';

    private const TOTAL_CREDIT_PATTERN = '/^TOTAL CREDIT\s*:\s*([\d,]+\.\d{2})$/u';

    private const TOTAL_DEBIT_PATTERN = '/^TOTAL DEBIT\s*:\s*([\d,]+\.\d{2})$/u';

    private readonly BankStatementRowParser $rowParser;

    public function __construct()
    {
        $this->rowParser = new BankStatementRowParser;
    }

    /**
     * @return list<BankStatementRow>
     *
     * @throws MalformedBankStatementException if the file cannot be
     *                                         read as a PDF, has no recognizable transaction table, any row
     *                                         fails {@see BankStatementRowParser}'s own validation, or the
     *                                         statement's own declared totals do not reconcile with what was
     *                                         actually parsed.
     */
    public function parse(string $fileContent, Currency $currency): array
    {
        try {
            $document = (new PdfTextParser)->parseContent($fileContent);
        } catch (\Throwable $e) {
            throw MalformedBankStatementException::forUnreadablePdf($e->getMessage());
        }

        $pages = $document->getPages();

        if ($pages === []) {
            throw MalformedBankStatementException::forEmptyFile();
        }

        /** @var list<string> $allLines */
        $allLines = [];
        /** @var list<string> $zoneLines */
        $zoneLines = [];

        foreach ($pages as $page) {
            $pageLines = $this->splitLines($page->getText());
            $allLines = [...$allLines, ...$pageLines];
            $zoneLines = [...$zoneLines, ...$this->extractTransactionZone($pageLines)];
        }

        if ($allLines === []) {
            throw MalformedBankStatementException::forEmptyFile();
        }

        $beginningBalance = $this->extractSingleAmount($allLines, self::BEGINNING_BALANCE_PATTERN, 'BEGINNING BALANCE', $currency);
        $endingBalance = $this->extractSingleAmount($allLines, self::ENDING_BALANCE_PATTERN, 'ENDING BALANCE', $currency);
        $totalCredit = $this->extractSingleAmount($allLines, self::TOTAL_CREDIT_PATTERN, 'TOTAL CREDIT', $currency);
        $totalDebit = $this->extractSingleAmount($allLines, self::TOTAL_DEBIT_PATTERN, 'TOTAL DEBIT', $currency);

        $rowBuilders = $this->classifyZoneLines($zoneLines);

        if ($rowBuilders === []) {
            throw MalformedBankStatementException::forEmptyFile();
        }

        $rows = [];
        $creditSum = Money::fromDecimalString('0.00', $currency);
        $debitSum = Money::fromDecimalString('0.00', $currency);

        foreach ($rowBuilders as $lineNumber => $builder) {
            [$rawDate, $description, $rawAmount, $rawDirection, $rawBalance, $referenceParts] = $builder;

            $columns = [
                $this->convertDate($rawDate),
                $description,
                str_replace(',', '', $rawAmount),
                $rawDirection === '+' ? 'IN' : 'OUT',
                str_replace(',', '', $rawBalance),
                trim(implode(' ', $referenceParts)),
            ];

            $row = $this->rowParser->parseRow($lineNumber + 1, $columns, $currency);
            $rows[] = $row;

            $creditSum = $row->direction() === BankTransactionDirection::MoneyIn
                ? $creditSum->add($row->amount())
                : $creditSum;
            $debitSum = $row->direction() === BankTransactionDirection::MoneyOut
                ? $debitSum->add($row->amount())
                : $debitSum;
        }

        if (! $creditSum->equals($totalCredit)) {
            throw MalformedBankStatementException::forTotalsMismatch(sprintf('parsed credits sum to %s but the statement declares TOTAL CREDIT %s', $creditSum->toDecimalString(), $totalCredit->toDecimalString()));
        }

        if (! $debitSum->equals($totalDebit)) {
            throw MalformedBankStatementException::forTotalsMismatch(sprintf('parsed debits sum to %s but the statement declares TOTAL DEBIT %s', $debitSum->toDecimalString(), $totalDebit->toDecimalString()));
        }

        try {
            $impliedEndingBalance = $beginningBalance->add($creditSum)->subtract($debitSum);
        } catch (UnresolvedMoneySignPolicyException) {
            throw MalformedBankStatementException::forTotalsMismatch('BEGINNING BALANCE plus parsed credits minus parsed debits would be negative.');
        }

        if (! $impliedEndingBalance->equals($endingBalance)) {
            throw MalformedBankStatementException::forTotalsMismatch(sprintf('BEGINNING BALANCE %s plus parsed credits %s minus parsed debits %s implies an ending balance of %s, but the statement declares ENDING BALANCE %s', $beginningBalance->toDecimalString(), $creditSum->toDecimalString(), $debitSum->toDecimalString(), $impliedEndingBalance->toDecimalString(), $endingBalance->toDecimalString()));
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r", "\u{A0}"], ["\n", "\n", ' '], $text);
        $lines = explode("\n", $normalized);

        return array_values(array_filter(
            array_map(static fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? $line), $lines),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * @param  list<string>  $pageLines
     * @return list<string>
     */
    private function extractTransactionZone(array $pageLines): array
    {
        $startIndex = null;

        foreach ($pageLines as $index => $line) {
            if (str_starts_with($line, self::TRANSACTION_ZONE_START_MARKER)) {
                $startIndex = $index;
                break;
            }
        }

        if ($startIndex === null) {
            return [];
        }

        $contentStart = $startIndex + 1 + self::HEADER_LINES_TO_SKIP_AFTER_START;
        $zone = [];

        for ($i = $contentStart; $i < count($pageLines); $i++) {
            $line = $pageLines[$i];

            foreach (self::TRANSACTION_ZONE_END_MARKERS as $endMarker) {
                if (str_starts_with($line, $endMarker)) {
                    return $zone;
                }
            }

            $zone[] = $line;
        }

        return $zone;
    }

    /**
     * @param  list<string>  $zoneLines
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: list<string>}>
     */
    private function classifyZoneLines(array $zoneLines): array
    {
        $rows = [];
        $current = null;

        foreach ($zoneLines as $line) {
            if (preg_match(self::BEGINNING_BALANCE_PATTERN, $line) === 1) {
                continue;
            }

            if (preg_match(self::TRANSACTION_LINE_PATTERN, $line, $matches) === 1) {
                if ($current !== null) {
                    $rows[] = $current;
                }

                $current = [$matches[1], $matches[2], $matches[3], $matches[4], $matches[5], []];

                continue;
            }

            if ($current === null) {
                throw MalformedBankStatementException::forUnrecognizedLine(count($rows) + 1, $line);
            }

            $current[5][] = $line;
        }

        if ($current !== null) {
            $rows[] = $current;
        }

        return $rows;
    }

    /**
     * @param  list<string>  $lines
     */
    private function extractSingleAmount(array $lines, string $pattern, string $label, Currency $currency): Money
    {
        $matches = [];

        foreach ($lines as $line) {
            if (preg_match($pattern, $line, $lineMatches) === 1) {
                $matches[] = $lineMatches[1];
            }
        }

        if (count($matches) !== 1) {
            throw MalformedBankStatementException::forTotalsMismatch(sprintf('expected exactly one "%s" line, found %d.', $label, count($matches)));
        }

        try {
            return Money::fromDecimalString(str_replace(',', '', $matches[0]), $currency);
        } catch (InvalidMoneyAmountException) {
            throw MalformedBankStatementException::forTotalsMismatch(sprintf('"%s" value "%s" is not a valid amount.', $label, $matches[0]));
        }
    }

    private function convertDate(string $rawDate): string
    {
        [$day, $month, $year] = explode('/', $rawDate);

        return sprintf('20%s-%s-%s', $year, $month, $day);
    }
}
