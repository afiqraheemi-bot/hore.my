<!--
    Shared minimal PDF layout for both Invoice and Quotation (AETS-017
    §4) — deliberately one template, not two: the two documents differ
    only in a handful of labels/fields this view already parameterizes
    ($documentTypeLabel, $secondaryDateLabel, etc.), and duplicating an
    entire layout for that difference would be exactly the kind of
    premature-abstraction-avoidance this codebase's own conventions
    warn against inverted — here, the risk is needless *duplication*,
    not needless abstraction.

    No company logo, no letterhead, no configurable color scheme
    (AETS-017 §3) — a Founder-level branding decision this document
    does not make. Every value below is already-escaped/trusted server
    data (Business Profile, Customer, Invoice/Quotation), never raw
    user HTML.

    ASCII-only in the rendered HTML below (outside this comment).
    Dompdf's base Helvetica/Arial fonts are not Unicode-aware; an em-
    dash, middle dot, or curly quote silently renders as mojibake
    ("Â·"/"â??") instead of throwing — caught only by visually
    rendering a real downloaded PDF, never by a magic-byte or
    Content-Type check. Use a plain hyphen, not an em-dash entity.
-->
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 32px; }
    .header { display: table; width: 100%; margin-bottom: 24px; }
    .header .seller { display: table-cell; width: 60%; vertical-align: top; }
    .header .doc-meta { display: table-cell; width: 40%; vertical-align: top; text-align: right; }
    .seller-name { font-size: 16px; font-weight: bold; margin-bottom: 4px; }
    .doc-title { font-size: 20px; font-weight: bold; letter-spacing: 1px; margin-bottom: 4px; }
    .muted { color: #666; }
    .status-badge { display: inline-block; padding: 2px 8px; border: 1px solid #999; border-radius: 3px; font-size: 10px; margin-top: 4px; }
    .parties { display: table; width: 100%; margin-bottom: 20px; }
    .parties .buyer { display: table-cell; width: 60%; vertical-align: top; }
    .parties .dates { display: table-cell; width: 40%; vertical-align: top; text-align: right; }
    .section-label { text-transform: uppercase; font-size: 9px; color: #888; margin-bottom: 4px; letter-spacing: 0.5px; }
    table.lines { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    table.lines th { text-align: left; border-bottom: 1.5px solid #1a1a1a; padding: 6px 4px; font-size: 10px; text-transform: uppercase; }
    table.lines th.num, table.lines td.num { text-align: right; }
    table.lines td { padding: 6px 4px; border-bottom: 1px solid #eee; }
    .total-row { display: table; width: 100%; margin-top: 8px; }
    .total-row .spacer { display: table-cell; width: 70%; }
    .total-row .total { display: table-cell; width: 30%; text-align: right; font-size: 14px; font-weight: bold; border-top: 1.5px solid #1a1a1a; padding-top: 6px; }
    .footer { margin-top: 32px; font-size: 9px; color: #999; }
</style>
</head>
<body>
    <div class="header">
        <div class="seller">
            <div class="seller-name">{{ $seller['legal_name'] }}</div>
            @foreach ($seller['address_lines'] as $line)
                <div class="muted">{{ $line }}</div>
            @endforeach
            @if ($seller['registration_number'])
                <div class="muted">Reg. No: {{ $seller['registration_number'] }}</div>
            @endif
            @if ($seller['tin'])
                <div class="muted">TIN: {{ $seller['tin'] }}</div>
            @endif
        </div>
        <div class="doc-meta">
            <div class="doc-title">{{ $documentTypeLabel }}</div>
            <div>{{ $documentNumber ?? 'DRAFT' }}</div>
            <div class="status-badge">{{ $statusLabel }}</div>
        </div>
    </div>

    <div class="parties">
        <div class="buyer">
            <div class="section-label">Bill To</div>
            <div><strong>{{ $buyer['name'] }}</strong></div>
            @if ($buyer['address'])
                <div class="muted">{{ $buyer['address'] }}</div>
            @endif
            @if ($buyer['email'])
                <div class="muted">{{ $buyer['email'] }}</div>
            @endif
            @if ($buyer['phone'])
                <div class="muted">{{ $buyer['phone'] }}</div>
            @endif
            @if ($buyer['tax_id'])
                <div class="muted">Tax ID: {{ $buyer['tax_id'] }}</div>
            @endif
        </div>
        <div class="dates">
            @if ($issueDate)
                <div class="section-label">Issue Date</div>
                <div>{{ $issueDate }}</div>
            @endif
            <div class="section-label" style="margin-top: 8px;">{{ $secondaryDateLabel }}</div>
            <div>{{ $secondaryDate }}</div>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Unit Price ({{ $currency }})</th>
                <th class="num">Amount ({{ $currency }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line['description'] }}</td>
                    <td class="num">{{ $line['quantity'] }}</td>
                    <td class="num">{{ $line['unit_price'] }}</td>
                    <td class="num">{{ $line['line_amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total-row">
        <div class="spacer"></div>
        <div class="total">Total: {{ $currency }} {{ $totalAmount }}</div>
    </div>

    <div class="footer">Generated by hore.my - a computer-generated {{ strtolower($documentTypeLabel) }}, no signature required.</div>
</body>
</html>
