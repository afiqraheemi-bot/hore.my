# AETS-001: Accounting Terminology

- Status: Active
- Version: 1.1.0
- Effective date: 2026-09-03
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-000](AETS-000.md), [AETS-002](AETS-002-Accounting-Invariants.md); [ADR-0001](../../adr/0001-modular-monolith-architecture.md), [ADR-0004](../../adr/0004-financial-integrity-principles.md), [ADR-0005](../../adr/0005-ai-provider-abstraction.md), [ADR-0006](../../adr/0006-transactional-outbox-pattern.md), [ADR-0007](../../adr/0007-money-representation-strategy.md); [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md)

## 1. Purpose

This document is the canonical accounting terminology (glossary) for hore.my's Accounting Core. It gives every term used across the AETS series, the codebase, and accounting-facing product surfaces one precise, non-negotiable meaning, so that later AETS documents, database schemas, code identifiers, and product copy can all refer to the same concept without re-defining it or drifting apart.

Consistent terminology is a cross-cutting concern every AETS document depends on. It is deliberately kept separate from [AETS-000](AETS-000.md) (architecture and governance) and [AETS-002](AETS-002-Accounting-Invariants.md) (non-negotiable invariants): this document says what a word *means*; AETS-002 says what must always be *true*. A term's definition here must be consistent with every invariant in AETS-002 (§4, Normative Terminology Rules), but this document does not itself impose new constraints — it only names concepts that AETS-000, AETS-002, and later AETS documents already rely on.

This document supersedes the placeholder that previously occupied `AETS-001` and contains no accounting algorithm, schema, or design decision — only definitions.

## 2. Scope

### 2.1 In scope

- Canonical definitions for the accounting domain terms listed in §5, covering the vocabulary needed to read [AETS-000](AETS-000.md), [AETS-002](AETS-002-Accounting-Invariants.md), the accepted ADRs, and every currently planned AETS document (see [AETS-000 §10](AETS-000.md#10-planned-document-structure)).
- Terminology usage rules (§4) that govern how these terms are used consistently across specifications, code, databases, and UI copy.

### 2.2 Out of scope

Consistent with the constraints on this task and with [AETS-000](AETS-000.md) §1–2, this document does **not**:

- define any accounting algorithm, calculation, matching rule, or workflow;
- restate or narrow the Money persistence decision — the canonical representation (integer minor units, PostgreSQL `BIGINT`) is settled by [ADR-0007](../../adr/0007-money-representation-strategy.md)'s Founder-approved amendment; formalizing the full Money domain value object contract remains AETS-005's task, per [AETS-000 §10](AETS-000.md#10-planned-document-structure);
- design the Posting Engine, journal/line schema, or transaction mechanics — deferred to AETS-004;
- design the Chart of Accounts or account taxonomy — deferred to AETS-003;
- change, add, or remove any invariant in [AETS-002](AETS-002-Accounting-Invariants.md); or
- introduce any new architecture decision. Where a definition below touches something an ADR already decided, it cites that ADR rather than restating or reinterpreting it.

## 3. Authority

This document is subordinate to the authority hierarchy defined in [AETS-000 §3](AETS-000.md#3-authority-hierarchy): Founder instruction, then `HORE_MY_PROJECT_INSTRUCTIONS.txt`, then `HORE_MY_MASTER_CONTEXT.md`, then the SRS, then the Detailed Project Proposal, then accepted ADRs, then AETS documents. Where a definition in this document appears to conflict with any higher-precedence source, the higher source governs and this document must be corrected, per [AETS-000 §8.4](AETS-000.md#84-relationship-to-adrs-and-conflict-handling).

Every definition below is traceable to one or more of: [ADR-0001](../../adr/0001-modular-monolith-architecture.md), [ADR-0004](../../adr/0004-financial-integrity-principles.md), [ADR-0005](../../adr/0005-ai-provider-abstraction.md), [ADR-0006](../../adr/0006-transactional-outbox-pattern.md), [ADR-0007](../../adr/0007-money-representation-strategy.md), [AETS-000](AETS-000.md), [AETS-002](AETS-002-Accounting-Invariants.md), or [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md). None introduces meaning beyond what those sources already establish.

## 4. Normative Terminology Rules

1. **One concept = one canonical term.** Each concept in §5 has exactly one canonical name. A concept is never given two different names across AETS documents, schemas, or code.
2. **UI labels may differ; domain terminology is authoritative.** Product copy and UI labels may use friendlier or localized wording (including Bahasa Malaysia, per [`HORE_MY_PROJECT_INSTRUCTIONS.txt`](../../product/reference/HORE_MY_PROJECT_INSTRUCTIONS.txt)) for end users, but the underlying concept must map to exactly one term defined here. A UI label is a presentation choice, never a redefinition.
3. **Database names and code identifiers should align with this glossary** unless a documented technical reason requires otherwise (for example, a framework or database reserved word). Any such deviation should be recorded as a comment at the point of deviation, not silently.
4. **Avoid ambiguous use of "Transaction."** "Transaction" alone is never used as an accounting domain term in AETS documents, schemas, or code identifiers, because it collides with the database concept of a transaction (ADR-0004, ADR-0006). Use the precise term instead:
   - **Business Transaction** — a real-world economic event;
   - **Bank Transaction** — a bank-reported line item; or
   - **Journal** — the posted double-entry accounting record.
   ("Database transaction," referring to an atomic PostgreSQL commit/rollback boundary as used in [ADR-0004](../../adr/0004-financial-integrity-principles.md) and [ADR-0006](../../adr/0006-transactional-outbox-pattern.md), is a distinct, already-established technical term and is exempt from this rule — it is not an accounting domain concept.)
5. **Definitions must not contradict [AETS-002](AETS-002-Accounting-Invariants.md).** Every definition in §5 has been checked against all 14 invariants and the release-blocker rule; see §7, Validation.
6. **Future AETS documents must reuse these terms instead of redefining them.** A later AETS document that needs one of the terms in §5 links to its entry here rather than restating or narrowing its meaning. If a later document finds a term here insufficient, that is a defect in this document to be corrected here (§9, Versioning of [AETS-000](AETS-000.md)), not a reason to define a competing term elsewhere.

## 5. Accounting Terminology

Terms are listed alphabetically. Each entry uses exactly: Term, Normative Definition, Notes / Boundaries, Related Terms.

### Account

- **Term:** Account
- **Normative Definition:** A named classification within hore.my's chart of accounts, to which Journal Lines post, representing one category of asset, liability, equity, income, or expense.
- **Notes / Boundaries:** The structure, taxonomy, numbering, and ownership rules for accounts are defined by a later document (AETS-003, Chart of Accounts & Account Taxonomy) and are not defined here. Every Account belongs to exactly one Tenant.
- **Related Terms:** Journal Line, Debit, Credit, Balance, Tenant.

### Accounting Command

- **Term:** Accounting Command
- **Normative Definition:** A validated instruction accepted by Accounting Core that results in a deterministic accounting effect — for example, Posting a Journal, or executing a Reversal or Replacement. Every Accounting Command carries an Idempotency Key.
- **Notes / Boundaries:** Distinct from an Accounting Proposal: a proposal is candidate input; a command is what Accounting Core actually accepts and executes after its own deterministic validation, regardless of whether the candidate input originated from a person or an accepted AI proposal ([ADR-0005](../../adr/0005-ai-provider-abstraction.md); [AETS-000 §5](AETS-000.md#5-ai-philosophy)). The concrete set of commands and how they are handled is defined by a later document (AETS-004, Journal & Posting Model) and is not defined here.
- **Related Terms:** Accounting Proposal, Idempotency Key, Posting, Actor.

### Accounting Period

- **Term:** Accounting Period
- **Normative Definition:** A defined, bounded span of time against which posted Journals are grouped for reporting and closing, and which is either Open (accepting ordinary Posting) or Closed (rejecting ordinary Posting).
- **Notes / Boundaries:** A closed period rejects ordinary posting; reopening it is explicit, reasoned, verified, and audited ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 6). Period lifecycle and closing mechanics are defined by a later, currently planned document ("Period Management & Closing," [AETS-000 §10](AETS-000.md#10-planned-document-structure)) and are not defined here.
- **Related Terms:** Journal, Posting, Ledger.

### Accounting Proposal

- **Term:** Accounting Proposal
- **Normative Definition:** A structured, candidate accounting suggestion — amount, date, category, account, evidence, and confidence — produced by interpretation, extraction, classification, or AI, and not yet accepted as an Accounting Command.
- **Notes / Boundaries:** A proposal has no ledger effect on its own. Accounting Core revalidates a proposal exactly as it would any other candidate input before it can become an Accounting Command; an AI-originated proposal carries no special authority ([ADR-0005](../../adr/0005-ai-provider-abstraction.md); [AETS-000 §5](AETS-000.md#5-ai-philosophy)). In this glossary, "proposal" always means Accounting Proposal.
- **Related Terms:** Accounting Command, Evidence, Actor.

### Actor

- **Term:** Actor
- **Normative Definition:** The identified party — a human user, an operator, or a system process acting under explicit authorization — responsible for a material action, recorded on the resulting Audit Event.
- **Notes / Boundaries:** AI and other proposal-producing processes may be recorded as the originator of an Accounting Proposal, but only an authorized Actor's confirmation, or a deterministic Accounting Core process, can accept an Accounting Command ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 13). Operator support access is read-restricted and is not an Actor capable of mutating ledger records directly ([ADR-0004](../../adr/0004-financial-integrity-principles.md)).
- **Related Terms:** Audit Event, Accounting Command, Tenant.

### Allocation

- **Term:** Allocation
- **Normative Definition:** The assignment of a specific amount of a payment (or other applicable credit) against a specific unallocated amount owed on an invoice or other receivable, reducing that unallocated amount by the allocated amount.
- **Notes / Boundaries:** An allocation cannot exceed the unallocated amount and cannot be consumed twice ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 7). Allocation algorithms and matching mechanics are defined by a later, currently planned document ("Invoicing & Payment Allocation," [AETS-000 §10](AETS-000.md#10-planned-document-structure)) and are not defined here.
- **Related Terms:** Balance, Reconciliation, Business Transaction.

### Audit Event

- **Term:** Audit Event
- **Normative Definition:** An append-only record of a material action, capturing at minimum the Actor, Tenant, source, time, and applicable policy or model version, linked to the action it records.
- **Notes / Boundaries:** Every material action requires an Audit Event, and its commit is atomic with the domain change it records ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariants 2 and 12). The detailed audit event schema is defined by a later, currently planned document ("Audit Trail & Evidence Linkage," [AETS-000 §10](AETS-000.md#10-planned-document-structure)) and is not defined here.
- **Related Terms:** Actor, Evidence, Tenant, Posting.

### Balance

- **Term:** Balance
- **Normative Definition:** The net amount an Account holds at a point in time; or, describing a Journal, the state in which total Debit equals total Credit exactly.
- **Notes / Boundaries:** The authoritative balance of an Account is always derived from posted Journals in the Ledger; a Projection may present a balance for convenience but is never itself authoritative and must be rebuildable from the Ledger ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 5). "Balanced" describes a Journal satisfying invariant 1; it does not describe an Account.
- **Related Terms:** Account, Journal, Ledger, Projection, Debit, Credit, Trial Balance.

### Bank Transaction

- **Term:** Bank Transaction
- **Normative Definition:** A single dated line item as recorded by a bank on a bank statement or bank data feed, representing one bank-reported movement of funds, before it is matched, reconciled, or posted within hore.my.
- **Notes / Boundaries:** A Bank Transaction is external, bank-sourced data — it is not a Journal and has no accounting effect until reconciliation or posting links it to one. The same Bank Transaction cannot be posted twice for the same economic event ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 8). See §4, rule 4, on why "Transaction" alone is never used.
- **Related Terms:** Business Transaction, Reconciliation, Source Fingerprint, Journal.

### Business Transaction

- **Term:** Business Transaction
- **Normative Definition:** A real-world economic event in the user's business — a sale, purchase, expense, payment, or similar occurrence — that may give rise to one or more Journals once interpreted and posted.
- **Notes / Boundaries:** A Business Transaction is a real-world occurrence, not itself a ledger record; its accounting effect exists only once represented by a posted Journal. See §4, rule 4, on why "Transaction" alone is never used.
- **Related Terms:** Bank Transaction, Journal, Accounting Proposal, Evidence.

### Credit

- **Term:** Credit
- **Normative Definition:** The right-hand side of double-entry accounting; an amount posted to the credit side of a Journal Line, per standard double-entry convention for the relevant Account type.
- **Notes / Boundaries:** A Credit has no independent meaning outside a Journal Line — it exists only paired with Debits such that a Journal's total debit equals total credit exactly ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 1). Which Account types normally carry credit balances is chart-of-accounts design (AETS-003, not yet created) and is not defined here.
- **Related Terms:** Debit, Journal Line, Balance, Account.

### Debit

- **Term:** Debit
- **Normative Definition:** The left-hand side of double-entry accounting; an amount posted to the debit side of a Journal Line, per standard double-entry convention for the relevant Account type.
- **Notes / Boundaries:** Symmetric to Credit; a Debit has no independent meaning outside a Journal Line, and which Account types normally carry debit balances is chart-of-accounts design (AETS-003, not yet created) and is not defined here.
- **Related Terms:** Credit, Journal Line, Balance, Account.

### Evidence

- **Term:** Evidence
- **Normative Definition:** The original source material — a receipt, invoice, bank statement, or other document or file — that supports a Business Transaction, Accounting Proposal, or posted Journal, retained with its original file, hash, uploader, and timestamp.
- **Notes / Boundaries:** Evidence must never be invented by AI ([ADR-0005](../../adr/0005-ai-provider-abstraction.md); [AETS-000 §5](AETS-000.md#5-ai-philosophy)). A posted Journal's evidence linkage commits atomically with the Journal ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 2). Evidence retention and storage mechanics are defined elsewhere and are not defined here.
- **Related Terms:** Business Transaction, Accounting Proposal, Journal, Audit Event.

### Idempotency Key

- **Term:** Idempotency Key
- **Normative Definition:** A caller-supplied or deterministically derived identifier attached to a material Accounting Command such that repeating or concurrently retrying the same command with the same key returns the original economic result rather than creating a second effect.
- **Notes / Boundaries:** Required on every material command ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 3). Its derivation and storage mechanism is defined by a later document (AETS-004) and is not defined here. Distinct from a Source Fingerprint, which identifies duplicate source data rather than a duplicate command.
- **Related Terms:** Source Fingerprint, Accounting Command, Posting.

### Journal

- **Term:** Journal
- **Normative Definition:** The atomic, posted unit of double-entry accounting: a set of at least two balanced Journal Lines representing one accounting effect, together with its linked evidence and audit event.
- **Notes / Boundaries:** A posted Journal is append-only and can never be updated or deleted through the application; correction uses a Reversal and, where needed, a Replacement ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 4). "Journal" is the only term used in this glossary for a posted double-entry accounting record — see §4, rule 4.
- **Related Terms:** Journal Line, Posting, Ledger, Reversal, Replacement, Balance.

### Journal Line

- **Term:** Journal Line
- **Normative Definition:** One Debit or Credit entry within a Journal, referencing exactly one Account and carrying a Money amount.
- **Notes / Boundaries:** A Journal Line never exists outside a Journal; a Journal requires at least two lines whose debits and credits sum exactly equal ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 1). Journal line schema (columns, keys, constraints) is defined by a later document (AETS-004) and is not defined here.
- **Related Terms:** Journal, Debit, Credit, Account, Money.

### Ledger

- **Term:** Ledger
- **Normative Definition:** The complete, authoritative set of all posted Journals for a Tenant, from which every Account Balance, Trial Balance, and financial report is derived.
- **Notes / Boundaries:** The Ledger is the single source of financial truth; a Projection is derived from it and must be rebuildable from it ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 5). "Ledger" refers to the posted record set as a whole, not to any single Journal or Account.
- **Related Terms:** Journal, Balance, Trial Balance, Projection, Tenant.

### Money

- **Term:** Money
- **Normative Definition:** An amount paired with an explicit currency and an explicit minor-unit scale, representing a monetary value using one of the exact representations permitted for hore.my.
- **Notes / Boundaries:** Binary floating point is prohibited for any monetary storage, calculation, comparison, aggregation, posting, reconciliation, or export ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 10; [ADR-0007](../../adr/0007-money-representation-strategy.md)). MVP currency is MYR at two decimal places. **The canonical representation is integer minor units**, stored as PostgreSQL `BIGINT`, per ADR-0007's Founder-approved amendment; the full Money domain value object contract (Currency, MinorUnits, and related types) is formalized separately in AETS-005 (Money Representation Design).
- **Related Terms:** Journal Line, Debit, Credit, Balance.

### Posting

- **Term:** Posting
- **Normative Definition:** The act, performed exclusively by Accounting Core, of committing a Journal to the Ledger as one atomic database transaction together with its evidence linkage, audit event, and any required outbox event.
- **Notes / Boundaries:** Only a validated Accounting Command accepted through deterministic Accounting Core controls can post; AI and proposal-producing modules cannot post ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariants 2 and 13). Posting mechanics (schema, transaction boundaries, concurrency handling) are defined by a later document (AETS-004) and are not defined here.
- **Related Terms:** Journal, Accounting Command, Ledger, Idempotency Key.

### Projection

- **Term:** Projection
- **Normative Definition:** Any derived, presentational view of ledger state — a balance, report, dashboard figure, or cached summary — computed from posted Journals for convenience or performance.
- **Notes / Boundaries:** A Projection is never authoritative and must always be rebuildable from the Ledger; it cannot substitute for the Ledger as a source of truth ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 5).
- **Related Terms:** Ledger, Balance, Trial Balance.

### Reconciliation

- **Term:** Reconciliation
- **Normative Definition:** The process of matching Bank Transactions against posted Journals (directly or via existing accounting records) to confirm that bank-reported and ledger-reported movements of funds agree.
- **Notes / Boundaries:** A completed Reconciliation has RM0.00 unexplained difference ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 9); the same Bank Transaction cannot be posted twice for the same economic event (invariant 8). Matching algorithms and workflow are defined by a later, currently planned document ("Bank Import & Reconciliation," [AETS-000 §10](AETS-000.md#10-planned-document-structure)) and are not defined here.
- **Related Terms:** Bank Transaction, Journal, Source Fingerprint.

### Replacement

- **Term:** Replacement
- **Normative Definition:** A new Journal, linked to an original posted Journal via its Reversal, that records the corrected accounting effect after that original Journal has been reversed.
- **Notes / Boundaries:** Used together with Reversal to correct a posted Journal without editing or deleting it; the original record and the reason for correction remain visible ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 4). Replacement mechanics are defined by a later, currently planned document ("Correction Model," [AETS-000 §10](AETS-000.md#10-planned-document-structure)) and are not defined here.
- **Related Terms:** Reversal, Journal, Audit Event.

### Reversal

- **Term:** Reversal
- **Normative Definition:** A new Journal, linked to an original posted Journal, that mirrors the original Journal's effect in the opposite direction, neutralizing its financial effect without altering or deleting it.
- **Notes / Boundaries:** Every correction of a posted Journal begins with a Reversal; a Replacement follows only where a corrected effect is also needed ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 4). Reversal mechanics are defined by a later, currently planned document ("Correction Model," [AETS-000 §10](AETS-000.md#10-planned-document-structure)) and are not defined here.
- **Related Terms:** Replacement, Journal, Audit Event.

### Source Fingerprint

- **Term:** Source Fingerprint
- **Normative Definition:** A derived identifier computed from the content or origin of source data (for example, an imported bank line or an uploaded document) used to detect that the same source data has already been processed.
- **Notes / Boundaries:** Combined with uniqueness constraints, a Source Fingerprint prevents the same source event from posting twice ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 8; [ADR-0004](../../adr/0004-financial-integrity-principles.md)). Distinct from an Idempotency Key, which identifies a duplicate command rather than duplicate source data. Its derivation mechanism is defined elsewhere and is not defined here.
- **Related Terms:** Idempotency Key, Bank Transaction, Reconciliation.

### Tenant

- **Term:** Tenant
- **Normative Definition:** The single business (sole proprietor or enterprise, per MVP scope) that owns a given set of accounting data, with immutable ownership on every financial record.
- **Notes / Boundaries:** Application, query, database, and storage controls must all prevent cross-tenant access ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants), invariant 11; [ADR-0004](../../adr/0004-financial-integrity-principles.md)). MVP is single-owner per tenant; multi-entity and Sdn. Bhd. structures are out of MVP scope ([`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §9).
- **Related Terms:** Actor, Account, Ledger.

### Trial Balance

- **Term:** Trial Balance
- **Normative Definition:** A summary listing of every Account's Balance at a point in time, used to confirm that total debits equal total credits across the entire Ledger.
- **Notes / Boundaries:** An imbalanced Trial Balance is a release blocker ([AETS-002 §5](AETS-002-Accounting-Invariants.md#5-release-blocker); [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §18). A Trial Balance is a Projection of the Ledger, not an independent record.
- **Related Terms:** Ledger, Balance, Projection.

## 6. Cross References

| Source | What it grounds in this document |
| --- | --- |
| [ADR-0001](../../adr/0001-modular-monolith-architecture.md) | Accounting Core's exclusive posting authority (Accounting Command, Posting) |
| [ADR-0004](../../adr/0004-financial-integrity-principles.md) | The majority of terms: Journal, Journal Line, Posting, Balance, Reversal, Replacement, Actor, Tenant, Audit Event, Source Fingerprint, Allocation, Bank Transaction, Reconciliation, Trial Balance |
| [ADR-0005](../../adr/0005-ai-provider-abstraction.md) | Accounting Proposal vs. Accounting Command, Evidence, Actor's relationship to AI |
| [ADR-0006](../../adr/0006-transactional-outbox-pattern.md) | Posting's atomicity with outbox events; the "database transaction" exemption in §4, rule 4 |
| [ADR-0007](../../adr/0007-money-representation-strategy.md) | Money, and the explicit deferral of its canonical representation |
| [AETS-000](AETS-000.md) | Authority hierarchy (§3); accounting and AI philosophy (§4–5) grounding Accounting Proposal, Actor; planned document numbers used throughout §5 |
| [AETS-002](AETS-002-Accounting-Invariants.md) | Every invariant citation in §5's Notes / Boundaries fields; the release-blocker citation under Trial Balance |
| [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) | Tenant's MVP single-owner scope (§9); Trial Balance's release-blocker status (§18) |

This document does not cite the System Requirements Specification PDF directly by page or section. As with [AETS-000](AETS-000.md) (see its §4, Validation), no PDF text-extraction tool is available in this environment; alignment instead relies on the SRS content already interpreted and cited by the accepted ADRs and by `HORE_MY_PROJECT_INSTRUCTIONS.txt` / `HORE_MY_MASTER_CONTEXT.md`, both of which outrank the SRS in the authority hierarchy (§3) regardless.

## 7. Validation

The following checks were performed on this document before delivery:

- **Internal consistency:** every term used inside another term's definition or notes (e.g. Journal Line inside Journal, Idempotency Key inside Accounting Command) is itself defined in §5, so no entry depends on an undefined concept.
- **No contradiction with AETS-002:** each of the 14 invariants in [AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) was checked against every term that touches it; no definition here states or implies anything an invariant forbids (e.g. Journal is defined as append-only, not editable; Posting is defined as Accounting Core's exclusive act).
- **No contradiction with AETS-000:** the accounting and AI philosophy in [AETS-000 §4–5](AETS-000.md#4-accounting-philosophy) is reflected in Accounting Proposal, Accounting Command, and Actor without restating or narrowing it.
- **No contradiction with the ADRs:** every ADR-sourced claim in §5 and §6 was checked against the cited ADR's own wording (§3, §6 above).
- **No contradiction with the Engineering Blueprint:** this document introduces no coding, testing, or architectural rule of its own; it only names concepts, so no Blueprint rule applies to be contradicted.
- **Markdown links:** every relative link in this document was verified to resolve to an existing file (and, where used, an existing heading anchor in that file).
- **No algorithm or design introduced:** every term whose full behavior depends on an undecided algorithm, schema, or design explicitly says so in its Notes / Boundaries and names the later AETS document responsible, rather than defining that behavior here.
- **Repository hygiene:** `git diff --check` was run against the working tree; see the task response for its output.

## 8. Deferred Topics

The following are explicitly **not** covered by this document and are left to later work:

- **Money's full domain value object contract** (Currency, MinorUnits, and related types) — the canonical representation itself is now resolved by [ADR-0007](../../adr/0007-money-representation-strategy.md)'s Founder-approved amendment (integer minor units, PostgreSQL `BIGINT`); formalizing the contract built on it remains deferred to AETS-005.
- **Posting Engine design** (journal/line schema, transaction and concurrency mechanics), deferred to AETS-004.
- **Chart of Accounts design** (account taxonomy, numbering, ownership rules), deferred to AETS-003.
- **Any accounting algorithm**, including allocation matching, reconciliation matching, rounding, idempotency-key derivation, and source-fingerprint derivation — each is named where relevant in §5 but not designed here.
- **Terms referenced but not yet formally defined**, because they fall outside this task's required list and are not yet load-bearing for an existing AETS document: *Chart of Accounts*, *Payment*, *Invoice*, *Quotation*, *Customer*, *MyInvois Submission*, *Period* lifecycle states beyond Open/Closed, *Golden Dataset* / *Proof of Accuracy*. These should be added here (not redefined elsewhere) when the AETS document that first needs them is written, per §4, rule 6.
- **Non-accounting terminology** (Identity, Workspace/Task, Document Processing, general product vocabulary) — out of this document's scope per §2.2, and not part of the Accounting Core domain this glossary covers.

## Changelog

- **1.1.0 (2026-09-03):** Updated the Money term (§5), §2.2 scope note, and §8 deferred-topics entry to reflect [ADR-0007](../../adr/0007-money-representation-strategy.md)'s Founder-approved resolution of the canonical Money representation (integer minor units, PostgreSQL `BIGINT`). No term's fundamental meaning changed; references to an open two-way representation choice were updated to the now-settled fact.
