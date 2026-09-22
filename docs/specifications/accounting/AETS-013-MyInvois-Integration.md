# AETS-013: MyInvois Integration

- Status: Draft
- Version: 0.1.0
- Effective date: Not effective — pending required review
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: CTO / Technical Partner; Accounting Domain Reviewer (outstanding — same honest gap [AETS-008](AETS-008-Bank-Reconciliation.md)/[AETS-012](AETS-012-Proof-of-Accuracy.md)/[AETS-015](AETS-015-Evidence-Storage.md) each record); Founder / Product Owner (scope approval obtained 2026-09-22 — see §3)
- Related: [AETS-000](AETS-000.md) §10 (planned document, working title "MyInvois Integration"), [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §13, [ADR-0005](../../adr/0005-ai-provider-abstraction.md) (external-provider boundary pattern reused for credential/environment isolation), [ADR-0006](../../adr/0006-transactional-outbox-pattern.md) (governs the still-deferred Phase 2 live-submission producer)

## 1. Purpose

`HORE_MY_MASTER_CONTEXT.md` §13 names MyInvois — Malaysia's mandatory LHDN e-Invoice submission system — as a required integration, with its own invariants (sandbox/production isolation, submission idempotency, full payload/response audit, no duplicate submission on retry) already stated there. `AETS-000` §10 lists it as a planned document. This is that document's first slice.

**This Draft is not governing implementation and is not an accuracy or release certification.** Per this repository's established convention ([AETS-008](AETS-008-Bank-Reconciliation.md) §1), a Draft may describe and even accompany real, running code — existing code described here is evidence of current behaviour, not authority for it, until an Accounting Domain Reviewer's sign-off is recorded.

**Phased, not all at once.** MyInvois integration has a hard external prerequisite this document cannot resolve by itself: hore.my has not yet registered on LHDN's pre-production portal (`https://preprod.myinvois.hasil.gov.my/`), so no real sandbox client credentials exist yet, and no real submission can be tested end-to-end. This version (0.1.0) specifies only what can be built and proven without any live LHDN credential — later versions add live submission, status reconciliation, and digital signature once their own external prerequisites are cleared (§10).

## 2. Scope

### 2.1 In scope (this version)

- A `MyInvoisCredential` aggregate: one row per Tenant per environment (Sandbox/Production), holding the client ID/secret a future live-submission phase will use to authenticate — encrypted at rest (§5).
- The additional Malaysia-specific fields a `hore.my` Business Profile and each Invoice line must carry before a MyInvois document can be built from them at all (§6).
- A pure, no-I/O `UblInvoiceDocumentBuilder`: given an Issued Invoice plus its Customer and the issuing Tenant's Business Profile, deterministically produces a MyInvois-shaped JSON document (Invoice type `01`, document version `1.0` — unsigned) or fails closed with a named reason when required data is missing (§7).
- A credential-management HTTP boundary: a Tenant can save and view (secret always masked) their Sandbox/Production `MyInvoisCredential`.

### 2.2 Out of scope (deferred to a future version of this document)

- Any live HTTP call to MyInvois — Submit Documents, Get Submission, Get Document Details, TIN validation, Cancel/Reject, bulk cancellation. Blocked on the Founder completing LHDN pre-production portal registration to obtain real sandbox credentials.
- The transactional-outbox dispatcher/queue ([ADR-0006](../../adr/0006-transactional-outbox-pattern.md)) — this repository's first real producer/consumer of that pattern, built once Phase 2 (live submission) actually needs asynchronous, retry-safe delivery. Building it before there is a real producer would repeat the speculative-build mistake [AETS-010 §2.2](AETS-010-Audit-Trail-Evidence-Linkage.md#22-out-of-scope) already named and declined once.
- Digital signature (XAdES, document version `1.1`) — blocked on obtaining a real X.509 certificate from a Malaysian Certificate Authority, a separate per-Tenant prerequisite from sandbox portal registration.
- Credit Note, Debit Note, Refund Note, and self-billed document types (e-Invoice type codes `02`–`04`, `11`–`14`) — this version covers Invoice (`01`) only, matching this codebase's own `Invoice` aggregate; no domain model exists yet for the others.
- Any automatic/implicit submission triggered by `InvoiceIssuingService::issue()`. Confirmed by direct inspection: `Invoice`/`InvoiceIssuingService` raise no domain event today, and this document does not introduce one. Submission (once built) is a separate, explicit, user-triggered action — consistent with this codebase's existing preference for explicit named services over implicit event chains, and with this session's own standing rule against shipping UI or actions that cannot yet do anything.
- MSIC code and Tax Type code *validation* against LHDN's full published code lists (hundreds of entries each). This version stores and carries whatever code a Tenant supplies; validating it against the authoritative list is a future refinement, not a Phase 1 blocker.

## 3. Authority and Founder approval

This document resolves `AETS-000` §10's planned "MyInvois Integration" entry, within `HORE_MY_MASTER_CONTEXT.md` §13's already-stated invariants (which this document does not weaken: sandbox/production credentials are never mixed, §5; every future submission will be idempotent and auditable, §10 deferred item).

Introducing a new integration and its own credential-management UI is a product-scope decision per [AETS-000 §8.2](AETS-000.md#82-ownership-and-review), requiring Founder / Product Owner review. That approval was obtained 2026-09-22: the Founder, presented with the roadmap's next-step options (AI orchestration, MyInvois, continued hardening), explicitly chose "MyInvois (regulatory)." An Accounting Domain Reviewer's independent sign-off remains outstanding, consistent with the same honest gap [AETS-008](AETS-008-Bank-Reconciliation.md)/[AETS-012](AETS-012-Proof-of-Accuracy.md)/[AETS-015](AETS-015-Evidence-Storage.md) each already record.

## 4. External prerequisites (tracked, not resolved by this document)

| Prerequisite | Blocks | Owner |
| --- | --- | --- |
| Register hore.my (or each Tenant business) on LHDN's pre-production portal (`https://preprod.myinvois.hasil.gov.my/`) to obtain a Sandbox client ID/secret. | Every §2.2 deferred item — real submission cannot be tested without this. | Founder / Product Owner |
| Obtain an X.509 certificate from a Malaysian CA (e.g. Pos Digicert, MSC Trustgate) with the required Distinguished Name fields, Non-Repudiation key usage, and Document Signing extended key usage. | Digital signature (document version `1.1`). | Founder / Product Owner, per-Tenant in production |
| Separately register for LHDN production access once sandbox testing passes. | Any real, non-test document submission. | Founder / Product Owner |

This document itself neither performs nor unblocks any of these — they are real-world administrative actions outside hore.my's codebase.

## 5. `MyInvoisCredential` aggregate

| Field | Requirement |
| --- | --- |
| Tenant | The owning Tenant. Immutable. |
| Environment | `Sandbox` or `Production` — a closed enum, never inferred, never mixed (`HORE_MY_MASTER_CONTEXT.md` §13). |
| Client ID | As issued by the MyInvois Portal for that Tenant/environment pair. |
| Client secret | Stored via encryption at rest — this repository's first use of that mechanism (`Crypt`/Eloquent `encrypted` cast are used nowhere else today). Never returned in full by any read boundary; a read shows only that a credential exists and its Client ID, never the secret. |

**Uniqueness.** At most one `MyInvoisCredential` row per (Tenant, Environment) pair — saving again for the same pair replaces the prior row (a credential is rotated, not versioned/kept).

## 6. Data model additions

Confirmed by direct inspection that these fields do not exist anywhere in the domain today (`Invoice`, `InvoiceLine`, `BusinessProfile`, `Customer`):

| Owner | New field | Why MyInvois requires it |
| --- | --- | --- |
| Business Profile | MSIC code | 5-digit mandatory Supplier business-nature code. |
| Business Profile | SST registration number | Mandatory when the Supplier is SST-registered; optional otherwise. |
| Per Invoice line (new, MyInvois-specific — see §6.1) | Tax type code | One of `01`–`06`/`E` (§8). |
| Per Invoice line | Tax rate | Percentage applied for the line's Tax type. |
| Per Invoice line | Tax amount | The computed tax for that line, carried explicitly (never re-derived silently — mirrors `InvoiceLine::hasConsistentLineAmount()`'s own "recompute and compare, never trust blindly" discipline). |
| Per Invoice line | Classification code | LHDN's product/service classification code for that line. |
| Per Invoice line | Unit of measure | A UN/ECE Rec 20 code (e.g. `EA` for each). |

Customer already carries a Tax Identification Number (`Customer::taxIdentificationNumber()`) — the Buyer TIN requirement is already satisfied.

### 6.1 Why these live outside `InvoiceLine`, not inside it

`InvoiceLine` (`app/Domain/Invoicing/InvoiceLine.php`) is a tightly invariant-checked Value Object that flows directly into ledger posting (`InvoiceToPostingCommandTranslator`) and carries its own re-derivation check (`hasConsistentLineAmount()`). None of the fields above affect what gets posted to the ledger — SST/e-Invoice metadata is a MyInvois submission concern, not an accounting-posting concern, and this codebase has no Malaysian-SST ledger treatment (a dedicated tax-liability Account) today. Extending the core `InvoiceLine` constructor would touch the posting pipeline for data the posting pipeline never needs, widening this change's blast radius into code the existing 1918-test backend suite exercises heavily for reasons unrelated to MyInvois.

This version instead adds a separate, MyInvois-owned table keyed by `(invoice_id, line_position)`, populated optionally alongside Invoice line creation and read only by `UblInvoiceDocumentBuilder` (§7). `InvoiceLine` itself, `InvoiceToPostingCommandTranslator`, and `InvoiceIssuingService` are unchanged by this document.

## 7. `UblInvoiceDocumentBuilder`

A pure domain service — no HTTP, no database read inside it; its caller assembles the Invoice, its lines, the per-line MyInvois detail (§6.1), the Customer, and the issuing Tenant's Business Profile, and passes them in.

**Input:** an Issued `Invoice` (rejects `Draft` — a document cannot be built for something that never posted), its `InvoiceLine[]`, the matching MyInvois line-detail rows (§6.1) keyed by position, the `Customer`, and the `BusinessProfile`.

**Output:** a MyInvois v1.0 (unsigned) JSON document array shaped per LHDN's published Invoice schema — e-Invoice type `01`, Supplier block (name, TIN, registration/ID number, SST registration number if present, MSIC code, address, contact), Buyer block (name, TIN, registration/ID number, address, contact), one line per `InvoiceLine` (description, quantity, unit price, unit of measure, classification code, tax type, tax rate, tax amount, subtotal, line total), and header/financial totals reconciling exactly to the Invoice's own already-computed amounts.

**Fails closed, never emits a partial or best-guess document.** If any mandatory field named in §6 is missing for the given Invoice/Tenant — a per-line MyInvois detail row absent, a Business Profile missing its MSIC code, etc. — the builder raises a named exception identifying exactly which field is missing, rather than omitting the field or substituting a placeholder. A caller decides what to do with that failure (this version does not yet expose an HTTP endpoint that calls the builder — that is Phase 2, alongside the submit action itself, §2.2).

## 8. Reference code tables (LHDN-published, transcribed for `MyInvoisTaxType`/`MyInvoisDocumentType` enums)

| Tax Type code | Meaning |
| --- | --- |
| `01` | Sales Tax |
| `02` | Service Tax |
| `03` | Tourism Tax |
| `04` | High-Value Goods Tax |
| `05` | Sales Tax on Low Value Goods |
| `06` | Not Applicable |
| `E` | Tax exemption (where applicable) |

| e-Invoice Type code | Meaning | Modeled in this version? |
| --- | --- | --- |
| `01` | Invoice | Yes |
| `02` | Credit Note | No (§2.2) |
| `03` | Debit Note | No (§2.2) |
| `04` | Refund Note | No (§2.2) |
| `11`–`14` | Self-billed variants | No (§2.2) |

Source: LHDN MyInvois SDK, `sdk.myinvois.hasil.gov.my/codes/` (reviewed 2026-09-22, per this document's own §1 requirement to check current official documentation before implementation).

## 9. Invariants

| ID | Invariant |
| --- | --- |
| MYI-001 | At most one `MyInvoisCredential` exists per (Tenant, Environment) pair; saving again replaces the prior row for that pair. |
| MYI-002 | A `MyInvoisCredential`'s client secret is encrypted at rest and is never returned in full by any read boundary. |
| MYI-003 | `UblInvoiceDocumentBuilder` never builds a document for a `Draft` Invoice — only `Issued`. |
| MYI-004 | `UblInvoiceDocumentBuilder` never emits a document with a missing mandatory field silently substituted or omitted — a missing required field is a raised, named exception, not a partial document. |
| MYI-005 | A built document's financial totals reconcile exactly to the source Invoice's own already-computed line/total amounts — no independent recomputation that could silently diverge. |
| MYI-006 | Saving a `MyInvoisCredential` for one Environment never reads from or writes to the other Environment's row for the same Tenant. |

## 10. Required test specification

A companion `ATS-013` document is required (unlike `AETS-015`'s initial slice, this module is already known to grow across multiple future phases) — see [`ATS-013`](tests/ATS-013-MyInvois-Integration-Test-Specification.md).

## 11. Deferred decisions

- Live submission, status polling/reconciliation, TIN validation, cancel/reject — Phase 2, blocked on §4's sandbox portal registration.
- The outbox dispatcher/queue mechanics Phase 2 will need ([ADR-0006](../../adr/0006-transactional-outbox-pattern.md) still names no table schema, dispatcher library, or retry interval — Phase 2 is what actually decides these).
- Digital signature (XAdES/X.509, document version `1.1`) — Phase 3, blocked on §4's certificate prerequisite.
- Credit Note/Debit Note/Refund Note/self-billed document types.
- Validating a Tenant-supplied MSIC/Tax Type/Classification code against LHDN's full authoritative code list.
- Whether `MyInvoisCredential` should eventually move to a hardware-backed secret store instead of an application-level encrypted column, if a future security review requires it.

## Changelog

- **0.1.0 (2026-09-22):** Initial Draft. Resolves `AETS-000` §10's planned "MyInvois Integration" entry with a first, deliberately narrow slice: `MyInvoisCredential` storage (encrypted, environment-isolated) and a pure `UblInvoiceDocumentBuilder` producing an unsigned MyInvois v1.0 Invoice document from an Issued Invoice — no live LHDN call, no outbox, no digital signature. Founder scope approval obtained 2026-09-22; Accounting Domain Reviewer sign-off outstanding.
