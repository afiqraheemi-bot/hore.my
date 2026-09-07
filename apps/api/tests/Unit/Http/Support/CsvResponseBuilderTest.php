<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Support;

use App\Http\Support\CsvResponseBuilder;
use PHPUnit\Framework\TestCase;

final class CsvResponseBuilderTest extends TestCase
{
    public function test_builds_a_csv_body_with_header_and_rows(): void
    {
        $response = CsvResponseBuilder::build(
            'report.csv',
            ['Account ID', 'Amount'],
            [
                ['account-0001', '100.00'],
                ['account-0002', '200.00'],
            ],
        );

        $content = (string) $response->getContent();

        // PHP's own `fputcsv()` (with the `$escape` parameter disabled,
        // per this class's own docblock) quotes any field containing a
        // space, including "Account ID" here — still perfectly valid,
        // parseable CSV, just more conservative than the strict RFC
        // 4180 minimum.
        $this->assertSame("\"Account ID\",Amount\naccount-0001,100.00\naccount-0002,200.00\n", $content);
    }

    public function test_sets_csv_content_type_and_download_disposition(): void
    {
        $response = CsvResponseBuilder::build('my-report.csv', ['A'], [['1']]);

        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="my-report.csv"', $response->headers->get('Content-Disposition'));
    }

    public function test_builds_a_csv_with_only_a_header_when_there_are_no_rows(): void
    {
        $response = CsvResponseBuilder::build('empty.csv', ['A', 'B'], []);

        $this->assertSame("A,B\n", (string) $response->getContent());
    }

    public function test_escapes_a_value_containing_a_comma(): void
    {
        $response = CsvResponseBuilder::build('report.csv', ['Description'], [['Hello, world']]);

        $this->assertSame("Description\n\"Hello, world\"\n", (string) $response->getContent());
    }
}
