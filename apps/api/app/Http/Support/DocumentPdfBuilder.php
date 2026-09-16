<?php

declare(strict_types=1);

namespace App\Http\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds a downloadable PDF `Response` by rendering a Blade view to
 * HTML and passing it through Dompdf (AETS-017) — a pure HTTP-layer
 * presentation concern, mirroring {@see CsvResponseBuilder}'s own
 * scoping exactly: it accepts a view name and its data, already fully
 * computed by the caller, and knows nothing about Invoice, Quotation,
 * or any other domain concept itself. The one shared view this
 * project currently renders through it
 * (`resources/views/pdf/document.blade.php`) is deliberately generic —
 * see AETS-017 §4 for why "Invoice" and "Quotation" are the same
 * template, not two.
 *
 * **Deliberately minimal, no custom branding.** Per AETS-017 §3, this
 * renders a functional, legally-adequate document with no company
 * logo upload, no letterhead, and no configurable color scheme — that
 * remains a Founder-level product decision, not resolved here.
 */
final class DocumentPdfBuilder
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function build(string $filename, string $view, array $data): Response
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultPaperSize', 'A4');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(View::make($view, $data)->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }
}
