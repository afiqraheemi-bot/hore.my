# AETS-000: Accounting Engine Technical Specification — Foundation

- Status: Active
- Version: 1.2.2
- Effective date: 2026-09-03
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [`ENGINEERING_BLUEPRINT.md`](../../../ENGINEERING_BLUEPRINT.md); [ADR-0001](../../adr/0001-modular-monolith-architecture.md), [ADR-0004](../../adr/0004-financial-integrity-principles.md), [ADR-0005](../../adr/0005-ai-provider-abstraction.md), [ADR-0006](../../adr/0006-transactional-outbox-pattern.md), [ADR-0007](../../adr/0007-money-representation-strategy.md); [`HORE_MY_PROJECT_INSTRUCTIONS.txt`](../../product/reference/HORE_MY_PROJECT_INSTRUCTIONS.txt); [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md); System Requirements Specification

## 1. Purpose

The Accounting Engine Technical Specification (AETS) is the technical specification series for hore.my's Accounting Core — the deterministic subsystem with exclusive authority over ledger posting, as established by [ADR-0001](../../adr/0001-modular-monolith-architecture.md) and [ADR-0004](../../adr/0004-financial-integrity-principles.md).

This document, AETS-000, is the foundation of that series. It exists to:

- state the purpose, scope, and authority the whole AETS series operates under;
- record the accounting and AI philosophy that every later AETS document must remain consistent with;
- define how AETS documents are governed, reviewed, versioned, and numbered; and
- lay out the planned structure of the series so later documents have a known, stable home.

AETS-000 does **not** define accounting algorithms, schemas, chart of accounts, rounding rules, reconciliation logic, or any other narrow implementation decision. Those belong to later, numbered AETS documents, each scoped to one concern.

## 2. Scope

### 2.1 In scope for the AETS series

The AETS series covers the technical design of hore.my's Accounting Core and its immediate contract with the modules that depend on it, for the locked MVP surface described in [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §7–8:

- double-entry ledger structure, chart of accounts, and posting mechanics;
- journal, evidence, and audit-event data model and invariants;
- money representation, arithmetic, and rounding policy;
- correction (reversal/replacement), period management, and closing;
- bank reconciliation, invoicing, payment allocation, and MyInvois submission as they touch the ledger;
- reporting and projection rebuild semantics; and
- the accounting-facing contract for AI-produced proposals (what Accounting Core accepts, validates, and rejects).

### 2.2 Out of scope for the AETS series

- Any accounting algorithm, schema, or threshold not yet defined by a later AETS document — this document defines none.
- UI/UX design, frontend implementation, and non-accounting modules (Identity, Onboarding, Workspace/Task, general Document Processing) — these belong to their own specifications.
- AI/OCR provider selection, model choice, prompt design, and routing — owned by AI Orchestration under [ADR-0005](../../adr/0005-ai-provider-abstraction.md); AETS defines only what Accounting Core requires from an accepted proposal.
- Infrastructure, deployment, and operational tooling — owned by the Engineering Blueprint and `infrastructure/`.
- Everything listed as out of MVP scope in [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §9 (Sdn. Bhd. and multi-entity, multi-currency, payroll, complex inventory, manufacturing, accountant portal, multi-user approval, live bank feed, autonomous tax advice or filing, and any guarantee of audit or tax acceptance).

## 3. Authority hierarchy

AETS is a technical specification series, not a source of product or architectural authority. Where sources conflict, the following precedence governs, exactly as recorded in [`docs/product/reference/README.md`](../../product/reference/README.md) and [`docs/adr/README.md`](../../adr/README.md):

1. Latest Founder instruction
2. [`HORE_MY_PROJECT_INSTRUCTIONS.txt`](../../product/reference/HORE_MY_PROJECT_INSTRUCTIONS.txt)
3. [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md)
4. System Requirements Specification (`hore.my_Spesifikasi_Keperluan_Sistem_v1.0_BM.pdf`), as corrected by [`docs/product/reference/SRS_OVERRIDES.md`](../../product/reference/SRS_OVERRIDES.md) where a controlled revision has been recorded
5. Detailed Project Proposal

Two further layers sit beneath these five, in this order:

6. **Accepted Architecture Decision Records** (`docs/adr/`) interpret and implement the sources above. An ADR is required before AETS may introduce or change anything that materially affects system boundaries, persistent data, public contracts, security posture, or another criterion in [`ENGINEERING_BLUEPRINT.md`](../../../ENGINEERING_BLUEPRINT.md) §9.
7. **AETS documents** (this series) provide the detailed technical design that implements accepted ADRs. AETS cannot weaken, override, or contradict any source above it. Where an AETS document appears to conflict with a higher-precedence source, the higher source governs and the AETS document must be corrected.

AETS documents are not ADRs and do not follow the ADR template or ADR lifecycle. They exist specifically to carry the narrower, detailed decisions that several accepted ADRs explicitly deferred — for example, the canonical money representation [ADR-0007](../../adr/0007-money-representation-strategy.md) §"Decision" originally deferred and has since resolved by Founder-approved amendment, ahead of Accounting Core implementation, exactly as that ADR's own rollout rule required. Where such a deferred decision is itself material by the Engineering Blueprint's own criteria, it still requires its own ADR; the resulting AETS document then records the full technical design consistent with that ADR.

## 4. Accounting philosophy

hore.my's accounting model follows directly from the locked product principles in [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §4 and §10:

- **Action-first, not conversation-first.** The user supplies evidence or an instruction; the system produces a structured proposal with amount, date, category, account, evidence, confidence, and available actions. Accounting is a sequence of confirmed, terminal actions, not an open-ended dialogue.
- **AI interprets; the engine posts.** Interpretation, extraction, classification, and proposal are separate from posting. Only the deterministic Accounting Core — never AI, never a proposal-producing module — commits a journal. See [AETS-002](AETS-002-Accounting-Invariants.md).
- **Nothing is lost, duplicated, or silently changed.** Every material command is idempotent and fingerprinted; posted journals are append-only; corrections are always visible, linked reversals or replacements, never silent edits.
- **Every number is traceable.** Every reported figure must be traceable to a journal, a decision, and evidence. Reports and balances are derived from the ledger, not the other way around.
- **Automate the obvious; ask only about material uncertainty.** The system should not manufacture busywork by asking about things it can determine confidently, and must not hide uncertainty when it cannot.
- **Simple to use, disciplined underneath.** hore.my must feel very simple to the user while being built on accounting and engineering structure that is very disciplined — the closing principle of the Master Context applies to the Accounting Core more than to any other module, because this is the module where a shortcut becomes a wrong financial statement.

These are product-level commitments. AETS's job is to turn them into a technical design that cannot be quietly violated by an individual feature or a convenient shortcut.

## 5. AI philosophy

AI's role and limits with respect to accounting are fixed by [ADR-0005](../../adr/0005-ai-provider-abstraction.md) and restated here because every AETS document must design to them:

**AI may:** interpret supported instructions, extract fields from evidence, classify documents, propose transactions or accounts, produce a confidence score, and explain its rationale.

**AI must never:**
- write directly to the ledger;
- update or delete a posted journal;
- hold repository or database permission capable of posting;
- invent evidence that does not exist;
- hide uncertainty instead of surfacing it;
- make an autonomous professional tax decision; or
- bypass deterministic domain validation or a required user confirmation.

An accepted AI proposal is not a posted journal. It is revalidated by Accounting Core exactly as any other candidate command would be, under the same invariants described in [AETS-002](AETS-002-Accounting-Invariants.md). Every AI-influenced decision carries the provider/model identifier, model and prompt/schema version, confidence, and the evidence it relied on, so it remains explainable and auditable after the fact. Proof of Accuracy — validating Accounting Core against approved golden datasets — precedes any full AI workflow reaching real posting; AETS documents for AI-adjacent accounting behavior (e.g. the AI proposal contract) must specify how a proposal is validated and rejected, not assume it is trustworthy input.

## 6. Financial integrity principles

The financial integrity invariants — the non-negotiable, numbered constraints every later AETS document must design within, and that no AETS document has authority to relax — are specified in **[AETS-002: Accounting Invariants](AETS-002-Accounting-Invariants.md)**, not restated here.

This section previously carried that list directly. It has moved to its own document so that AETS-000 remains architecture and governance only, while the invariants themselves get a stable, independently versioned home they can be cited from precisely (e.g. "AETS-002 §4") as later AETS documents (chart of accounts, journal and posting model, reconciliation, and so on) are written against them. The invariants' source of authority is unchanged: [ADR-0004](../../adr/0004-financial-integrity-principles.md), [ADR-0006](../../adr/0006-transactional-outbox-pattern.md), and [ADR-0007](../../adr/0007-money-representation-strategy.md).

## 7. Design principles

These apply the Engineering Blueprint's general engineering principles (§2) specifically to Accounting Core design work carried out under AETS:

- **Explicit boundaries.** Accounting business rules live in the Accounting Core domain, not in controllers, queue handlers, or a UI layer. Every AETS document should be readable without reference to a specific framework API.
- **Deterministic before probabilistic.** Where a workflow mixes AI interpretation and ledger posting, the deterministic validation and posting step is designed and specified first; the AI contribution is a validated input to it, never a shortcut around it.
- **Idempotent by default.** Every command, event, and external submission an AETS document introduces must specify its idempotency mechanism explicitly — this is a required section of any future document that defines a command or an integration, not an afterthought.
- **Rebuildable, not just correct.** Any derived state (a balance, a report, a projection) must be specified together with how it is rebuilt from the ledger, so drift is detectable and recoverable rather than merely "unlikely."
- **Explicit currency and scale.** No AETS document may assume a currency or minor-unit scale implicitly; MVP is MYR at two decimal places, and this must be stated, not inferred, wherever an amount is defined.
- **Small, reversible change.** Accounting Core changes are reviewable and independently testable; correction of a design mistake uses the same reversal/replacement discipline the ledger itself uses — a later AETS document supersedes an earlier one rather than silently rewriting it (§9).
- **Evidence-based quality.** Claims of correctness are backed by the validation methods each ADR already specifies (property-based tests, golden datasets, fault-injection, concurrency tests) — an AETS document that introduces new accounting behavior must say how that behavior will be proved, not just describe it.
- **Tenant isolation as a first-class constraint.** Every schema, query pattern, and cache design an AETS document proposes must be evaluated for cross-tenant leakage risk explicitly, not by omission.

## 8. Document governance

### 8.1 What an AETS document is

An AETS document is a detailed technical specification for one bounded concern within Accounting Core's scope (§2.1). It is written for implementers: precise enough to build from, testable, and traceable to the ADRs and product references that authorize it.

### 8.2 Ownership and review

AETS documents are owned by Accounting Core, under the repository's [`CODEOWNERS`](../../../CODEOWNERS) policy. Given the financial-integrity stakes involved, a new or materially revised AETS document requires review from:

- the CTO / Technical Partner (architecture and engineering fit), and
- an Accounting Domain Reviewer (accounting correctness), consistent with the deciders already recorded on [ADR-0004](../../adr/0004-financial-integrity-principles.md) and [ADR-0007](../../adr/0007-money-representation-strategy.md).

Founder / Product Owner review is required only where a document changes scope, cost, risk, or user experience, per [`HORE_MY_PROJECT_INSTRUCTIONS.txt`](../../product/reference/HORE_MY_PROJECT_INSTRUCTIONS.txt).

### 8.3 Lifecycle

Each AETS document (including this one) carries a `Status` field with one of these values:

| Status | Meaning |
| --- | --- |
| `Draft` | Under active writing or review; not yet governing implementation. |
| `Active` | Reviewed and governs current implementation. |
| `Superseded` | Replaced by a later AETS document, which it must link to. The superseded document is kept, not deleted, for historical traceability — the same rule ADRs already follow. |
| `Deprecated` | No longer applicable (e.g. the concern it covered left MVP scope), without being replaced by a newer document. |

### 8.4 Relationship to ADRs and conflict handling

AETS documents must cite the ADR(s) they implement in a `Related` field, exactly as this document does. If implementing an AETS document would require a decision an existing ADR does not cover and that meets the Engineering Blueprint's ADR criteria, the ADR is written and accepted first; the AETS document is then written or revised to match it. AETS content is never used to justify skipping a required ADR.

If a conflict between an AETS document and a higher-precedence source (§3) is ever discovered, it is a defect in the AETS document. It must be corrected as soon as identified, and the correction recorded via the versioning rule in §9.

### 8.5 Location and file naming

AETS documents live in `docs/specifications/accounting/`, named `AETS-NNN.md` with a zero-padded, sequential, never-reused number, plus this directory's `README.md` as the series index. Numbers are assigned only when a document is actually created, matching the Engineering Blueprint's rule that directories and artifacts are created only when they are owned — §10 lists the currently planned, not-yet-created, numbers as placeholders only.

## 9. Versioning

### 9.1 Per-document version

Each AETS document carries its own `Version` field using `MAJOR.MINOR.PATCH`:

- **MAJOR** — a change that alters an accounting outcome, invariant, or contract described in the document (e.g. a different rounding rule, a changed idempotency scope). Requires the review in §8.2 and, if it also changes something ADR-governed, a corresponding ADR update first.
- **MINOR** — a clarification, added detail, or new subsection that does not change any previously specified behavior.
- **PATCH** — an editorial fix (typo, broken link, formatting) with no content change.

A MAJOR version change on an `Active` document does not edit history silently: the document's version increments, and a brief changelog note (date, version, summary of what changed and why) is kept at the bottom of the document, mirroring how ADRs record amendments (see [ADR-0002](../../adr/0002-technology-stack-selection.md)'s amendment section for the established pattern in this repository).

### 9.2 Series versioning

The AETS series itself is not independently versioned; its state at any time is the set of documents currently `Active`, indexed in [`README.md`](README.md). This foundation document is `AETS-000`, version `1.0.0`.

## 10. Planned document structure

**AETS-001 (Accounting Terminology), AETS-002 (Accounting Invariants), AETS-003 (Money Specification), and the later Active documents listed in [`README.md`](README.md) have already been created. AETS-008 and AETS-012 exist as Drafts only.** This table is the single authoritative roadmap for the AETS series; no other document states a competing numbering. Rows without an existing document remain planned and unreserved; a Draft row is not effective until activated under §8. The list may grow, shrink, or reorder as design work proceeds. This roadmap does not itself define any listed topic.

| Planned | Working title | Anticipated concern |
| --- | --- | --- |
| AETS-004 | Journal & Posting Model | Journal/line schema, atomicity and idempotency mechanics |
| AETS-005 | Chart of Accounts & Account Taxonomy | Account types, structure, and ownership rules |
| AETS-006 | Posting Rules | Posting validation and correction (reversal/replacement) mechanics, and period-close posting controls |
| AETS-007 | Accounting Commands | The concrete set of Accounting Commands Accounting Core accepts, including invoicing and payment allocation |
| AETS-008 (Draft) | Bank Reconciliation | Import de-duplication, matching, RM0.00 completion rule; unresolved workflow decisions explicitly block activation |
| AETS-009 | Financial Reporting | Report derivation, rebuild-from-ledger guarantees |
| AETS-010 | Audit Trail | Audit event shape, evidence retention and linkage |
| AETS-011 | AI Accounting Proposal Contract | What Accounting Core requires from, and how it validates, an AI-produced proposal |
| AETS-012 (Draft) | Proof of Accuracy | Proposed golden dataset scope and acceptance criteria gating full AI workflows; not effective or certified pending review |
| AETS-013 | MyInvois Integration | Idempotent submission, status reconciliation, sandbox/production isolation |
| AETS-014 | Period Management | Period lifecycle, close/reopen controls |

Each, when created, must include a `Related` field citing the ADR(s) and sections of this document (or of [AETS-002](AETS-002-Accounting-Invariants.md)) it implements, and must not introduce anything AETS-002 or §3 prohibits.

## Changelog

- **1.2.2 (2026-09-15):** Records that AETS-008 now exists as a Draft with unresolved activation blockers. No accounting requirement or governance rule changed.
- **1.2.1 (2026-09-13):** Records that AETS-012 now exists as a Draft and clarifies that roadmap rows can represent existing Active/Draft documents as well as unreserved plans. No accounting or governance rule changed.
- **1.2.0 (2026-09-03):** Replaced the obsolete §10 roadmap (which listed `AETS-003` as Chart of Accounts and `AETS-005` as Money Representation Design) with the corrected, authoritative roadmap reflecting that `AETS-003` is the Money Specification. No governance rule in this document changed; this is a roadmap/numbering correction only.
- **1.1.0 (2026-09-03):** Updated §3's illustrative example and §10's AETS-005 description to reflect [ADR-0007](../../adr/0007-money-representation-strategy.md)'s Founder-approved resolution of the canonical Money representation (integer minor units, PostgreSQL `BIGINT`). No governance rule in this document changed; only a reference to a now-resolved external fact was updated.
