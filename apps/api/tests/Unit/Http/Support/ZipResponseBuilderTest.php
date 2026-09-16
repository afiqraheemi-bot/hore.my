<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Support;

use App\Http\Support\ZipResponseBuilder;
use PHPUnit\Framework\TestCase;

final class ZipResponseBuilderTest extends TestCase
{
    public function test_builds_a_zip_containing_every_entry_with_its_exact_content(): void
    {
        $response = ZipResponseBuilder::build('bundle.zip', [
            'a.csv' => "A,B\n1,2\n",
            'b.csv' => "C,D\n3,4\n",
        ]);

        $zip = $this->openZipFromResponseBody((string) $response->getContent());

        $this->assertSame(2, $zip->numFiles);
        $this->assertSame("A,B\n1,2\n", $zip->getFromName('a.csv'));
        $this->assertSame("C,D\n3,4\n", $zip->getFromName('b.csv'));

        $zip->close();
    }

    public function test_sets_zip_content_type_and_download_disposition(): void
    {
        $response = ZipResponseBuilder::build('my-bundle.zip', ['a.csv' => 'x']);

        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="my-bundle.zip"', $response->headers->get('Content-Disposition'));
    }

    public function test_builds_an_empty_zip_when_given_no_entries(): void
    {
        $response = ZipResponseBuilder::build('empty.zip', []);

        $zip = $this->openZipFromResponseBody((string) $response->getContent());

        $this->assertSame(0, $zip->numFiles);

        $zip->close();
    }

    private function openZipFromResponseBody(string $body): \ZipArchive
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'zip-response-builder-test-');
        $this->assertNotFalse($tempPath);
        file_put_contents($tempPath, $body);

        $zip = new \ZipArchive;
        $openResult = $zip->open($tempPath);
        $this->assertTrue($openResult === true, sprintf('Expected the built ZIP to open cleanly, got error code %s.', var_export($openResult, true)));

        unlink($tempPath);

        return $zip;
    }
}
