<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Banking;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Banking\BankTransactionDirection;
use App\Domain\Banking\CsvBankStatementParser;
use App\Domain\Banking\Exception\MalformedBankStatementException;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see CsvBankStatementParser} (M17) — the single fixed v1
 * schema, normalization (SRS BNK-003), and every malformed-input
 * rejection path, exercised as a pure Value Object with no database.
 */
final class CsvBankStatementParserTest extends TestCase
{
    private CsvBankStatementParser $parser;

    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new CsvBankStatementParser;
        $this->myr = Currency::of('MYR');
    }

    public function test_parses_a_well_formed_statement(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,3000.00,IN,3000.00,REF001\n"
            ."2026-08-02,Rent payment,1200.00,OUT,1800.00,REF002\n";

        $rows = $this->parser->parse($csv, $this->myr);

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
        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,3000.00,in,,\n";

        $rows = $this->parser->parse($csv, $this->myr);

        $this->assertSame(BankTransactionDirection::MoneyIn, $rows[0]->direction());
    }

    public function test_header_row_is_case_insensitive(): void
    {
        $csv = "DATE,Description,AMOUNT,Direction,Balance,REFERENCE\n"
            ."2026-08-01,Salary credit,3000.00,IN,,\n";

        $rows = $this->parser->parse($csv, $this->myr);

        $this->assertCount(1, $rows);
    }

    public function test_empty_balance_and_reference_are_accepted(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,3000.00,IN,,\n";

        $rows = $this->parser->parse($csv, $this->myr);

        $this->assertNull($rows[0]->balance());
        $this->assertSame('', $rows[0]->reference());
    }

    public function test_blank_lines_are_ignored(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."\n"
            ."2026-08-01,Salary credit,3000.00,IN,,\n"
            ."\n";

        $rows = $this->parser->parse($csv, $this->myr);

        $this->assertCount(1, $rows);
    }

    public function test_empty_file_is_rejected(): void
    {
        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse('', $this->myr);
    }

    public function test_missing_header_is_rejected(): void
    {
        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse("2026-08-01,Salary credit,3000.00,IN,,\n", $this->myr);
    }

    public function test_wrong_header_order_is_rejected(): void
    {
        $csv = "description,date,amount,direction,balance,reference\n"
            ."Salary credit,2026-08-01,3000.00,IN,,\n";

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($csv, $this->myr);
    }

    public function test_wrong_column_count_is_rejected(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,3000.00,IN\n";

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($csv, $this->myr);
    }

    public function test_invalid_date_is_rejected(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."01/08/2026,Salary credit,3000.00,IN,,\n";

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($csv, $this->myr);
    }

    public function test_empty_description_is_rejected(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            .'2026-08-01,,3000.00,IN,,'."\n";

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($csv, $this->myr);
    }

    public function test_malformed_amount_is_rejected(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,abc,IN,,\n";

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($csv, $this->myr);
    }

    public function test_negative_amount_is_rejected(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,-100.00,IN,,\n";

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($csv, $this->myr);
    }

    public function test_invalid_direction_is_rejected(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,100.00,SIDEWAYS,,\n";

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($csv, $this->myr);
    }

    public function test_malformed_balance_is_rejected(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,100.00,IN,not-a-number,\n";

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($csv, $this->myr);
    }

    /**
     * BNK-020 (AETS-008 §12.9): a negative running balance describes an
     * overdraft, which Money cannot represent and this milestone does
     * not support — the whole file fails closed atomically rather than
     * silently truncating or misreading it.
     */
    public function test_negative_balance_is_rejected(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Overdrawn,100.00,OUT,-50.00,\n";

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($csv, $this->myr);
    }

    public function test_a_single_malformed_row_fails_the_whole_import_not_just_that_row(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Good row,100.00,IN,,\n"
            ."2026-08-02,Bad row,abc,IN,,\n"
            ."2026-08-03,Another good row,50.00,OUT,,\n";

        $this->expectException(MalformedBankStatementException::class);

        $this->parser->parse($csv, $this->myr);
    }
}
