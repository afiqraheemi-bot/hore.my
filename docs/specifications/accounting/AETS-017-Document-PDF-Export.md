# AETS-017: Invoice and Quotation PDF Export

- Status: Draft
- Version: 0.1.1
- Effective date: Not effective — pending required review
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: CTO / Technical Partner; Accounting Domain Reviewer (outstanding — same honest gap [AETS-008](AETS-008-Bank-Reconciliation.md)/[AETS-012](AETS-012-Proof-of-Accuracy.md)/[AETS-015](AETS-015-Evidence-Storage.md)/[AETS-016](AETS-016-Quotation.md) each record); Founder / Product Owner (scope approval obtained 2026-09-16 — see §3)
- Related: [AETS-009](AETS-009-Financial-Reporting.md) §2.2/§15/§18 — the "PDF (Invoice)" deferral this document resolves for Invoice; [AETS-016](AETS-016-Quotation.md) §2.2/§8 — the identical deferral for Quotation

## 1. Purpose

Two separate documents each named an identical, unresolved deferral: [AETS-009 §2.2](AETS-009-Financial-Reporting.md#22-out-of-scope) named "PDF (Invoice)" export as blocked on "an invoice layout/branding decision that is a Founder-level product call," and [AETS-016 §2.2](AETS-016-Quotation.md#22-out-of-scope) named the identical blocker for Quotation. This document resolves both, for Invoice and Quotation only — never for a financial report (Trial Balance, Profit & Loss, etc.), which remains PDF-deferred exactly as AETS-009 §2.2/§15/§18 already state; those reports are consumed as CSV/XLSX for professional review (AETS-009 §20), not printed as customer-facing documents the way an Invoice or Quotation is.

**This Draft is not governing implementation and is not an accuracy or release certification.** Per this repository's own established convention ([AETS-008](AETS-008-Bank-Reconciliation.md) §1), a Draft may describe and even accompany real, running code — existing code described here is evidence of current behaviour, not authority for it, until an Accounting Domain Reviewer's sign-off is recorded.

## 2. Scope

### 2.1 In scope

- A real HTTP boundary rendering a PDF for one Invoice (`GET /invoices/{id}/pdf`) or one Quotation (`GET /quotations/{id}/pdf`), in any state either aggregate can be in — a Draft/not-yet-sent document renders as a labelled preview, never an error.
- One shared, minimal, functional layout for both: seller identity (Business Profile: legal name, address, registration number, TIN), buyer identity (Customer: name, address, email, phone, tax ID), document metadata (type, number or "DRAFT," status, dates), a line-item table, and a total.
- Malaysian-relevant legal fields already captured elsewhere in this codebase (Business Profile's `registration_number`/`tin`, Customer's own tax identification number) are rendered when present, omitted when absent — never fabricated.

### 2.2 Out of scope

- **Any company logo, letterhead, or configurable color scheme.** Per §3's own Founder decision, this document builds the minimal/functional layout now; branded output (logo upload, custom color, font choice) is a tracked future enhancement, not built here.
- **PDF rendering of any financial report** (Trial Balance, Profit & Loss, Balance Sheet, General Ledger, Evidence Index, Aging) — remains deferred exactly as [AETS-009 §2.2/§15/§18](AETS-009-Financial-Reporting.md) already state; those gain XLSX export instead ([AETS-009 §20](AETS-009-Financial-Reporting.md)).
- **Email delivery of the rendered PDF to the Customer** — mirrors [AETS-016 §2.2](AETS-016-Quotation.md#22-out-of-scope)'s own identical deferral; this document produces a downloadable file only, never sends anything.
- **Digital signatures, QR codes, or any MyInvois-specific rendering requirement** — MyInvois e-invoice submission is a wholly separate, not-yet-built capability (Master Context §13); this PDF is a human-readable convenience document, never a submission artifact.
- **Multi-page pagination logic for pathologically long documents** — `Invoice`/`Quotation` each already cap at 200 lines (`TooManyInvoiceLinesException`/`TooManyQuotationLinesException`); Dompdf's own default page-flow handles this bound without custom pagination code.

## 3. Authority and Founder approval

Introducing a customer-facing rendered document is a user-experience and product-scope change per [AETS-000 §8.2](AETS-000.md#82-ownership-and-review), requiring Founder / Product Owner review — the exact review both AETS-009 §2.2 and AETS-016 §2.2 named as outstanding for this precise decision. That approval was obtained 2026-09-16: asked directly whether to (a) build a minimal/functional layout now, with no logo/letterhead, or (b) wait for the Founder to first supply branding guidelines, the Founder chose (a). An Accounting Domain Reviewer's independent sign-off remains outstanding, consistent with the same honest gap this series' other Draft documents each already record — this document introduces no ledger/Posting Command behavior and no new accounting rule, but AETS-000 §8.2 names that review unconditionally and this document does not claim an exception to it.

## 4. Shared template rationale

One Blade view (`resources/views/pdf/document.blade.php`) renders both Invoice and Quotation PDFs, parameterized by a document-type label, a secondary date label ("Due Date" vs. "Valid Until"), and the two aggregates' already-identical shape (seller, buyer, lines, total) — the two documents differ only in these few labelled fields, not in structure. Duplicating an entire layout to express that difference would itself be the premature-duplication mistake this codebase's own conventions warn against; a single parameterized template is the more disciplined choice here, not a shortcut.

## 5. Rendering contract (normative)

1. The seller identity is read from the requesting Tenant's own Business Profile (`App\Models\BusinessProfile`); a Tenant with no saved profile yet renders a placeholder ("Unknown Business") rather than failing the request — a PDF preview of an in-progress Draft may reasonably be requested before Business Profile onboarding completes.
2. The buyer identity is read from the Invoice's/Quotation's own linked Customer; a Customer that no longer resolves (a defensive case only, since both aggregates already enforce a valid Customer foreign key at creation) renders a placeholder ("Unknown Customer") for the identical reason — never a 500 error.
3. Every Money value renders via `Money::toDecimalString()` (exact decimal string), never a native float — the same representation every other export in this codebase already uses (AETS-009 §18's own rule, restated here for the identical reason).
4. A Draft Invoice (no `invoiceNumber` yet) or a Draft Quotation (no `quotationNumber` yet) renders "DRAFT" in the document-number position — never a blank, and never a fabricated number.
5. No remote resource fetch (network image, external stylesheet, external font) is permitted during rendering (`Options::set('isRemoteEnabled', false)`) — every asset the template needs is inline; this is both a security boundary (no SSRF surface from PDF rendering) and consistent with §2.2's own no-logo-upload scope.

## 6. Invariants

| ID | Invariant |
| --- | --- |
| PDF-001 | A PDF is renderable for an Invoice or Quotation in any state — no state ever produces a rendering failure. |
| PDF-002 | A Draft document (no number assigned yet) renders "DRAFT" in the document-number position, never a blank or fabricated number. |
| PDF-003 | Every Money value renders as `Money::toDecimalString()`'s exact decimal string, never a native float or a re-derived computation. |
| PDF-004 | The rendered line-item table always matches the aggregate's own current `lines()` — no cached, stale, or independently-derived line data. |
| PDF-005 | Cross-Tenant access is rejected exactly like every other Invoice/Quotation endpoint (`404`, not a distinguishable "exists but forbidden" response) — this endpoint introduces no separate authorization path. |
| PDF-006 | Rendering performs no remote resource fetch of any kind. |

## 7. Required test specification

A dedicated ATS-017 companion document is deferred for this initial slice, mirroring [AETS-015 §9](AETS-015-Evidence-Storage.md#9-required-test-specification)/[AETS-016 §7](AETS-016-Quotation.md#7-required-test-specification)'s own identical decision — the six `PDF-NNN` invariants above are proven directly by the executable test suite this document's own commit introduces (HTTP-level tests proving a valid PDF is returned for a Draft and an Issued/Sent document each, and tenant isolation), following this repository's established discipline.

## 8. Deferred decisions

- Company logo upload, letterhead, and configurable color scheme (§2.2) — a tracked future enhancement once the Founder supplies branding guidelines.
- Email delivery to the Customer (§2.2).
- PDF rendering of any financial report (§2.2) — those reports gain XLSX instead.
- MyInvois-specific rendering (QR code, digital signature) (§2.2).

## Changelog

- **0.1.1 (2026-09-17):** Bug fix, found while visually rendering a real downloaded PDF for AETS-009 §21's own new "loan-ready" export (a check no prior work had done — every existing test proved only that a valid PDF was produced, magic bytes and Content-Type, never that its text rendered correctly): the shared template's footer used a literal em-dash character, which Dompdf's base Helvetica font — not Unicode-aware — silently renders as mojibake ("hore.my â?? a computer-generated invoice") instead of failing loudly. Replaced with a plain ASCII hyphen; added a template comment warning against non-ASCII characters in the rendered HTML for the same reason. No `PDF-NNN` invariant addresses text legibility, so none is newly violated or resolved — this is a cosmetic defect, not an invariant gap. Classified **PATCH**.
- **0.1.0 (2026-09-16):** Initial Draft. Resolves AETS-009 §2.2's and AETS-016 §2.2's identical "PDF (Invoice)"/"PDF quotation documents" deferrals with one shared, minimal, functional PDF template for both — no logo/letterhead/branding, per the Founder's own explicit choice between that and waiting for branding guidelines first. Introduces `PDF-001`–`PDF-006`. Founder scope approval obtained; Accounting Domain Reviewer sign-off outstanding.
