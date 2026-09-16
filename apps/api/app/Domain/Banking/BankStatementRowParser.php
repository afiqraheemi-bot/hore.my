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
 * The single fixed v1 schema (M17, SRS BNK-001/BNK-003) every bank
 * statement format shares, extracted so {@see CsvBankStatementParser}
 * and {@see XlsxBankStatementParser} (Import & Export, AETS-008 §5.1)
 * validate the identical header and parse each row's cell values
 * through the identical rules — never two subtly divergent
 * validators for what is supposed to be one contract. Each format-
 * specific parser's own job is reduced to extracting raw string cell
 * values from its own file format; every actual validation rule
 * (date grammar, amount grammar, direction vocabulary, non-empty
 * description) lives here, once.
 *
 * **`amount` is always a positive magnitude; `direction` carries the
 * sign's meaning** — mirrors {@see JournalLine}'s
 * own "magnitude-with-direction, never signed" convention (AETS-004
 * §7, §8).
 *
 * **Never touches the database.** Pure, storage-free parsing.
 */
final class BankStatementRowParser
{
    /**
     * @var list<string>
     */
    public const EXPECTED_HEADER = ['date', 'description', 'amount', 'direction', 'balance', 'reference'];

    private const DATE_FORMAT = 'Y-m-d';

    /**
     * @param  list<string>  $normalizedHeader  already lower-cased and
     *                                          trimmed by the caller.
     *
     * @throws MalformedBankStatementException if the header does not
     *                                         match {@see EXPECTED_HEADER} exactly.
     */
    public function validateHeader(array $normalizedHeader): void
    {
        if ($normalizedHeader !== self::EXPECTED_HEADER) {
            throw MalformedBankStatementException::forMissingOrIncorrectHeader(implode(',', self::EXPECTED_HEADER));
        }
    }

    /**
     * @param  list<string>  $columns  each already trimmed by the
     *                                 caller; count must already match {@see EXPECTED_HEADER}.
     *
     * @throws MalformedBankStatementException if any cell fails its
     *                                         own column's format.
     */
    public function parseRow(int $lineNumber, array $columns, Currency $currency): BankStatementRow
    {
        if (count($columns) !== count(self::EXPECTED_HEADER)) {
            throw MalformedBankStatementException::forRow($lineNumber, sprintf('expected %d columns, found %d.', count(self::EXPECTED_HEADER), count($columns)));
        }

        [$rawDate, $description, $rawAmount, $rawDirection, $rawBalance, $reference] = $columns;

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
