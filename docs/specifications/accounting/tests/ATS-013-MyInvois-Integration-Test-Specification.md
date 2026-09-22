# ATS-013: MyInvois Integration Test Specification

- Status: Draft
- Version: 0.1.0
- Effective date: Not effective — pending required review
- Owner: Accounting Core (see [`CODEOWNERS`](../../../../CODEOWNERS))
- Reviewers: CTO / Technical Partner; Accounting Domain Reviewer (outstanding)
- Related: [AETS-013](../AETS-013-MyInvois-Integration.md)

## 1. Purpose

This specification proves `AETS-013` v0.1.0 — `MyInvoisCredential` storage and `UblInvoiceDocumentBuilder` only. It proves none of AETS-013 §2.2's deferred items (no live LHDN call exists yet to test against).

## 2. Test strategy

- `MyInvoisCredential` persistence, uniqueness-per-environment, secret-encryption, and tenant-isolation tests use real services and real PostgreSQL — no mocked persistence, matching this repository's established discipline.
- `UblInvoiceDocumentBuilder` is a pure function with no I/O — its tests are unit tests with no database, built against fixtures, including one golden multi-line Invoice fixture whose expected document shape is transcribed from LHDN's own published sample payload (`sdk.myinvois.hasil.gov.my/documents/invoice-v1-1/`, reviewed 2026-09-22).

## 3. Traceability matrix

| Invariant | Test IDs |
| --- | --- |
| MYI-001 | MYI-T001, MYI-T002 |
| MYI-002 | MYI-T003, MYI-T004 |
| MYI-003 | MYI-T007 |
| MYI-004 | MYI-T008, MYI-T009 |
| MYI-005 | MYI-T013 |
| MYI-006 | MYI-T005, MYI-T006 |

## 4. Test cases

### 4.1 `MyInvoisCredential` persistence (real PostgreSQL)

| ID | Proof |
| --- | --- |
| MYI-T001 | Saving a Sandbox credential persists a row scoped to the Tenant and that Environment. |
| MYI-T002 | Saving again for the same Tenant/Environment pair replaces the prior row rather than creating a second one. |
| MYI-T003 | The client secret column, read directly from the database, is never the plaintext value that was saved. |
| MYI-T004 | Reading a saved credential through the application boundary never returns the full secret — only that a credential exists, and its Client ID. |
| MYI-T005 | Saving a Sandbox credential does not alter an existing Production credential for the same Tenant, and vice versa. |
| MYI-T006 | A credential saved under one Tenant is never visible to another Tenant. |

These are methods of `MyInvoisCredentialManagementTest` (Feature, real PostgreSQL).

### 4.2 `UblInvoiceDocumentBuilder` (unit, no I/O)

| ID | Proof |
| --- | --- |
| MYI-T007 | Building a document for a `Draft` Invoice is rejected with a named exception. |
| MYI-T008 | Building a document where the Business Profile is missing its MSIC code raises a named exception identifying that field. |
| MYI-T009 | Building a document where a line is missing its MyInvois detail row raises a named exception identifying which line. |
| MYI-T010 | A built document's Supplier block matches the Business Profile's own fields exactly (name, TIN, registration number, SST registration number when present, MSIC code, address). |
| MYI-T011 | A built document's Buyer block matches the Customer's own fields exactly, including TIN. |
| MYI-T012 | A built document contains exactly one line entry per `InvoiceLine`, each carrying its own description, quantity, unit price, unit of measure, classification code, tax type, tax rate, and tax amount. |
| MYI-T013 | A built document's financial totals reconcile exactly to the Invoice's own already-computed total — no independent recomputation that could silently diverge. |
| MYI-T014 | The e-Invoice type code is always `01` and the document version is always `1.0` in this version — never a Credit/Debit/Refund Note code, never version `1.1`. |
| MYI-T015 | Building a document for a golden multi-line Invoice fixture produces every LHDN-mandatory field present and correctly shaped, matching a fixture transcribed from LHDN's own published sample payload. |

These are methods of `UblInvoiceDocumentBuilderTest` (Unit, no persistence).

## 5. Out of scope for this version

No test in this document exercises a live HTTP call to MyInvois, the outbox, or digital signature — none of that code exists yet (`AETS-013` §2.2). A future `ATS-013` version adds those test IDs once Phase 2/3 land.

## Changelog

- **0.1.0 (2026-09-22):** Initial Draft, proving AETS-013 v0.1.0 (`MyInvoisCredential` storage and `UblInvoiceDocumentBuilder` only).
