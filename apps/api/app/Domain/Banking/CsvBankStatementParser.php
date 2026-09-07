<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Exception\InvalidMoneyAmountException;
use App\Domain\Accounting\Money\Exception\UnresolvedMoneySignPolicyException;
use App\Domain\Accounting\Money\Money;
use App\Domain\Banking\Exception\MalformedBankStatementException;

/**
 * Parses a raw CSV bank statement file into a list of
 * {@see BankStatementRow} (M17, SRS BNK-001/BNK-003).
 *
 * **One fixed schema, not configurable column mapping.** SRS BNK-002
 * asks for guided mapping of unrecognized formats — this class does not
 * attempt that yet. It requires the exact header row (case-insensitive,
 * trimmed) `{@see self::EXPECTED_HEADER}` and rejects anything else
 * with {@see MalformedBankStatementException}. A configurable-mapping
 * importer for arbitrary bank export formats is real, separate work,
 * deliberately deferred rather than half-built here — see the M17
 * closure report for the tracked gap. This class implements only
 * {@see CsvBankStatementParser} as a *type*, so a future
 * `XlsxBankStatementParser` or per-bank-format parser can sit alongside
 * it (SRS BNK-008's provider-abstraction spirit) without this class
 * needing to change.
 *
 * **`amount` is always a positive magnitude; `direction` carries the
 * sign's meaning** — mirrors {@see JournalLine}'s
 * own "magnitude-with-direction, never signed" convention (AETS-004
 * §7, §8), for the identical reason: no real record should ever need
 * to write "a MoneyIn of -RM10.00."
 *
 * **Never touches the database.** Pure, storage-free parsing —
 * {@see BankStatementImportService} is the only caller, and the only
 * place persistence happens.
 */
final class CsvBankStatementParser
{
    private const EXPECTED_HEADER = ['date', 'description', 'amount', 'direction', 'balance', 'reference'];

    private const DATE_FORMAT = 'Y-m-d';

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

        if ($normalizedHeader !== self::EXPECTED_HEADER) {
            throw MalformedBankStatementException::forMissingOrIncorrectHeader(implode(',', self::EXPECTED_HEADER));
        }

        $rows = [];

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 2; // +1 for zero-based index, +1 for the consumed header row.
            $rows[] = $this->parseRow($lineNumber, str_getcsv($line, ',', '"', ''), $currency);
        }

        return $rows;
    }

    /**
     * @param  list<string|null>  $columns
     */
    private function parseRow(int $lineNumber, array $columns, Currency $currency): BankStatementRow
    {
        if (count($columns) !== count(self::EXPECTED_HEADER)) {
            throw MalformedBankStatementException::forRow($lineNumber, sprintf('expected %d columns, found %d.', count(self::EXPECTED_HEADER), count($columns)));
        }

        [$rawDate, $description, $rawAmount, $rawDirection, $rawBalance, $reference] = array_map(
            static fn (?string $value): string => trim((string) $value),
            $columns,
        );

        $transactionDate = \DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $rawDate);

        if ($transactionDate === false || $transactionDate->format(self::DATE_FORMAT) !== $rawDate) {
            throw MalformedBankStatementException::forRow($lineNumber, sprintf('"%s" is not a valid date in Y-m-d format.', $rawDate));
        }

        if ($description === '') {
            throw MalformedBankStatementException::forRow($lineNumber, 'description must not be empty.');
        }

        try {
            $amount = Money::fromDecimalString($rawAmount, $currency);
        } catch (InvalidMoneyAmountException|UnresolvedMoneySignPolicyException) {
            throw MalformedBankStatementException::forRow($lineNumber, sprintf('"%s" is not a valid amount.', $rawAmount));
        }

        $direction = match (strtoupper($rawDirection)) {
            'IN' => BankTransactionDirection::MoneyIn,
            'OUT' => BankTransactionDirection::MoneyOut,
            default => throw MalformedBankStatementException::forRow($lineNumber, sprintf('direction must be "IN" or "OUT", found "%s".', $rawDirection)),
        };

        $balance = null;

        if ($rawBalance !== '') {
            try {
                $balance = Money::fromDecimalString($rawBalance, $currency);
            } catch (InvalidMoneyAmountException|UnresolvedMoneySignPolicyException) {
                throw MalformedBankStatementException::forRow($lineNumber, sprintf('"%s" is not a valid balance.', $rawBalance));
            }
        }

        return new BankStatementRow($transactionDate, $description, $amount, $direction, $balance, $reference);
    }
}
