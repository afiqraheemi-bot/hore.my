# AETS-016: Quotation

- Status: Draft
- Version: 0.2.0
- Effective date: Not effective — pending required review
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: CTO / Technical Partner; Accounting Domain Reviewer (outstanding — same honest gap [AETS-008](AETS-008-Bank-Reconciliation.md)/[AETS-012](AETS-012-Proof-of-Accuracy.md)/[AETS-015](AETS-015-Evidence-Storage.md) each record); Founder / Product Owner (scope approval obtained 2026-09-16 — see §3)
- Related: [AETS-000](AETS-000.md), [AETS-001](AETS-001-Accounting-Terminology.md) §"Terms referenced but not yet formally defined" — the entry this document resolves; [AETS-007](AETS-007-Posting-Command.md) — Quotation produces no Posting Command and is never listed there; [ADR-0001](../../adr/0001-modular-monolith-architecture.md) — capability 6, "Customers, Quotations, and Invoicing"

## 1. Purpose

[AETS-001 §"Terms referenced but not yet formally defined"](AETS-001-Accounting-Terminology.md) names *Quotation* as a term deferred "until the AETS document that first needs them is written." This is that document. It defines a Quotation as a pre-sale document a Tenant issues to a Customer, structurally parallel to the existing `Invoice` aggregate (`App\Domain\Invoicing\Invoice`, undocumented by any AETS document of its own but the established implementation template this one follows), with one deliberate difference: **a Quotation never produces a ledger effect.** It has no Posting Command, no Journal, and no Account references of its own — it exists purely as a structured, trackable pre-sale record until a Customer accepts it, at which point it may be converted into a real, Account-bearing Draft Invoice.

**This Draft is not governing implementation and is not an accuracy or release certification.** Per this repository's own established convention ([AETS-008](AETS-008-Bank-Reconciliation.md) §1), a Draft may describe and even accompany real, running code — existing code described here is evidence of current behaviour, not authority for it, until an Accounting Domain Reviewer's sign-off is recorded.

## 2. Scope

### 2.1 In scope

- One Quotation aggregate: Tenant, Customer, quotation number (assigned at Send, not before), lifecycle status, issue date, a validity date, a list of Quotation Lines, and a cached total.
- The Quotation lifecycle: `Draft` → `Sent` → `Accepted`/`Rejected`, and `Accepted` → `Converted` (§4).
- Converting an `Accepted` Quotation into a new `Draft` Invoice (`InvoiceIssuingService`'s own sibling concern — conversion produces a Draft, never an Issued, Invoice; issuing that Invoice is the existing, unmodified [Invoice] flow).
- A real HTTP boundary: list, show, create, edit (Draft only), delete (Draft only), send, accept, reject, and convert-to-invoice.

### 2.2 Out of scope

- Any ledger effect of a Quotation itself. A Quotation is never translated into a Posting Command and never appears in any Accounting Core report — this is the one structural fact separating it from `Invoice`.
- A "quotation expiring automatically" state transition. No scheduler/cron infrastructure exists in this codebase to drive one honestly; this document instead exposes the validity date on every read so a caller can compute staleness itself, rather than inventing a state transition nothing actually triggers.
- Letterhead/branding (company logo, configurable color scheme) and email delivery to the Customer — each remains a Founder-level product/branding decision, not an engineering default to guess. **A minimal, functional PDF export itself is resolved** — see [AETS-017](AETS-017-Document-PDF-Export.md), added 2026-09-16 under the identical Founder approval this section's own deferral had been waiting on.
- Editing or re-sending an already-`Sent` Quotation. A rejected or stale Quotation is superseded by issuing a new one, never edited in place — mirroring `Invoice`'s own "only Draft is editable" rule and this project's append-only convention generally.
- MyInvois / e-invoice submission of any kind — a Quotation is pre-sale and outside MyInvois' scope entirely.

## 3. Authority and Founder approval

Introducing a new Tenant-facing business document type is a user-experience and product-scope change per [AETS-000 §8.2](AETS-000.md#82-ownership-and-review), requiring Founder / Product Owner review. That approval was obtained 2026-09-16: the Founder's own roadmap message named "Quotation, compliance pack dan export menyeluruh" as the next item to build, and explicitly instructed "jalankan #6" (run/execute #6). An Accounting Domain Reviewer's independent sign-off remains outstanding, consistent with the same honest gap [AETS-008](AETS-008-Bank-Reconciliation.md)/[AETS-012](AETS-012-Proof-of-Accuracy.md)/[AETS-015](AETS-015-Evidence-Storage.md) each already record — this document introduces no new ledger/Posting Command behavior (§2.2), but AETS-000 §8.2 names that review unconditionally and this document does not claim an exception to it.

## 4. Quotation lifecycle (normative)

| State | Meaning |
| --- | --- |
| `Draft` | Mutable. No number assigned yet. Editable and deletable. |
| `Sent` | Immutable line/total content; a Quotation number has been assigned. Awaiting the Customer's decision. |
| `Accepted` | The Customer agreed. Convertible into a Draft Invoice. Not itself terminal — see `Converted`. |
| `Rejected` | The Customer declined, or the Tenant withdrew it. Terminal. |
| `Converted` | A Draft Invoice has been created from this Quotation (§5); `convertedInvoiceId` is set. Terminal. |

Allowed transitions: `Draft`→`Sent`; `Sent`→`Accepted`; `Sent`→`Rejected`; `Accepted`→`Rejected`; `Accepted`→`Converted`. No transition outside this table is valid.

## 5. Conversion to Invoice (normative)

Converting an `Accepted` Quotation into an Invoice:

1. requires the caller to supply a receivable Account (Asset type), a revenue Account (Revenue type), and a due date — a Quotation carries none of these, since it has no ledger effect (§2.2), and `Invoice::draft()` requires them;
2. constructs a new `Invoice` in the `Draft` state (never `Issued`) from the Quotation's own Customer and Lines, translated 1:1 into `InvoiceLine`s;
3. is one atomic operation: the new Invoice is saved and the Quotation is marked `Converted` with `convertedInvoiceId` set, or neither happens;
4. takes a pessimistic row lock on the Quotation before checking its state, mirroring `InvoiceIssuingService`'s own established reasoning (its docblock, "Concurrency") — two concurrent conversion attempts against the same `Accepted` Quotation must never both succeed and must never both create an Invoice.
5. does not touch Accounting Core in any way — the resulting Invoice is a `Draft`, with zero ledger effect, exactly as if a human had created it by hand through the existing Invoice creation endpoint. Issuing it afterward is the ordinary, unmodified Invoice-issuing flow this document does not alter.

## 6. Invariants

| ID | Invariant |
| --- | --- |
| QUO-001 | Only the transitions listed in §4 are valid; an attempt at any other transition is rejected and leaves the Quotation's state unchanged. |
| QUO-002 | Only a `Draft` Quotation is editable or deletable. |
| QUO-003 | A Quotation number is assigned exactly once, atomically, at `Send` — never at creation, never reassigned. |
| QUO-004 | `Send` requires at least one Line and requires the validity date not to precede the issue date, mirroring `Invoice::issue()`'s own due-date check. |
| QUO-005 | A Quotation's cached total always equals the sum of its own Lines' `lineAmount`s; a reconstituted Quotation whose persisted total or any Line's persisted `lineAmount` disagrees with its own recomputation fails closed, mirroring `Invoice::reconstitute()`'s own 2026-09-11 audit remediation. |
| QUO-006 | A Quotation never produces a Posting Command, a Journal, or any Accounting Core report entry. |
| QUO-007 | Converting an `Accepted` Quotation into a Draft Invoice (§5) is one atomic operation — either both the new Invoice and the Quotation's own `Converted` state persist, or neither does. |
| QUO-008 | Two concurrent conversion attempts against the same `Accepted` Quotation never both succeed — proven by a genuine two-process concurrency test, mirroring `InvoiceIssuingService`'s own established requirement. |
| QUO-009 | Every Quotation and its Lines are Tenant-isolated; a cross-Tenant read fails closed as not found, and every foreign key (Customer, converted Invoice) is a composite `(tenant_id, …)` reference. |

## 7. Required test specification

A dedicated ATS-016 companion document is deferred for this initial slice, mirroring [AETS-015 §9](AETS-015-Evidence-Storage.md#9-required-test-specification)'s own identical decision — the nine `QUO-NNN` invariants above are proven directly by the executable test suite this document's own commit introduces (Unit, real-PostgreSQL Integration including a genuine two-process concurrency proof for QUO-008, HTTP, and Migration levels), following this repository's established real-PostgreSQL, no-mocked-persistence discipline.

## 8. Deferred decisions

- Automatic expiry of a stale `Sent` Quotation (§2.2) — no scheduler exists to drive it honestly.
- Branded Quotation documents (logo, color scheme) and Customer email delivery (§2.2) — Founder-level product decisions; a minimal/functional PDF itself is resolved, see [AETS-017](AETS-017-Document-PDF-Export.md).
- Rich Quotation revision history (a superseded-Quotation linking mechanism, mirroring [WTS-001](../workspace/WTS-001-Task-Proposal-State-Model.md) `TSK-014`'s own correction link) — not built here; a rejected or stale Quotation today is simply replaced by issuing a new, unlinked one.

## Changelog

- **0.2.0 (2026-09-16):** Same-day follow-up: a minimal, functional Quotation PDF export is now resolved by the new [AETS-017](AETS-017-Document-PDF-Export.md), under the identical Founder approval this document's own §2.2 PDF deferral had been waiting on. §2.2 and §8 reworded to state precisely what remains deferred (branding/letterhead, email delivery) versus what is now built (the PDF itself). No `QUO-NNN` invariant changed. Classified **MINOR**.
- **0.1.0 (2026-09-16):** Initial Draft. Resolves AETS-001's own named "Quotation" terminology deferral with a real, tenant-scoped Quotation aggregate, a five-state lifecycle producing zero ledger effect, and an atomic, concurrency-safe conversion path into a Draft Invoice. Founder scope approval obtained ("jalankan #6"); Accounting Domain Reviewer sign-off outstanding.
