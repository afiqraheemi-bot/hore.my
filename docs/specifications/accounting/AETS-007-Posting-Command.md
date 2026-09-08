# AETS-007: Accounting Commands & Posting Pipeline

- Status: Active
- Version: 2.1.0
- Effective date: 2026-09-06
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-000](AETS-000.md), [AETS-001](AETS-001-Accounting-Terminology.md), [AETS-002](AETS-002-Accounting-Invariants.md), [AETS-003](AETS-003-Money-Specification.md), [AETS-004](AETS-004-Journal-Posting-Model.md), [AETS-005](AETS-005-Chart-of-Accounts.md); [ADR-0001](../../adr/0001-modular-monolith-architecture.md), [ADR-0004](../../adr/0004-financial-integrity-principles.md), [ADR-0005](../../adr/0005-ai-provider-abstraction.md), [ADR-0006](../../adr/0006-transactional-outbox-pattern.md)

## 1. Purpose

This document is the normative specification for hore.my's **Accounting Commands** ([AETS-001](AETS-001-Accounting-Terminology.md#accounting-command)) and the Posting Pipeline every one of them is ultimately validated through — the number and working title [AETS-000 §10](AETS-000.md#10-planned-document-structure) already anticipated ("AETS-007, Accounting Commands, ... including invoicing and payment allocation"). This document claims that scope in full, phased: it fully specifies exactly one Accounting Command now — the **Posting Command**, the concrete contract Accounting Core requires before it will consider transitioning a Journal from Draft to Posted, and the validation pipeline that contract must pass through before any ledger effect can occur. Every business-specific Accounting Command built to date (Expense, Income, Transfer, Owner Equity, Invoice Issuing, and Payment Recording) resolves down to this Posting Command contract with a fixed, already-implemented account mapping — recorded, as of v2.1.0, in §26. A business-specific Accounting Command not yet built (Bank Reconciliation, MyInvois, a Credit Note, and so on) remains a deferred future subsection or extension of this same document, under this same number — not silently designed here, and not split into a competing AETS number (§2, §27).

[AETS-004 §2.2](AETS-004-Journal-Posting-Model.md#22-out-of-scope) and [AETS-004 §26](AETS-004-Journal-Posting-Model.md#26-deferred-items) explicitly deferred "the concrete Posting Command schema and API contract — the actual command shape, transport, and validation error format" to this document, by this number. [AETS-005 §2.2](AETS-005-Chart-of-Accounts.md#22-out-of-scope) makes the same deferral for "the concrete command shape and API contract." This document resolves that deferral for the Posting Command specifically — it does not reopen anything either document already settled.

This document exists so Posting Engine implementation work has a precise, testable, traceable target before any code is written, exactly as [AETS-004](AETS-004-Journal-Posting-Model.md) and [AETS-005](AETS-005-Chart-of-Accounts.md) already exist for the Journal/Journal Line and Account contracts the Posting Command depends on.

This document uses **MUST**, **MUST NOT**, **SHOULD**, and **MAY** with their normal RFC 2119 meaning, exactly as [AETS-003 §5](AETS-003-Money-Specification.md#5-normative-language), [AETS-004 §1](AETS-004-Journal-Posting-Model.md#1-purpose), and [AETS-005 §1](AETS-005-Chart-of-Accounts.md#1-purpose) already establish for this series.

## 2. Scope

### 2.1 In scope

- **This version's phased scope.** The Posting Command is fully specified by this version (§4–§19, §23) — every field, validation rule, and invariant it requires. Business-specific Accounting Commands not yet built (§1) are named only to state that they remain deferred, future subsections or extensions of this same document; none of their fields, validation rules, or account mappings are designed in this version.
- The Posting Command's conceptual contract: what it carries, what identifies it, and what distinguishes it from both an Accounting Proposal and a Journal (§4–§11).
- The Posting Validation Pipeline, reconciled to and extending [AETS-004 §11](AETS-004-Journal-Posting-Model.md#11-posting-validation-pipeline) and [AETS-005 §19](AETS-005-Chart-of-Accounts.md#19-journal-line-integration) rather than replacing either (§12–§15).
- The atomic transaction boundary a successful Posting Command's execution requires (§16–§17).
- Failure and success semantics for a Posting Command specifically (§18–§19).
- The AI-authority boundary as it applies to the Posting Command (§20), restating and applying — not re-deciding — [AETS-000 §5](AETS-000.md#5-ai-philosophy) and [ADR-0005](../../adr/0005-ai-provider-abstraction.md).
- Security, tenant isolation, and auditability requirements specific to accepting a Posting Command (§21–§22).
- Stable `POST-NNN` invariants for Posting Engine responsibilities (§23).
- The required coverage of a future Posting Command Test Specification (§24).
- **As of v2.1.0:** the fixed account mapping each already-implemented business-specific Accounting Command produces once it resolves down to the Posting Command contract above — Expense, Income, Transfer, Owner Equity, Invoice Issuing, and Payment Recording — plus the explicit clarification that Payment Allocation is not an Accounting Command at all (§26).

### 2.2 Out of scope

- **The Journal, Journal Line, Debit/Credit semantics, Journal lifecycle states, balance validation, atomicity, idempotency, append-only rules, or Reversal/Replacement mechanics themselves** — all fully specified by [AETS-004](AETS-004-Journal-Posting-Model.md) and not reopened here. This document only specifies the command that triggers them.
- **The Account entity, its taxonomy, or its own lifecycle rules** — fully specified by [AETS-005](AETS-005-Chart-of-Accounts.md) and not reopened here. This document only specifies which of those rules a Posting Command must satisfy before Accounting Core accepts it.
- **The Money domain contract** — fully specified by [AETS-003](AETS-003-Money-Specification.md) and not reopened here.
- **Detailed correction-workflow mechanics** — whether/how many times a Journal may be reversed, approval requirements for a Replacement, and period-close interaction with posting — deferred to AETS-006 (Posting Rules), per [AETS-004 §2.2](AETS-004-Journal-Posting-Model.md#22-out-of-scope). This document references Reversal/Replacement only where the Posting Command's own contract must accommodate them (§11), and designs no correction workflow of its own.
- **Invoice domain, Expense domain, Bank Reconciliation, MyInvois, tax computation, reporting** — each its own future document ([AETS-000 §10](AETS-000.md#10-planned-document-structure)); this document defines the generic Posting Command contract those domains will each build on, not the domain logic (customer records, due dates, statement matching, and so on) around each one's own account mapping.
- **A default Chart of Accounts or Account Code numbering scheme** — [AETS-005 §2.2](AETS-005-Chart-of-Accounts.md#22-out-of-scope) already excludes this; this document does not reopen it.
- **Specific business transaction mappings not yet built** — Bank Reconciliation, MyInvois, a Credit Note, or any other domain-specific command [AETS-000 §10](AETS-000.md#10-planned-document-structure) anticipates under this same number that does not yet exist in the codebase. This document claims the "Accounting Commands" scope in full (§1); §26 records the mapping for every business-specific command actually built as of v2.1.0, and §27 continues to track what remains undesigned.
- **UI design and AI prompt design** — this document states what Accounting Core requires as input and produces as output; it does not design how either is presented or generated.
- **Concrete Actor, Source, Evidence, or Audit Event object schemas** — [AETS-001](AETS-001-Accounting-Terminology.md) defines each as a concept; their detailed shapes are deferred to a future Audit Trail specification (AETS-010) and an Identity/Access specification, neither yet created. This document defines only the Posting Command's *relationship* to each (§8–§10, §22) — for Actor and Source specifically, it additionally names and defines the minimal opaque-reference contract each requires to be carried and tested at all (§8.1, §9.1); it does not define either's full domain schema, and Evidence's reference contract remains entirely deferred alongside its schema.
- **The exact Idempotency Key derivation algorithm** — [AETS-004 §14](AETS-004-Journal-Posting-Model.md#14-idempotency) already states this is a later implementation decision; this document states the required contract and guarantees only (§6).
- Implementation code, ORM/schema design, and migrations — this is a specification, not code.

## 3. Authority and dependencies

This document implements [ADR-0001](../../adr/0001-modular-monolith-architecture.md) (Accounting Core's exclusive posting authority), [ADR-0004](../../adr/0004-financial-integrity-principles.md) (idempotency key/source fingerprint, atomicity, tenant isolation, complete auditability, deterministic authority), [ADR-0005](../../adr/0005-ai-provider-abstraction.md) (the Accounting Proposal/Accounting Command boundary), and [ADR-0006](../../adr/0006-transactional-outbox-pattern.md) (transactional outbox), within the terminology of [AETS-001](AETS-001-Accounting-Terminology.md), the invariants of [AETS-002](AETS-002-Accounting-Invariants.md), the Money contract of [AETS-003](AETS-003-Money-Specification.md), the Journal/Posting model of [AETS-004](AETS-004-Journal-Posting-Model.md), and the Account contract of [AETS-005](AETS-005-Chart-of-Accounts.md). It cannot weaken, override, or contradict any of them ([AETS-000 §3](AETS-000.md#3-authority-hierarchy)); where this document appears to conflict with one of them, the higher-precedence source governs and this document must be corrected ([AETS-000 §8.4](AETS-000.md#84-relationship-to-adrs-and-conflict-handling)). No such conflict was found while drafting this document — see §7 (Validation) for the specific checks performed.

Dependencies:

- [AETS-000](AETS-000.md) — governance, versioning, and the accounting/AI philosophy this document designs to (§5, §20).
- [AETS-001](AETS-001-Accounting-Terminology.md) — canonical terminology: Accounting Command, Accounting Proposal, Actor, Audit Event, Evidence, Idempotency Key, Posting, Source Fingerprint, Tenant, and others, all reused exactly, none redefined ([AETS-001 §4](AETS-001-Accounting-Terminology.md#4-normative-terminology-rules) rule 6).
- [AETS-002](AETS-002-Accounting-Invariants.md) — the 14 financial integrity invariants this document's `POST-NNN` invariants (§23) are additional to and must not contradict.
- [AETS-003](AETS-003-Money-Specification.md) — the Money, Currency, and MinorUnits contract every proposed Journal Line's amount uses unchanged.
- [AETS-004](AETS-004-Journal-Posting-Model.md) — the Journal/Journal Line model, the five-step Posting Validation Pipeline (§11), atomicity (§13), the idempotency contract (§14), and the `JRN-NNN` invariants (§22) this document extends with a concrete command contract, never contradicts.
- [AETS-005](AETS-005-Chart-of-Accounts.md) — the Account model and its own extension of AETS-004 §11 (§19, the four-step Account resolution sequence) this document reuses unchanged.
- Existing Journal/Account domain code, referenced only to confirm this specification is consistent with already-settled, already-implemented contracts, never to redesign them: the `Journal` aggregate's `create()`/`post()`/`reconstitute()` methods (§11), the `JournalId` Value Object's deliberate absence of a self-generation method (§6, §11), and `JournalRepository`'s existing `save()` contract, which already supports both a fresh Journal insert and an existing-Draft-to-Posted transition (§11, §16) — see [`apps/api/app/Domain/Accounting/Journal/`](../../../apps/api/app/Domain/Accounting/Journal/) and [`apps/api/app/Infrastructure/Accounting/Journal/JournalRepository.php`](../../../apps/api/app/Infrastructure/Accounting/Journal/JournalRepository.php).

## 4. Posting Command definition

A **Posting Command** is the Accounting Command ([AETS-001](AETS-001-Accounting-Terminology.md)) whose accepted effect is transitioning a Journal from Draft to Posted ([AETS-004 §5](AETS-004-Journal-Posting-Model.md#5-definitions), [AETS-004 §10](AETS-004-Journal-Posting-Model.md#10-posting-command)). This document does not redefine that term — it specifies the concrete contract a Posting Command must satisfy to be accepted.

**A Posting Command is not a Journal.** It is a request *to* Accounting Core, carrying everything Accounting Core needs to independently decide whether a Journal may become Posted. Nothing about a Posting Command's existence, submission, or content has any ledger effect. Only after every step of the Posting Validation Pipeline (§14) succeeds, inside one atomic transaction (§17), does its resulting Journal become Posted and authoritative. A rejected Posting Command produces no Journal, no partial Journal, and no ledger effect of any kind (§18).

**A Posting Command is not an Accounting Proposal.** An Accounting Proposal ([AETS-001](AETS-001-Accounting-Terminology.md)) is candidate input — possibly AI-produced, possibly incomplete, carrying a confidence score. A Posting Command is what Accounting Core actually accepts and independently validates, regardless of what produced the candidate input that led to it. An AI-originated proposal has no path to becoming a Posting Command except through explicit confirmation by an authorized Actor, or deterministic acceptance by Accounting Core's own controls (§20; [AETS-004 §10](AETS-004-Journal-Posting-Model.md#10-posting-command)).

**The locked rule this document preserves.** AI never writes directly to the ledger. AI, or any other business module, may only produce or request a candidate Posting Command; Accounting Core independently validates it, in full, before any Journal becomes Posted and authoritative ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 13; [AETS-000 §5](AETS-000.md#5-ai-philosophy); [ADR-0005](../../adr/0005-ai-provider-abstraction.md)). Nothing in this document weakens that rule; §20 states it in Posting-Command-specific terms.

## 5. Posting Command fields

A Posting Command MUST carry the following, at minimum ([AETS-004 §10](AETS-004-Journal-Posting-Model.md#10-posting-command)). This table names each field's governing contract; it does not invent a concrete transport shape, serialization, or primitive type beyond what an existing, already-settled contract already defines.

| Field | Required | Conceptual content | Governing contract |
| --- | --- | --- | --- |
| Command identity / Idempotency Key | Always | An identifier such that repeating or concurrently retrying this same logical command returns the original economic result rather than creating a second effect (§6). | [AETS-001](AETS-001-Accounting-Terminology.md#idempotency-key) Idempotency Key; concrete derivation deferred ([AETS-004 §14](AETS-004-Journal-Posting-Model.md#14-idempotency), §6, §27). |
| Source Fingerprint | Where the command originates from, or is materially derived from, external/imported source data requiring duplicate-source detection (§6) | An identifier, derived from that source data's content or origin, used to detect that the same source data has already been processed. | [AETS-001](AETS-001-Accounting-Terminology.md#source-fingerprint) Source Fingerprint; [AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 3; concrete derivation deferred (§6, §27). |
| TenantId | Always | The single Tenant this command, and every fact it references, is scoped to. | [AETS-001](AETS-001-Accounting-Terminology.md#tenant) Tenant; existing `TenantId` domain Value Object. |
| Actor | Always | The identified human user, operator, or authorized system process responsible for this command. | [AETS-001](AETS-001-Accounting-Terminology.md#actor) Actor; minimal opaque reference contract §8.1; full Actor object schema deferred (§8, §27). |
| Source | Always | A traceable reference to the Accounting Command, accepted Accounting Proposal, or correction reference that gives rise to this command. | [AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability); minimal opaque reference contract §9.1; full Source object schema deferred (§9, §27). |
| Evidence references | Where the underlying Business Transaction is evidence-backed | Reference(s) to the Evidence supporting this command's Journal. | [AETS-001](AETS-001-Accounting-Terminology.md#evidence) Evidence; concrete Evidence object schema deferred (§10, §27). |
| Proposed Journal identity | Always | Either (a) an identifier for a not-yet-persisted Journal, or (b) a reference to an existing Draft Journal's identifier. | [AETS-004 §6](AETS-004-Journal-Posting-Model.md#6-journal-aggregate), §9, §10; existing `JournalId` Value Object (§11). |
| Proposed Journal Lines | Always | The ordered set of {Account identifier, Money, Direction} entries this command proposes to post. | [AETS-004 §7](AETS-004-Journal-Posting-Model.md#7-journal-line), §8; existing `JournalLine` Value Object (§11). |
| Financial Date | Always | The ledger-authoritative accounting date the resulting Journal's effect belongs to — never derived from `created_at` or any other system timestamp. | [AETS-004 §9.1](AETS-004-Journal-Posting-Model.md#91-financial-date-and-posted-at), §11.1. |

No field beyond this table is required by this document. A future business-specific Accounting Command (an invoice posting, an expense posting, and so on — §2.2, §27) MAY carry additional fields of its own before it resolves down to this Posting Command contract; designing any such field is out of scope here.

## 6. Command identity and idempotency

This section states the required contract and guarantees only; it does not invent a final Idempotency Key or Source Fingerprint derivation algorithm, hashing scheme, or storage schema, per [AETS-004 §14](AETS-004-Journal-Posting-Model.md#14-idempotency) and [AETS-004 §26](AETS-004-Journal-Posting-Model.md#26-deferred-items)'s own explicit deferral, which this document does not resolve.

### 6.1 Idempotency Key (universal)

Required guarantees, restated here as the Posting Command's own contract (mirroring [AETS-004 §14](AETS-004-Journal-Posting-Model.md#14-idempotency), [AETS-004 §20](AETS-004-Journal-Posting-Model.md#20-concurrency-and-locking-expectations), and [AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 3):

- Every Posting Command MUST carry an Idempotency Key, scoped to its Tenant. A Posting Command with no Idempotency Key is not well-formed and MUST be rejected before any pipeline step runs (§14, `POST-002`).
- Retrying the same logical Posting Command (same Tenant, same Idempotency Key, same logical request) MUST NEVER create a second accounting effect. Accounting Core MUST instead return or identify the original Journal's result, deterministically (`POST-003`).
- The same Idempotency Key reused, for the same Tenant, with a materially different logical request (a different proposed Journal identity, different Journal Lines, different Account references, a different amount, or a different Financial Date, §11.1) is a **conflicting reuse**, not a safe replay. Accounting Core MUST reject a conflicting reuse loudly and distinguishably from both a fresh success and an idempotent replay — never silently returning the prior result, and never silently accepting the divergent request as a new, unrelated command (`POST-004`).
- Two concurrent Posting Commands carrying the same Idempotency Key for the same Tenant MUST result in exactly one Journal being created; the command that loses the race MUST detect the conflict and return the winner's result, never error destructively and never create a second Journal ([AETS-004 §20](AETS-004-Journal-Posting-Model.md#20-concurrency-and-locking-expectations)).
- Database-level uniqueness (and, where an existing Draft Journal is involved, row-level locking) remains the final, race-safe authority for the guarantees above — a purely application-level "check, then act" sequence is not sufficient on its own, since it cannot close the window between two concurrent commands' checks. This document does not mandate a specific locking or isolation-level mechanism ([AETS-004 §20](AETS-004-Journal-Posting-Model.md#20-concurrency-and-locking-expectations)); it requires only that whatever mechanism is chosen actually close that window. §15 states this in full as duplicate prevention's own dedicated guarantee.

### 6.2 Source Fingerprint (conditional)

[AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 3, as corrected, distinguishes universal command idempotency from *conditional* source deduplication. A Source Fingerprint ([AETS-001](AETS-001-Accounting-Terminology.md#source-fingerprint)) identifies duplicate *source data* (for example, the same imported bank line), not a duplicate *command* — it is distinct from, and complementary to, the Idempotency Key (§6.1), never a substitute for it.

- A Posting Command MUST carry a Source Fingerprint when it originates from, or is materially derived from, external or imported source data for which duplicate-source detection is required — for example: a Bank Transaction; an uploaded receipt; an uploaded invoice; an imported statement row; or an external integration payload.
- A Posting Command MUST NOT carry a fabricated Source Fingerprint merely to satisfy this requirement where no such source exists — a purely manual command, authored directly by an Actor with no external source material behind it, has no source to fingerprint and carries none (`POST-027`).
- Where a Source Fingerprint is required (by the rule above) but missing, the Posting Command MUST fail safely, before any persistent effect — exactly like any other structural well-formedness failure (§18, `POST-026`). This is not a weakening of duplicate prevention: it is a second, independent guard, in addition to (never instead of) the Idempotency Key's own guarantee (§6.1).
- Whether a given command "originates from, or is materially derived from" external source data (and therefore requires a Source Fingerprint) is determined by the calling module (for example, a future Bank Reconciliation or Document Processing module) supplying the command — this document does not enumerate every business context that would trigger the requirement, consistent with its own scope (§2.2); it states the rule, and the calling module applies it.
- A Posting Command triggered from de-duplicated source data still carries its own Idempotency Key regardless — the two mechanisms operate together, never as alternatives.

This document does not invent a Source Fingerprint derivation algorithm (hashing scheme, content-vs-origin basis, or storage schema) — deferred alongside Idempotency Key derivation (§27), exactly as [AETS-001](AETS-001-Accounting-Terminology.md#source-fingerprint) already states ("its derivation mechanism is defined elsewhere and is not defined here").

## 7. Tenant ownership

A Posting Command MUST fail — before any persistent effect — if any of the following does not belong to the command's own TenantId ([AETS-004 §18](AETS-004-Journal-Posting-Model.md#18-tenant-isolation); [AETS-005 §17](AETS-005-Chart-of-Accounts.md#17-tenant-ownership); [AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 11):

- any Account referenced by a proposed Journal Line;
- the command's own Actor;
- any Evidence reference the command carries;
- the existing Draft Journal the command references, if it references one rather than proposing a fresh Journal.

This is the single tenant-ownership gate the Posting Validation Pipeline's first logical step (§14) performs — it is not four separate checks scattered across the pipeline, though it does require resolving each referenced fact (the Account, the Actor, the Evidence, the existing Draft Journal) far enough to determine its TenantId before comparing. Tenant ownership, once set on any of these records, is immutable ([AETS-001](AETS-001-Accounting-Terminology.md#tenant); [AETS-004 §18](AETS-004-Journal-Posting-Model.md#18-tenant-isolation)) — this document does not introduce any operation that could change it.

## 8. Actor

Every Posting Command MUST record an identified Actor ([AETS-001](AETS-001-Accounting-Terminology.md#actor); [AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability)) — the human user, operator, or authorized system process responsible for it. A Posting Command with no Actor is not well-formed and MUST be rejected before any pipeline step runs, exactly as a missing Idempotency Key is (§6).

AI, or any other proposal-producing process, MUST NOT itself be recorded as the Actor accepting a Posting Command ([AETS-000 §5](AETS-000.md#5-ai-philosophy); [AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability)). It may only be recorded as the originator of the Accounting Proposal a human or a deterministic Accounting Core process subsequently confirmed (§20). Operator support access is read-restricted and is not an Actor capable of accepting a Posting Command directly ([ADR-0004](../../adr/0004-financial-integrity-principles.md); [AETS-001](AETS-001-Accounting-Terminology.md#actor)).

This document does not define Actor's own concrete object schema (identity provider, session/authorization representation) — that belongs to a future Identity/Access specification, not yet created (§27). It defines only that a Posting Command must carry a reference sufficient to identify one, and that the reference must resolve to the command's own Tenant (§7).

### 8.1 Actor reference (minimal contract)

Pending the future Identity/Access specification's full Actor schema (§27), a Posting Command's Actor field is, at minimum, an **Actor reference**: an opaque, immutable reference sufficient to resolve to exactly one identified party and to the command's own Tenant (§7), once an Identity/Access system exists to resolve it further. This reference:

- MUST be opaque — Accounting Core does not interpret its internal structure. This specification does not constrain its physical representation: a bounded string, a composite value, or any other concrete form remains an implementation decision for whichever component actually constructs one (today) and for the future Identity/Access specification (eventually) to make or revise — this document requires only the reference's identity contract (opaque, immutable, exactly comparable), never a specific representation.
- MUST carry no identity, session, or authorization semantics of its own — Accounting Core does not authenticate or authorize an Actor; it only records which already-authorized reference accepted a command. Actor/Tenant consistency remains a Posting Validation Pipeline responsibility (§7, §14) — the reference itself asserts nothing about Tenant match; the pipeline checks it.
- MUST be immutable once supplied, and comparable only for exact equality — never partially updated, never derived or normalized from a different value.

This is the minimum contract this document requires. The future Identity/Access specification MAY add fields, richer structure, or a different physical representation without contradicting this minimum, but MUST NOT remove the ability to resolve an Actor reference to a Tenant.

## 9. Source

Every Journal MUST retain a traceable Source reference — the Accounting Command, accepted Accounting Proposal, or correction reference that gave rise to it ([AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability)). A Posting Command MUST therefore itself carry that Source reference, so it can be recorded on the Journal it produces.

Source is distinct from Actor: Actor identifies *who* accepted the command; Source identifies *what* gave rise to it (a directly authored command, a confirmed AI proposal, or a Reversal/Replacement's reference to the Journal it corrects). The two are recorded together but answer different questions, exactly as [AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability) already establishes.

This document does not define Source's own concrete object schema — deferred alongside Actor and Evidence to a future Audit Trail specification (AETS-010, not yet created; §27). It defines only that a Posting Command must carry a reference sufficient to identify one.

### 9.1 Source reference (minimal contract)

Pending AETS-010's full Source schema (§27), a Posting Command's Source field is, at minimum, a **Source reference**: an opaque, immutable reference sufficient to trace back to the Accounting Command, accepted Accounting Proposal, or correction reference that gave rise to it. This reference:

- MUST be opaque — this specification does not constrain its physical representation, and imposes no requirement that it resolve to any specific concrete form.
- MUST NOT itself parse, interpret, or classify what it points to (a directly authored command, a confirmed AI proposal, or a correction reference) — that discrimination, where ever needed, belongs to whichever future component actually resolves the reference (AETS-010's own eventual design), never to the reference type itself.
- MUST be immutable once supplied, and comparable only for exact equality.

This is the minimum contract this document requires until AETS-010 defines Source's full schema.

## 10. Evidence references

Every Journal MUST retain Evidence linkage where applicable — where the underlying Business Transaction is evidence-backed, that linkage commits atomically with the Journal ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 2; [AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability)). A Posting Command for an evidence-backed effect MUST carry the Evidence reference(s) supporting it.

"Where applicable" is not a loophole: a Posting Command whose underlying Business Transaction *is* evidence-backed MUST NOT omit the reference and post anyway. A Journal with no independent evidence of its own — for example, a pure Reversal, whose evidentiary basis is the original Journal it references — is not required to fabricate one ([AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability)).

Evidence MUST NEVER be invented by AI ([ADR-0005](../../adr/0005-ai-provider-abstraction.md); [AETS-000 §5](AETS-000.md#5-ai-philosophy); [AETS-001](AETS-001-Accounting-Terminology.md#evidence)) — a Posting Command's Evidence reference(s) must resolve to real, previously retained Evidence, never to a value an AI proposal-producing process generated to satisfy this requirement.

This document does not define Evidence's own concrete object schema (retention, storage, hash verification) — deferred to a future Audit Trail specification (AETS-010, not yet created; §27), exactly as [AETS-001](AETS-001-Accounting-Terminology.md#evidence) already states. It defines only that a Posting Command must carry a reference sufficient to identify the Evidence it relies on, where any exists.

## 11. Journal payload

A Posting Command's Journal payload is exactly the proposed Journal identity plus the proposed Journal Lines (§5) — nothing more. This document does not add a field to either shape beyond what [AETS-004 §6–§8](AETS-004-Journal-Posting-Model.md#6-journal-aggregate) already defines for a Journal and its Journal Lines.

**Proposed Journal identity — two shapes, both legitimate.** [AETS-004 §9](AETS-004-Journal-Posting-Model.md#9-journal-states) deliberately does not decide "whether a Draft Journal is ever separately persisted before posting, or is assembled transiently from a Posting Command and validated in one step." This document does not resolve that either; it specifies both shapes a Posting Command's proposed Journal identity may therefore take:

1. **A fresh Journal.** The command carries an identifier for a Journal that does not yet exist, together with a complete proposed Journal Line set. Accounting Core assembles and validates a new Journal from this input.
2. **An existing Draft Journal.** The command references an already-persisted Draft Journal's identifier. Accounting Core MUST confirm that Journal's currently recorded state is still Draft (§14 step 3; `POST-018`) before proceeding — this is the "Draft-only candidate input" requirement.

Neither shape requires this document to invent a JournalId generation strategy. The existing `JournalId` Value Object deliberately exposes no self-generation method — an identifier is always supplied to it, never produced by it. This document does not change that design; how a caller or Accounting Core obtains a fresh, collision-free JournalId (a UUID, a ULID, a sequence, or otherwise) remains deferred, exactly like Idempotency Key derivation (§6, §27).

**Proposed Journal Lines** are exactly the {Account identifier, Money, Direction} triples [AETS-004 §7](AETS-004-Journal-Posting-Model.md#7-journal-line) already defines for a Journal Line — no line identifier, memo, or additional metadata is added here (consistent with `JournalLine`'s own already-settled minimal shape). Line order, where the command supplies it, is preserved through validation and, on success, through persistence — consistent with the already-implemented `JournalRepository`'s own `line_position`-based ordering (M3-T10), which this document does not redesign.

**Not yet a Journal.** Until the Posting Validation Pipeline (§14) succeeds, this payload is candidate input only — no `Journal` domain instance need exist yet, and if one is assembled early for validation purposes, it MUST remain Draft in every observable sense until the atomic persistence step (§17) commits it Posted. Accounting Core's existing `Journal::post()` operation — the sole Draft → Posted transition ([AETS-004 §9](AETS-004-Journal-Posting-Model.md#9-journal-states)) — and `JournalRepository::save()` — the sole persistence boundary, which already supports both a fresh insert and an existing-Draft-to-Posted transition (M3-T10) — remain the mechanisms a Posting Engine built against this document would use; this document does not replace or redesign either.

### 11.1 Financial date (M8)

Every Posting Command MUST also carry a **Financial Date**: the ledger-authoritative accounting date [AETS-004 §9.1](AETS-004-Journal-Posting-Model.md#91-financial-date-and-posted-at) requires every Journal to carry from the moment it is first assembled as a Draft — this is the same field, restated here as the Posting Command's own required input, not a second, independently-invented one.

- A Posting Command MUST carry a Financial Date. It is not conditionally required, and it is not defaulted by Accounting Core to `created_at`, "today," or any other system-derived value where the caller did not supply one — a caller that has no Financial Date to supply cannot construct a well-formed Posting Command (`POST-028`).
- Accounting Core copies a Posting Command's Financial Date directly onto the Journal it assembles ([AETS-004 §9.1](AETS-004-Journal-Posting-Model.md#91-financial-date-and-posted-at)) — it is never recomputed, adjusted, or overridden during the Posting Validation Pipeline (§14).
- **Financial Date is part of a Posting Command's logical-payload identity for idempotency purposes (§6.1).** The same (Tenant, Idempotency Key) reused with the same Journal Lines but a different Financial Date is a conflicting reuse, not a safe replay — because the Financial Date determines which accounting period the resulting Journal's effect belongs to, and a future Reporting/Period Management module MUST be able to trust that a replayed command never silently shifts an already-posted effect's period.
- Financial Date is compared, for both logical-equivalence and persistence purposes, at calendar-day precision — it identifies an accounting *day*, not a moment.
- **This document does not decide which Financial Date a correction Journal (Reversal or Replacement) should carry.** That selection is a Posting Rules/Period Management policy decision, deferred to AETS-006 (§27) — not "use the original Journal's date," not "use today's date," and not any other hard-coded rule. The calling layer that constructs a Reversal or Replacement request supplies the Financial Date explicitly, exactly as any other Posting Command caller does.

## 12. Account validation

Before a Posting Command's proposed Journal may post, every Account identifier a proposed Journal Line references MUST resolve through exactly the four-step sequence [AETS-005 §19](AETS-005-Chart-of-Accounts.md#19-journal-line-integration) already establishes, extending [AETS-004 §11](AETS-004-Journal-Posting-Model.md#11-posting-validation-pipeline)'s line-level validation step rather than replacing it:

1. The identifier resolves to an existing Account (`POST-008`).
2. That Account belongs to the same Tenant as the command (§7; `COA-015`; `POST-009`).
3. That Account is currently Active (`COA-009`; `POST-010`).
4. That Account is currently posting-eligible (`COA-008`, `COA-010`; `POST-011`).

Any failure at any of these four steps MUST reject the entire Posting Command before any persistent effect — not merely omit or skip the offending line.

**Steps 1 and 2 share one observable rejection category — tenant isolation takes precedence.** An Account identifier that does not resolve to any existing Account, and an Account that exists only under a different Tenant, MUST be treated as the same distinguishable rejection category by Posting validation, not two. This is not a relaxation of either check: both failure modes above (`POST-008`, `POST-009`) MUST still independently cause rejection — an Account belonging to a different Tenant is never accepted merely because that category is shared with "does not exist." Only the *externally observable category* a caller receives is shared. Posting validation MUST NOT perform a tenant-unscoped lookup, or any other means, merely to reveal which of the two physical causes applies — doing so would itself leak cross-tenant Account existence, contradicting Tenant isolation (AETS-002 invariant 11; `COA-001`). This mirrors the already-established, security-motivated design of the existing Account repository contract, which returns the same "not found" result for both cases by construction — this document aligns to that existing behavior rather than requiring it be weakened. Steps 3 and 4 remain fully independent, distinguishable categories, both from each other and from steps 1–2 (`POST-010`, `POST-011`).

**NormalBalance MUST NOT determine Journal Direction.** An Account's Normal Balance ([AETS-005 §11](AETS-005-Chart-of-Accounts.md#11-normal-balance)) is a classificatory property of the Account, derived deterministically from its Account Type. It is never consulted to infer, default, or validate a Journal Line's own explicit Direction (§13; [AETS-004 §8](AETS-004-Journal-Posting-Model.md#8-debit-and-credit-semantics); [AETS-005 §11](AETS-005-Chart-of-Accounts.md#11-normal-balance)). A Posting Engine built against this document MUST accept exactly the Direction a Posting Command's Journal Line supplies, and MUST NOT derive one from the referenced Account's Type or Normal Balance.

**The Posting Engine MUST NOT mutate Account balances.** No Account carries a monetary balance as mutable authoritative state ([AETS-005 §6](AETS-005-Chart-of-Accounts.md#6-account-model), `COA-012`); any balance a caller observes MUST be derived from posted Journals (AETS-002 invariant 5; [AETS-001](AETS-001-Accounting-Terminology.md#balance)). A successful Posting Command's atomic persistence step (§17) writes the Journal and its Lines — an append-only insert — and never an update to any Account row's balance field, because no such field exists to update.

## 13. Money validation

Every proposed Journal Line's Money MUST satisfy exactly the rules [AETS-003](AETS-003-Money-Specification.md) and [AETS-004 §7–§8](AETS-004-Journal-Posting-Model.md#7-journal-line) already establish — this document restates them as Posting Command intake requirements, it does not add to or narrow them:

- **Exact Money only.** A Money value is the exact representation [AETS-003](AETS-003-Money-Specification.md) already defines — never a raw number, never a formatted string.
- **No binary float, at any point.** A native binary-float monetary value MUST be rejected at the type level, before any grammar or value validation runs (`MON-001`; `JRN-009`; `POST-017`).
- **Non-negative magnitude.** A Journal Line's Money MUST be a non-negative magnitude; it is never negated to express the accounting sign (`JRN-009`; `POST-014`).
- **Direction carries polarity, not Money.** The Debit/Credit sign is carried entirely by the line's explicit Direction, never by the sign of its Money value ([AETS-004 §8](AETS-004-Journal-Posting-Model.md#8-debit-and-credit-semantics); `POST-015`).
- **One Currency per Journal.** Every Journal Line within one proposed Journal MUST share exactly one Currency (`JRN-011`; `POST-013`); Money's own cross-currency guard (`MON-006`) forbids comparing or summing Money of different Currency, and multi-currency Journals remain out of MVP scope ([`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §9).
- **Exact Debit == Credit.** Total Debit Money MUST equal total Credit Money exactly — zero tolerance, no rounding allowance — computed via Money's own exact addition and equality, never native numeric comparison (`JRN-007`; `POST-016`). This is the pipeline's balance-validation step (§14 step 5), stated here as the Money-level rule it enforces.

This document does not reopen Money's general sign-policy deferral ([AETS-003 §25](AETS-003-Money-Specification.md#25-deferred-items)) beyond what [AETS-004 §8](AETS-004-Journal-Posting-Model.md#8-debit-and-credit-semantics) already resolved for Journal Line's own usage.

## 14. Validation pipeline

Every Posting Command MUST be validated, in full, before any persistent effect occurs. This document does not invent a competing pipeline — the authoritative logical ordering remains exactly [AETS-004 §11](AETS-004-Journal-Posting-Model.md#11-posting-validation-pipeline)'s five steps, followed by atomic persistence (§13 of that document, §17 of this one). What this document adds is the concrete sub-checks each step now performs, once the Posting Command contract (§4–§11) and the Account contract ([AETS-005](AETS-005-Chart-of-Accounts.md)) exist to perform them against:

1. **Tenant ownership** (§7; [AETS-004 §11](AETS-004-Journal-Posting-Model.md#11-posting-validation-pipeline) step 1) — the command's Tenant, every referenced Account's Tenant, the Actor's Tenant, any Evidence's Tenant, and (if referenced) the existing Draft Journal's Tenant, all match.
2. **Command/idempotency validation** (§6; step 2) — the command is well-formed (Idempotency Key, TenantId, and Actor present, §6.1, §8; a Source Fingerprint present wherever §6.2's rule requires one); whether a Journal already exists for this (Tenant, Idempotency Key) pair; if so, the remaining steps are skipped and the original result is returned (§15).
3. **Journal-state validation** (§11; step 3) — if referencing an existing Draft Journal, it is still Draft, not already Posted (`POST-018`).
4. **Account existence and Tenant ownership** (§12 step 1–2) — every proposed line's Account identifier resolves to an existing Account belonging to the command's Tenant. *(Extends step 1/step 4 together — an Account's own tenant-match is, structurally, part of tenant ownership; its existence is part of line-level validation. Both are grouped here because [AETS-005 §19](AETS-005-Chart-of-Accounts.md#19-journal-line-integration) states them as one ordered sequence.)*
5. **Account active/posting-eligible validation** (§12 step 3–4; [AETS-005 §19](AETS-005-Chart-of-Accounts.md#19-journal-line-integration) steps 3–4).
6. **Money/currency validation** (§13; [AETS-004 §11](AETS-004-Journal-Posting-Model.md#11-posting-validation-pipeline) step 4) — every line's Money is exact, non-float, non-negative, and every line shares one Currency.
7. **Journal structural validation** (step 4) — at least two lines (`JRN-002`), and every line carries exactly one Direction (`JRN-008`).
8. **Debit == Credit validation** (§13; step 5) — total Debit Money exactly equals total Credit Money.
9. **Atomic persistence** (§17; [AETS-004 §13](AETS-004-Journal-Posting-Model.md#13-atomicity)) — only once every step above has succeeded.

Only once every step above succeeds does atomic persistence run. Implementations MAY combine or reorder these checks internally for efficiency, provided the observable guarantee — no persistent ledger effect occurs before every check has passed — holds exactly, exactly as [AETS-004 §11](AETS-004-Journal-Posting-Model.md#11-posting-validation-pipeline) already permits.

**Reconciliation notes, where this document's more granular checks needed mapping onto AETS-004's five canonical steps rather than becoming new ones:**

- Actor and Source validation are not separate pipeline steps. Actor and Source presence is part of a Posting Command's structural well-formedness (step 2, alongside the Idempotency Key); Actor's Tenant-match is part of step 1, exactly as [AETS-004 §18](AETS-004-Journal-Posting-Model.md#18-tenant-isolation) already states ("A Posting Command MUST fail — before any persistent effect (§11 step 1) — if any Account it references, any Evidence it links, its Actor, or ... the Draft Journal it posts, does not belong to the command's own Tenant").
- Source Fingerprint validation is likewise not a separate pipeline step. Whether one is required at all is conditional (§6.2); where required, its presence is checked as part of step 2's well-formedness gate, alongside the Idempotency Key — never as an eleventh step, and never conflated with Idempotency Key's own, unconditional guarantee (§6.1).
- Evidence validation, where required, is likewise part of step 1's Tenant-ownership check (§7) plus step 9's atomic linkage requirement (§10, §17) — it is not an independent pipeline stage with its own ordering position.
- "Duplicate prevention" is not step 11 of a longer list — it is the guarantee step 2 (the idempotency check) and step 9 (atomic persistence's database-level uniqueness, the final race-safe authority) jointly provide. §15 states this guarantee on its own, in full, rather than inventing a pipeline position for it.
- Account existence/Tenant-ownership and Account active/posting-eligible are presented as two grouped sub-steps (4–5 above) rather than folded silently into "line-level validation," because [AETS-005 §19](AETS-005-Chart-of-Accounts.md#19-journal-line-integration) already specifies them as an explicit four-step sequence extending AETS-004 §11 — this document preserves that existing sequence exactly rather than re-deriving it.

## 15. Duplicate prevention

Duplicate prevention is not a single check — it is a guarantee spanning two points in the pipeline (§14), exactly as [AETS-004 §14](AETS-004-Journal-Posting-Model.md#14-idempotency) and [AETS-004 §20](AETS-004-Journal-Posting-Model.md#20-concurrency-and-locking-expectations) already establish:

1. **Before validation (logical check).** Step 2 of the pipeline determines whether a Journal already exists for the incoming command's (Tenant, Idempotency Key) pair. If one exists, no further validation runs — the existing result is returned (§6, §19).
2. **At persistence (structural authority).** The (Tenant, Idempotency Key) → Journal association MUST be recorded durably as part of the same atomic transaction that posts the Journal (§17), and a database-level uniqueness constraint on that pair MUST be the final authority that two genuinely concurrent commands — both of which passed step 1's logical check before either committed — cannot both succeed in creating a Journal. The command that loses that race MUST detect the conflict (via the constraint violation, or an equivalent race-safe mechanism) and return the winner's result, never error destructively and never create a second Journal.

`JRN-015` ("duplicate-effect prevention") is a `JournalRepository`-adjacent concern already partially proven at the repository level (row-locking preventing two concurrent writers from silently applying conflicting changes to the same Journal — see `ATS-004`'s `JRN-T185`–`JRN-T186`). This section is the Posting-Command-level completion of that guarantee: it additionally requires the (Tenant, Idempotency Key) uniqueness constraint specifically, which a bare Journal repository has no reason to know about, since Idempotency Key is a Posting Command concern, not a Journal persistence concern (§6).

This document does not mandate a specific uniqueness-constraint shape (a dedicated idempotency-record table, a unique index on the Journal table itself, or otherwise) — that is part of the concrete Idempotency Key storage schema §6.1 and §27 already defer.

**Distinct from Source Fingerprint duplicate prevention.** The guarantee above prevents a duplicate *command* from creating a second Journal. Where a Posting Command also carries a Source Fingerprint (§6.2), that fingerprint, combined with its own uniqueness constraint, additionally prevents the same *source data* from ever giving rise to two different commands in the first place ([AETS-001](AETS-001-Accounting-Terminology.md#source-fingerprint); [AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 8). The two mechanisms are complementary layers, not alternatives — neither substitutes for the other, and this document does not weaken either to accommodate the other.

## 16. Posting execution boundary

Accounting Core is the exclusive authority for accepting a Posting Command and executing the Draft → Posted transition ([ADR-0001](../../adr/0001-modular-monolith-architecture.md); [AETS-004 §10](AETS-004-Journal-Posting-Model.md#10-posting-command)). No other module, and no AI or proposal-producing process, may perform that transition, bypass any step of the Posting Validation Pipeline (§14), or invoke the domain-level `Journal::post()` operation directly against a Posting Command it did not itself validate.

This document does not replace, wrap, or redesign the domain and persistence primitives Accounting Core already has for this purpose — it describes the boundary a Posting Engine implementation sits behind:

- `Journal::post()` remains the sole Draft → Posted domain transition ([AETS-004 §9](AETS-004-Journal-Posting-Model.md#9-journal-states)); it performs no I/O and decides nothing about *whether* posting should be allowed — a Posting Engine calls it only after the full pipeline (§14) has already succeeded.
- `JournalRepository::save()` remains the sole persistence boundary for a Journal and its Lines, already supporting exactly the two shapes §11 describes (a fresh insert, or an existing Draft's transition to Posted) and already refusing any other silent mutation. A Posting Engine's atomic persistence step (§17) uses this repository; it does not invent a second write path to the `journals`/`journal_lines` schema.
- Evidence linkage, Audit Event, and Outbox event writes (§17, §22) are additional writes the same atomic transaction must also perform; this document does not design their own persistence mechanisms, only requires that they commit or roll back together with the Journal (§17).

A Posting Command that never reaches this boundary — because it failed the pipeline (§14) — has produced no call to either primitive above, and therefore no ledger effect of any kind (§18).

## 17. Atomic transaction requirements

A successful Posting Command's persistent effect MUST commit or roll back together, as one PostgreSQL transaction ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 2; [AETS-004 §13](AETS-004-Journal-Posting-Model.md#13-atomicity); [ADR-0004](../../adr/0004-financial-integrity-principles.md)). At minimum, that transaction boundary MUST include:

- the Journal header;
- every Journal Line;
- the required Evidence association, where the underlying Business Transaction is evidence-backed (§10);
- the required Audit Event (§22); and
- any required Outbox event (§22; [ADR-0006](../../adr/0006-transactional-outbox-pattern.md)).

**No network calls inside the transaction.** No network call, queue publication, or other external/asynchronous work MUST execute inside that transaction ([ADR-0006](../../adr/0006-transactional-outbox-pattern.md); `JRN-013`; `POST-022`). Any such work is handed off via the transactional outbox after commit. This document does not design the external delivery workers, dispatchers, or consumers that later process an Outbox event — that remains [ADR-0006](../../adr/0006-transactional-outbox-pattern.md)'s own deferred scope, unchanged here (§27).

**Failure inside the transaction.** If any required write within it fails, the entire transaction MUST roll back — there MUST be no partially posted Journal, no orphaned Line, and no orphaned Evidence-linkage, Audit, or Outbox record observable afterward (§18).

This document does not mandate a specific locking or isolation-level mechanism for the transaction beyond what §6 and §15 already require for idempotency/duplicate prevention specifically.

## 18. Failure semantics

**Before persistence.** Any Posting Validation Pipeline failure (§14), at any step, MUST produce zero Journal effect and zero partial ledger effect of any kind — no Journal row, no Line row, at any state ([AETS-004 §21](AETS-004-Journal-Posting-Model.md#21-failure-semantics)).

**Inside atomic persistence.** Any failure once the atomic persistence step (§17) has begun MUST cause a full rollback — no partially Posted Journal, no orphaned Line, Evidence linkage, Audit Event, or Outbox record survives.

**AI failure.** AI confidence, an AI-assigned score, or any AI output MUST NEVER override or bypass Accounting Core's own validation (§20; [AETS-000 §5](AETS-000.md#5-ai-philosophy)). An invalid AI-originated proposal or command cannot post, regardless of how confident its source claims to be, and regardless of which pipeline step it fails at.

**Distinguishable failure categories.** Restating [AETS-004 §21](AETS-004-Journal-Posting-Model.md#21-failure-semantics) at the Posting Command level, distinct, typed failure categories MUST exist for at least: a malformed command (missing Idempotency Key, TenantId, or Actor, §6, §8); a Tenant-ownership mismatch (§7); a conflicting Idempotency Key reuse, distinct from an idempotent replay (§6.1); a required Source Fingerprint missing from a source-derived command, distinct from every other malformed-command category (§6.2); a Journal not in a postable state (§11 step 3); an Account reference that cannot be resolved within the command's own Tenant — whether because it does not exist at all, or exists only under a different Tenant, one shared category by design, per §12's tenant-isolation precedence — an Account that is Inactive (its own, separate category), and an Account that is not posting-eligible (its own, separate category) (§12); an invalid Journal Line (missing/multiple Account reference, missing/dual Direction, invalid or float Money) (§13); an unbalanced Journal (§13); and a persistence/infrastructure failure (§17). Each category MUST be distinguishable by callers (for example, by type), never merged into one generic error ([AETS-004 §21](AETS-004-Journal-Posting-Model.md#21-failure-semantics)).

## 19. Success semantics

A successful Posting Command MUST produce exactly one authoritative Posted Journal, and a deterministic terminal result carrying enough information for the caller (an application or UI layer) to present the outcome — this document does not design that presentation, only what the result must convey (§4, `POST-021`).

**Resolving [AETS-004 §14](AETS-004-Journal-Posting-Model.md#14-idempotency)'s deferred indicator.** [AETS-004 §14](AETS-004-Journal-Posting-Model.md#14-idempotency) requires that an idempotent replay return "the original Journal's result, deterministically," while explicitly leaving open whether a caller can tell "first success" apart from "safe replay," pending "an explicit indicator that this document does not design." As the document AETS-004 itself deferred the concrete Posting Command contract to (§1), this document resolves that specific point: a Posting Command's terminal result MUST allow the caller to determine whether this specific invocation newly posted the Journal or matched an already-processed command for the same (Tenant, Idempotency Key) pair. This document does not design the concrete field, response schema, or API shape that indicator takes — only that the information MUST be present and MUST be deterministic.

At minimum, a successful terminal result MUST identify: the Posted Journal (by its stable identifier), that its resulting state is Posted, and the newly-posted-vs-replay indicator above. This document does not design UI presentation of any of this (§2.2).

## 20. AI-originated command rules

Restating [AETS-004 §10](AETS-004-Journal-Posting-Model.md#10-posting-command) and [AETS-004 §23](AETS-004-Journal-Posting-Model.md#23-prohibited-operations) as the Posting Command's own explicit rule, per [AETS-000 §5](AETS-000.md#5-ai-philosophy) and [ADR-0005](../../adr/0005-ai-provider-abstraction.md):

- An AI-originated Accounting Proposal has no posting authority of its own. It MUST be explicitly confirmed by an authorized Actor, or deterministically accepted through Accounting Core's own controls, before it can become a Posting Command (§4).
- Neither AI nor any automated confidence threshold MUST be able to bypass that confirmation step, or bypass any stage of the Posting Validation Pipeline (§14), regardless of the proposal's origin or confidence score.
- AI, or any other proposal-producing process, MUST NOT itself be recorded as the Actor accepting a Posting Command (§8).
- AI MUST NOT fabricate Evidence to satisfy §10's requirement.
- No code path may treat an Accounting Proposal as pre-confirmed merely because of a high confidence score, a prior similar proposal's outcome, or any other AI-internal signal — confirmation is a distinct, required step, never inferred.

This section states no new rule AETS-002/AETS-000/AETS-004/ADR-0005 does not already require; it exists so a Posting Command implementation has one place that states the rule specifically in terms of the command contract this document defines.

## 21. Security and tenant isolation

Application, query, database, and storage controls MUST all prevent cross-tenant access to a Journal, Journal Line, or Account referenced by a Posting Command, consistent with [ADR-0004](../../adr/0004-financial-integrity-principles.md), [AETS-004 §18](AETS-004-Journal-Posting-Model.md#18-tenant-isolation), and [AETS-005 §17](AETS-005-Chart-of-Accounts.md#17-tenant-ownership). This document does not design the specific enforcement mechanism (row-level security, query scoping, or otherwise) — neither of the documents it extends does either.

A Posting Command referencing a cross-tenant Account, Actor, Evidence, or Draft Journal MUST fail at the pipeline's tenant-ownership step (§7, §14 step 1), before any persistent effect — never partially validated, never partially applied, and never revealed to the caller as anything more specific than a tenant-ownership failure category (§18) that would leak the existence of another Tenant's record.

A Reversal or Replacement Posting Command MUST belong to the same Tenant as the Journal(s) it references — cross-tenant correction MUST NOT be possible ([AETS-004 §18](AETS-004-Journal-Posting-Model.md#18-tenant-isolation)). This document does not otherwise design Reversal/Replacement Posting Command mechanics beyond this tenant-isolation restatement (§2.2; AETS-006).

## 22. Auditability requirements

Every successful Posting Command MUST produce an Audit Event capturing at minimum Actor, Tenant, Source, and time, committed atomically with the Journal it posts ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 12; [AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability); [AETS-001](AETS-001-Accounting-Terminology.md#audit-event)). This document does not design the Audit Event's detailed schema — deferred to a future Audit Trail specification (AETS-010, not yet created), exactly as [AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability) already states.

Where a successful Posting Command requires any asynchronous or external effect (for example, a future MyInvois submission, or a notification), the corresponding Outbox event MUST be recorded within the same atomic transaction as the Journal itself, never as a separate, later write ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariants 2 and 14; [ADR-0006](../../adr/0006-transactional-outbox-pattern.md)). This document does not design the Outbox event's schema, delivery mechanism, or dispatcher — [ADR-0006](../../adr/0006-transactional-outbox-pattern.md) already defers all of that, unchanged here (§27).

Evidence linkage, where applicable, retains its original file, hash, uploader, and timestamp ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 12; [AETS-001](AETS-001-Accounting-Terminology.md#evidence)) — this document does not design that retention mechanism, only requires the linkage commit atomically with the Journal (§10, §17).

## 23. Invariants

Each invariant below states a **Posting Engine responsibility** for accepting and executing a Posting Command — additional to [AETS-002](AETS-002-Accounting-Invariants.md)'s 14 invariants and to [AETS-004](AETS-004-Journal-Posting-Model.md)'s `JRN-NNN`/[AETS-005](AETS-005-Chart-of-Accounts.md)'s `COA-NNN` invariants, which each already state the underlying accounting fact. Where a `POST-NNN` invariant restates a `JRN-NNN`/`COA-NNN`/`MON-NNN` invariant, it does so only to state the Posting Command/pipeline-level responsibility for enforcing it before persistence — it does not duplicate that invariant's own wording or introduce a competing definition.

| ID | Invariant |
| --- | --- |
| POST-001 | **Tenant ownership validated at acceptance.** The Posting Engine MUST reject a Posting Command, before any persistent effect, if its Tenant, any referenced Account, its Actor, any referenced Evidence, or any existing Draft Journal it references does not belong to the command's own Tenant (§7). |
| POST-002 | **Command identity required.** The Posting Engine MUST NOT accept a Posting Command with no Idempotency Key, no TenantId, or no Actor — such a command is not well-formed and MUST be rejected before any pipeline step runs (§6, §8). |
| POST-003 | **Idempotent accounting effect.** The Posting Engine MUST ensure that retrying the same logical Posting Command (same Tenant, same Idempotency Key, same logical request) never creates a second Journal or any second accounting effect; it MUST return or identify the original result instead (§6). |
| POST-004 | **Conflicting idempotency reuse rejected.** The Posting Engine MUST reject, loudly and distinguishably from an idempotent replay, any command that reuses an already-used (Tenant, Idempotency Key) pair with a materially different logical request (§6). |
| POST-005 | **Valid Actor and Source required.** The Posting Engine MUST reject a Posting Command that does not carry both an identified, authorized Actor and a traceable Source reference; it MUST NOT itself supply, infer, or default either (§8, §9). |
| POST-006 | **AI cannot be recorded as Actor.** The Posting Engine MUST NOT record AI, or any other proposal-producing process, as the Actor accepting a Posting Command (§8, §20). |
| POST-007 | **Evidence traceability where applicable.** Where the Business Transaction underlying a Posting Command is evidence-backed, the Posting Engine MUST require and persist that Evidence linkage atomically with the resulting Journal; it MUST NOT post an evidence-backed effect with the linkage silently omitted, and MUST NOT accept AI-fabricated Evidence (§10). |
| POST-008 | **Account existence required.** The Posting Engine MUST reject a Posting Command referencing an Account identifier that does not resolve to an existing Account (§12). |
| POST-009 | **Account same-Tenant ownership.** The Posting Engine MUST reject a Posting Command referencing an Account belonging to a different Tenant than the command itself (§7, §12). |
| POST-010 | **Account must be Active.** The Posting Engine MUST reject a Posting Command referencing an Account currently Inactive (§12). |
| POST-011 | **Account must be posting-eligible.** The Posting Engine MUST reject a Posting Command referencing an Account whose posting-eligibility state is non-posting (§12). |
| POST-012 | **Minimum Journal Line count.** The Posting Engine MUST reject a Posting Command whose proposed Journal contains fewer than two Journal Lines (§13, §14). |
| POST-013 | **Single Currency per Journal.** The Posting Engine MUST reject a Posting Command whose proposed Journal Lines do not all share exactly one Currency (§13). |
| POST-014 | **Non-negative Money magnitude.** The Posting Engine MUST reject a Posting Command carrying a Journal Line whose Money is not a non-negative magnitude (§13). |
| POST-015 | **Explicit Direction required.** The Posting Engine MUST reject a Posting Command carrying a Journal Line without exactly one explicit Direction, and MUST NOT infer Direction from a referenced Account's Normal Balance (§12, §13). |
| POST-016 | **Exact Debit == Credit.** The Posting Engine MUST reject a Posting Command whose proposed Journal's total Debit Money does not exactly equal its total Credit Money — zero tolerance (§13, §14). |
| POST-017 | **No binary float anywhere in the pipeline.** The Posting Engine MUST reject, at the earliest possible validation step, any Posting Command input carrying a native binary-float monetary value (§13). |
| POST-018 | **Draft-only candidate input.** Where a Posting Command references an existing Journal by identity, the Posting Engine MUST reject the command unless that Journal's currently recorded state is Draft (§11, §14). |
| POST-019 | **Atomic posting.** On success, the Posting Engine MUST commit the Journal header, every Journal Line, required Evidence linkage, the Audit Event, and any required Outbox event together, as one database transaction (§17). |
| POST-020 | **Rollback on failure.** If any required write within that transaction fails, the Posting Engine MUST roll back the entire transaction, leaving zero partial Journal, Line, Evidence-linkage, Audit, or Outbox effect (§17, §18). |
| POST-021 | **One authoritative Posted Journal on success.** A successful Posting Command MUST result in exactly one Posted Journal becoming authoritative; the Posting Engine MUST NOT produce two Posted Journals from one logical command, and MUST NOT leave a Journal in an intermediate or ambiguous state (§19). |
| POST-022 | **No network call inside the transaction.** The Posting Engine MUST NOT perform any network call, queue publication, or other external/asynchronous work inside the posting database transaction (§17). |
| POST-023 | **No direct AI posting authority.** The Posting Engine MUST NOT accept, execute, or treat as pre-confirmed any Posting Command whose Actor is AI or another proposal-producing process; an AI-originated Accounting Proposal MUST pass through explicit human confirmation or a deterministic Accounting Core acceptance process first (§20). |
| POST-024 | **Audit traceability.** The Posting Engine MUST produce, atomically with every successful Posting Command, an Audit Event capturing at minimum Actor, Tenant, Source, and time (§22). |
| POST-025 | **Outbox consistency.** Where a successful Posting Command requires any asynchronous or external effect, the Posting Engine MUST record the corresponding Outbox event within the same atomic transaction as the Journal itself, never as a separate, later write (§17, §22). |
| POST-026 | **Required Source Fingerprint fails safely when missing.** The Posting Engine MUST reject, before any persistent effect, a Posting Command that originates from, or is materially derived from, external/imported source data requiring duplicate-source detection, if it does not carry the required Source Fingerprint (§6.2). |
| POST-027 | **No fabricated Source Fingerprint.** The Posting Engine MUST NOT accept, or itself generate, a Source Fingerprint for a Posting Command with no external source data behind it, merely to satisfy `POST-026` (§6.2). |
| POST-028 | **Financial Date required and never self-generated.** The Posting Engine MUST reject a Posting Command carrying no Financial Date; it MUST NOT itself substitute `created_at`, "today," or any other system-derived value on the caller's behalf, and MUST treat a same-key reuse with a different Financial Date as a conflicting reuse, not a safe replay (§11.1). |

## 24. ATS requirements

This section states what a future Posting Command Test Specification (`ATS-007`, numbered to match this document) MUST prove; it does not write that test specification.

A Posting Command ATS MUST include:

- **Invariant traceability** — every `POST-NNN` invariant (§23) traced to at least one test, mirroring `ATS-004`'s and `ATS-005`'s established traceability-matrix pattern.
- **Valid posting** — a representative range of well-formed Posting Commands (§25) posting successfully, each producing exactly one Posted Journal.
- **Every validation rejection** — a dedicated test for each pipeline step's failure mode (§14, §18): malformed command, Tenant-ownership mismatch, non-Draft existing Journal, Account non-existence, Account cross-tenant, Account Inactive, Account non-posting-eligible, invalid/float Money, mixed Currency, insufficient lines, missing/dual Direction, and unbalanced Journal — each proven to produce zero persistent effect, not merely an exception.
- **Idempotent retry** — a repeated Posting Command with the same Idempotency Key returning the original result and never creating a second Journal (§6).
- **Conflicting idempotency reuse** — the same Idempotency Key with a materially different logical request rejected loudly, distinguishable from a safe replay (§6.1, §15).
- **Required Source Fingerprint rejection and anti-fabrication** — a source-derived command missing its required Source Fingerprint rejected safely before any persistent effect, distinguishable from every other failure category; and proof no fabricated Source Fingerprint is accepted or generated for a purely manual command (§6.2, `POST-026`, `POST-027`).
- **Financial Date persistence, replay, and conflict** — the Financial Date supplied on a Posting Command is persisted exactly onto the Journal it produces; a replayed command's Financial Date is never silently overwritten; and the same (Tenant, Idempotency Key) reused with an identical Journal Line set but a different Financial Date is rejected as a conflicting reuse, not accepted as a safe replay (§11.1, `POST-028`).
- **Concurrent duplicate attempts** — two simultaneous Posting Commands with the same Idempotency Key, and a Draft Journal posted concurrently by two different commands, each producing exactly one Posted Journal (§6, §15, §20 of AETS-004).
- **Account status/tenant rejection** — each of the four Account-resolution steps (§12) independently proven to reject, against real data, not merely asserted as a rule.
- **Unbalanced rejection** — proven with zero tolerance, including a one-minor-unit imbalance (§13).
- **Malformed Money rejection** — proven at the type level (float) and the value level (invalid/over-precision) (§13).
- **Atomic rollback fault injection** — a fault injected mid-transaction against real PostgreSQL, proving zero partial Journal, Line, Evidence-linkage, Audit, or Outbox record afterward (§17, §18).
- **No partial Journal** — proven directly by querying the database after every rejection and every fault-injected failure, not inferred from an exception alone.
- **Audit Event atomicity** — proven to commit or roll back together with the Journal, never independently (§22).
- **Outbox atomicity** — proven to commit or roll back together with the Journal, never independently, and never published from inside the transaction (§17, §22).
- **No-network-in-transaction architecture proof** — a static/architectural test proving no network, queue, mail, or notification call exists inside the posting transaction's code path (§17; mirroring `JRN-T032`/`JRN-T192`'s established technique).
- **AI-originated command validation parity** — proving an AI-originated Accounting Proposal, once confirmed into a Posting Command, is validated through exactly the same pipeline (§14) as a directly human-authored command, with no shortcut, and that an unconfirmed AI proposal cannot post at all (§20).
- **Real PostgreSQL concurrency/integration tests** — every atomicity, idempotency, and concurrency claim above proven against a real PostgreSQL instance, using genuinely independent connections where concurrency is claimed, never SQLite as evidence of any of them — consistent with the precedent already established across `ATS-003`, `ATS-004`, and `ATS-005`.

## 25. Examples (Informative)

**These examples are illustrative only and are not normative.** Account identifiers are abstract placeholders (`ACCOUNT-CASH`, `ACCOUNT-INCOME`, and so on) — this document does not design final account codes, a Chart of Accounts, or any business transaction mapping (§2.2).

- **Simple cash sale command.** A Posting Command with Idempotency Key `K1`, Tenant `TENANT-A`, Actor `ACTOR-1`, proposing a fresh Journal: Debit `ACCOUNT-CASH` RM100.00, Credit `ACCOUNT-INCOME` RM100.00. Both Accounts exist, belong to `TENANT-A`, are Active and posting-eligible; total Debit equals total Credit exactly. Every pipeline step (§14) succeeds — the command posts, producing exactly one Posted Journal.
- **Simple expense command.** A Posting Command proposing: Debit `ACCOUNT-EXPENSE` RM45.50, Credit `ACCOUNT-CASH` RM45.50, evidence-backed by a referenced receipt. Balanced, both Accounts valid — posts successfully, with the Evidence linkage committed atomically.
- **Rejected inactive Account.** The cash-sale command above, but `ACCOUNT-CASH` is currently Inactive. Step 5 of the pipeline (§14) rejects the command with a distinct "Account Inactive" failure category (§18); no Journal is posted, no partial effect occurs.
- **Rejected unbalanced command.** A Posting Command proposing: Debit `ACCOUNT-EXPENSE` RM45.50, Credit `ACCOUNT-CASH` RM45.00. Total Debit ≠ total Credit — step 8 of the pipeline (§14) rejects the command with a distinct "unbalanced" failure category; no Journal is posted.
- **Duplicate retry.** The cash-sale command above is submitted, posts, and creates Journal `J1`. The same command — same Tenant, same Idempotency Key `K1`, same logical request — is submitted again (a client retry after a timeout). Step 2 of the pipeline (§14, §6) recognizes the existing (Tenant, `K1`) association and returns `J1`'s result, with the terminal result's replay indicator (§19) set accordingly; no second Journal is created.

## 26. Business-specific Accounting Command mappings (M6–M21)

This section resolves, for every business-specific Accounting Command actually built as of v2.1.0, the deferral §1/§27 otherwise leaves open. None of these commands has fields, an identity, or a validation rule of its own beyond what §4–§19 already specify for the Posting Command — each is, in full, a caller that assembles a Posting Command with a fixed set of Journal Lines, and is validated through exactly the same Posting Validation Pipeline (§14) as any other. This section records only what §2.2 previously left undocumented: *which* Accounts each command's two Journal Lines target, in which Direction, and where each command's Financial Date and Source reference come from. A command not listed here (Bank Reconciliation, MyInvois, a Credit Note, and so on) remains fully deferred (§27).

**Why this section exists.** [AETS-000 §7](AETS-000.md#7-design-principles) requires "explicit boundaries" and treats an AETS document as the specification implementers build from; each mapping below was already implemented, and already tested against ([`AllocationServiceIntegrationTest`](../../../apps/api/tests/Feature/Domain/Payments/AllocationServiceIntegrationTest.php), [`InvoiceIssuingServiceIntegrationTest`](../../../apps/api/tests/Feature/Domain/Invoicing/InvoiceIssuingServiceIntegrationTest.php), and each translator's own unit test), before this section was written. Recording it here closes that gap rather than leaving the account mapping for an already-shipped feature discoverable only by reading its translator class.

**None of these mappings introduces a new `POST-NNN` invariant.** Each is fully governed by the invariants §23 already states — POST-012 (minimum two lines), POST-013 (single Currency), POST-016 (exact Debit == Credit, satisfied by construction since both lines below always carry the same `Money` amount), and POST-015 (explicit Direction, never inferred from an Account's Normal Balance) apply to every mapping below exactly as to any other Posting Command. This section states *which* Accounts and Directions each command's translator supplies to satisfy them — it does not relax or add to the pipeline itself.

### 26.1 Expense Recording Command (M6/M7)

| Line | Account | Direction |
| --- | --- | --- |
| 1 | Expense Account (the command's own `expenseAccountId`) | Debit |
| 2 | Payment Account (the command's own `paymentAccountId`) | Credit |

- **Financial Date:** the command's own `transactionDate` (M8) — never `created_at` or "today."
- **Source reference:** `expense:{ExpenseId}` (self-referential — traces to the Expense record, per §9).
- **Source Fingerprint:** never supplied (§6.2) — a manually-authored Expense command is not materially derived from external/imported source data, even where it carries an Evidence reference; no file-upload/import pipeline exists yet to define what a duplicate would mean.
- **Evidence:** carried where the command's own `evidenceReference` is non-null; omitted otherwise (§10).
- Implemented by [`ExpenseToPostingCommandTranslator`](../../../apps/api/app/Domain/Transactions/Expense/ExpenseToPostingCommandTranslator.php).

### 26.2 Income Recording Command (M9)

| Line | Account | Direction |
| --- | --- | --- |
| 1 | Deposit Account (the command's own `depositAccountId`) | Debit |
| 2 | Income Account (the command's own `incomeAccountId`) | Credit |

- **Financial Date:** the command's own `transactionDate` (M8).
- **Source reference:** `income:{IncomeId}`.
- **Source Fingerprint:** never supplied, for the identical reason as §26.1.
- **Evidence:** carried where the command's own `evidenceReference` is non-null; omitted otherwise.
- Implemented by [`IncomeToPostingCommandTranslator`](../../../apps/api/app/Domain/Transactions/Income/IncomeToPostingCommandTranslator.php).

### 26.3 Transfer Command (M14)

| Line | Account | Direction |
| --- | --- | --- |
| 1 | Destination Account (the command's own `destinationAccountId`) | Debit |
| 2 | Source Account (the command's own `sourceAccountId`) | Credit |

- **Financial Date:** the command's own `transactionDate` (M8).
- **Source reference:** `transfer:{TransferId}`.
- **Source Fingerprint:** never supplied, for the identical reason as §26.1.
- **Evidence:** carried where the command's own `evidenceReference` is non-null; omitted otherwise.
- Implemented by [`TransferToPostingCommandTranslator`](../../../apps/api/app/Domain/Transactions/Transfer/TransferToPostingCommandTranslator.php).

### 26.4 Owner Equity Transaction Command (M15)

The only mapping in this section where a business-level enum (`OwnerEquityMovementType`) selects between two fixed, mutually-reversed Line sets — never derived from a `Money` sign or any other implicit signal:

| Movement | Line 1 | Line 2 |
| --- | --- | --- |
| Contribution | Debit the Cash Account | Credit the Equity Account |
| Drawing | Debit the Equity Account | Credit the Cash Account |

- **Financial Date:** the command's own `transactionDate` (M8).
- **Source reference:** `owner-equity:{OwnerEquityTransactionId}`, regardless of movement type — the movement type is human-readable on the Owner Equity Transaction record itself, not on the Journal.
- **Source Fingerprint:** never supplied, for the identical reason as §26.1.
- **Evidence:** carried where the command's own `evidenceReference` is non-null; omitted otherwise.
- Implemented by [`OwnerEquityTransactionToPostingCommandTranslator`](../../../apps/api/app/Domain/Transactions/OwnerEquity/OwnerEquityTransactionToPostingCommandTranslator.php).

### 26.5 Invoice Issuing Command (M20)

| Line | Account | Direction |
| --- | --- | --- |
| 1 | Receivable Account (the Invoice's own `receivableAccountId`) | Debit |
| 2 | Revenue Account (the Invoice's own `revenueAccountId`) | Credit |

Both lines carry the Invoice's own `totalAmount` — standard accrual-basis revenue recognition at issuance. This is the *only* Invoicing event this document maps; a future Credit Note or Invoice Payment-application event (distinct from Payment Recording, §26.6) is not designed here and remains deferred (§27) alongside every other not-yet-built command.

- **Financial Date:** the `issueDate` explicitly supplied to [`InvoiceIssuingService::issue()`](../../../apps/api/app/Domain/Invoicing/InvoiceIssuingService.php) — never `created_at` or a silently-substituted "today" (§11.1).
- **Source reference:** `invoice:{InvoiceId}`.
- **Source Fingerprint:** never supplied (§6.2) — issuing an Invoice is an Actor confirming an already-composed Draft the Actor authored directly, not source data derived from an external import.
- **Evidence:** none — an Invoice Issuing Command carries no Evidence reference; the Invoice itself, not an external document, is the Business Transaction's own record.
- **Idempotency:** the resulting `JournalId` is deterministically derived from the command's own Idempotency Key (mirroring Income's `DeterministicIdempotentId` convention, §6.1), so a retry is recognized as a replay by the same (Tenant, Idempotency Key) mechanism every other Posting Command uses — no Invoice-specific idempotency mechanism exists.
- Implemented by [`InvoiceToPostingCommandTranslator`](../../../apps/api/app/Domain/Invoicing/InvoiceToPostingCommandTranslator.php), called from [`InvoiceIssuingService`](../../../apps/api/app/Domain/Invoicing/InvoiceIssuingService.php), which also takes the row lock on the Invoice required to keep a concurrent double-issue from posting two Journals for one Invoice (§17; a fault this codebase's own `InvoiceIssuingServiceIntegrationTest` reproduces and closes with a `SELECT ... FOR UPDATE` taken before any Draft/Issued check, mirroring §6.1's concurrency requirement).

### 26.6 Payment Recording Command (M21)

| Line | Account | Direction |
| --- | --- | --- |
| 1 | Deposit Account (the command's own `depositAccountId`) | Debit |
| 2 | Receivable Account (the command's own `receivableAccountId`) | Credit |

Both lines carry the Payment's own `amount` — cash received from a Customer reduces what that Customer owes. This command does not itself know, or need to know, which Invoice(s) that Payment will later be allocated to (§26.7); the two are deliberately independent.

- **Financial Date:** the command's own `paymentDate`.
- **Source reference:** `payment:{PaymentId}`.
- **Source Fingerprint:** never supplied, for the identical reason as §26.1.
- **Evidence:** none — a Payment Recording Command carries no Evidence reference in the current implementation.
- Implemented by [`PaymentToPostingCommandTranslator`](../../../apps/api/app/Domain/Payments/PaymentToPostingCommandTranslator.php), called from [`PaymentRecordingService`](../../../apps/api/app/Domain/Payments/PaymentRecordingService.php).

### 26.7 Payment Allocation — not an Accounting Command (M21)

**Payment Allocation, as actually built, is not an Accounting Command and does not resolve down to the Posting Command contract at all.** [AETS-000 §10](AETS-000.md#10-planned-document-structure) and §1 of this document (prior to v2.1.0) both anticipated "a payment-allocation command" as a future business-specific Accounting Command; this subsection resolves that anticipation for good — no such command exists, and none is needed, because allocating a Payment against an Invoice produces no ledger effect of its own:

- The Payment's own cash-received effect is already fully posted at Payment-recording time (§26.6: Debit deposit / Credit Receivable).
- The Invoice's own revenue-recognition effect is already fully posted at Issuing time (§26.5: Debit Receivable / Credit Revenue).
- Allocation only records *which portion of which Payment is matched against which Invoice* — pure sub-ledger bookkeeping for reporting purposes (an Invoice's outstanding balance, a Payment's unallocated amount, the Aging Report), never a debit or a credit to any Account.

Concretely, `AllocationService::allocate()` persists a `PaymentAllocation` record directly through `PaymentAllocationRepository` — it never constructs a `PostingCommand`, never calls `PostingCommandTransactionalExecutor`, and is never validated through the Posting Validation Pipeline (§14). It is therefore not subject to any `POST-NNN` invariant, produces no Audit Event under §22's Posting-Command-specific rule, and Accounting Core's exclusive posting authority (§16) is not implicated by it at all, because there is no posting for that authority to guard.

Allocation instead enforces its own two named invariants, at the Payments-domain layer, not as `POST-NNN`/`JRN-NNN`/`COA-NNN` invariants this document adopts:

- an Invoice's own allocations must never exceed its `total_amount`; and
- a Payment's own allocations must never exceed its own `amount`.

Both are protected against the same concurrent-read-then-write race every Posting Command's own idempotency guarantee (§6.1, §15) exists to close, using the identical technique: `AllocationService::allocate()` takes `SELECT ... FOR UPDATE` locks on the specific Payment and Invoice rows before computing either invariant's sum, so two concurrent allocations against the same Payment or Invoice cannot each read a stale sum and together exceed the limit neither alone would have. This document does not adopt these two invariants as its own — they are recorded here only so a reader of this section is not left wondering whether Payment Allocation's own correctness is unproven; it is proven, by [`AllocationServiceIntegrationTest`](../../../apps/api/tests/Feature/Domain/Payments/AllocationServiceIntegrationTest.php), under the Payments domain's own test coverage, not under a future `ATS-007` extension.

## 27. Deferred items

- **The Idempotency Key's and Source Fingerprint's exact derivation, hashing, or generation strategy** — this document states the required contract and guarantees only (§6); [AETS-004 §14](AETS-004-Journal-Posting-Model.md#14-idempotency)'s deferral, and [AETS-001](AETS-001-Accounting-Terminology.md#source-fingerprint)'s own equivalent deferral for Source Fingerprint, are not resolved here.
- **Which business contexts require a Source Fingerprint** — §6.2 states the rule (external/imported source data requiring duplicate-source detection); the calling module (a future Bank Reconciliation, Document Processing, or similar module) determines, in its own domain, whether a given command falls under that rule.
- **The fresh-Journal identifier generation strategy** — how a caller or Accounting Core obtains a collision-free `JournalId` for a not-yet-persisted Journal (§11) is not designed here.
- **Concrete Actor, Source, and Evidence object schemas** — deferred to a future Identity/Access specification and a future Audit Trail specification (AETS-010), neither yet created (§8–§10). Actor's and Source's *minimal opaque-reference contracts* are defined now (§8.1, §9.1), so a Posting Command can carry and be tested for each without waiting on either future specification; only their full schema (identity/session representation, audit-record shape, and so on) remains deferred. Evidence's reference contract remains entirely deferred alongside its schema — no minimal contract is defined for it here.
- **The Audit Event's detailed schema** — deferred to AETS-010 (§22), exactly as [AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability) already states.
- **The Outbox event's schema, delivery mechanism, dispatcher, and external delivery workers** — [ADR-0006](../../adr/0006-transactional-outbox-pattern.md)'s own deferred scope, unchanged here (§17, §22).
- **The concrete Posting Command terminal-result schema/API shape** — §19 states what it must convey; its transport, serialization, and error-response format are not designed here.
- **Business-specific Accounting Commands not yet built** — Bank Reconciliation, MyInvois, a Credit Note, and any other domain-specific command [AETS-000 §10](AETS-000.md#10-planned-document-structure) anticipated for "AETS-007, Accounting Commands," that does not yet exist in the codebase. §26 resolved this deferral for every business-specific command actually built as of v2.1.0 (Expense, Income, Transfer, Owner Equity, Invoice Issuing, Payment Recording) and clarified that Payment Allocation is not such a command at all (§26.7); a future command not on that list remains deferred as a **future subsection or extension of this same document, under this same AETS-007 number** — not a new AETS number, and not silently designed here (§1, §2.2).
- **Detailed correction-workflow mechanics** — reversal frequency limits, Replacement approval requirements, period-close interaction with posting, and which Financial Date a Reversal or Replacement Posting Command should be constructed with — deferred to AETS-006, Posting Rules (§2.2, §11.1), exactly as [AETS-004 §26](AETS-004-Journal-Posting-Model.md#26-deferred-items) already states.
- **Invoice domain detail beyond Issuing, Expense domain, Bank Reconciliation, MyInvois, tax computation, reporting, default Chart of Accounts, Account Code numbering, UI, and AI prompt design** — each explicitly out of scope (§2.2), deferred to its own future document.
- **This document's own future ATS-007** — §24 states its required coverage; the test specification document is not written here.

## 28. Changelog

- **2.1.0 (2026-09-08):** Resolved a governance gap an external audit surfaced: every business-specific Accounting Command built since v2.0.0 (Expense/Income at M6–M9, Transfer at M14, Owner Equity at M15, Invoice Issuing at M20, Payment Recording at M21) had its account mapping decided and implemented in its own translator class's docblock, but never recorded in this document — §1, §2.2, and §26 (now §27) had, since v1.1.0, explicitly named "invoicing" and "payment allocation" as commands this document would eventually claim, without ever being updated once those commands actually shipped. Adds new **§26, Business-specific Accounting Command mappings**, recording — as a stable normative reference, not a new field or validation rule — the fixed two-line mapping, Financial Date source, and Source reference convention each already-implemented command's translator produces (§26.1–§26.6), and clarifying that **Payment Allocation is not an Accounting Command at all** (§26.7): it posts no Journal, is never validated through the Posting Validation Pipeline, and its own two named invariants (never exceed an Invoice's `total_amount`; never exceed a Payment's own `amount`) are Payments-domain invariants, not `POST-NNN` ones. Renumbers the former §26 (Deferred items) to §27 and the former §27 (Changelog) to §28 to make room, updating every live internal cross-reference to the old §26 accordingly (§1, §2.2, §5, §6.1–§6.2, §8, §9, §11, §11.1, §15, §17, §22); historical changelog entries below, and other documents' own historical changelog entries citing "AETS-007 §26" as it was named at the time they were written, are left as an accurate record of the past rather than rewritten. Also updates the two live (non-changelog) code references to this document's own §26 — [`PostingCommand`](../../../apps/api/app/Domain/Accounting/Posting/PostingCommand.php) and [`ActorReference`](../../../apps/api/app/Domain/Accounting/Posting/ActorReference.php) — to cite §27, the section they actually meant (Evidence's and Actor's own future schema deferral). No `POST-NNN` invariant, pipeline step, field, or previously specified behavior changed. Classified **MINOR** per [AETS-000 §9.1](AETS-000.md#91-per-document-version): a new subsection recording already-implemented, already-tested behavior, not a change to any existing accounting outcome, invariant, or contract.
- **2.0.0 (2026-09-06):** Resolved a cross-cutting gap M7 (first Accounting Core consumer, Expense Recording) exposed: [SRS §5.1](../../product/reference/hore.my_Spesifikasi_Keperluan_Sistem_v1.0_BM.pdf) requires financial date, posting time, and source time stored separately, but neither `Journal` nor `PostingCommand` carried a financial date at all before this version — a calling module (Expense, and any future Income/Invoice/Bank consumer) had no way to make the ledger's accounting date authoritative without each reinventing its own convention, and Reporting/Period Management would have had to join every calling module's own table to determine period membership. Adds **Financial Date** as a new, always-required Posting Command field (§5, §11.1), the same field [AETS-004](AETS-004-Journal-Posting-Model.md) v2.0.0 (companion amendment, same task) adds to the Journal aggregate itself — this document does not invent a second, independent field. Financial Date is now also part of a Posting Command's logical-payload identity for idempotency purposes (§6.1): the same (Tenant, Idempotency Key) reused with a different Financial Date is a conflicting reuse, not a safe replay. Adds `POST-028` (§23, appended — no existing `POST-NNN` ID renumbered, altered, or removed), a new ATS requirement (§24), and a §26 clarification that which Financial Date a Reversal or Replacement Posting Command should carry remains a Posting Rules/Period Management policy decision deferred to AETS-006, never hard-coded to "the original's date" or "today" by this document, `Journal::reverse()`, or `Journal::createReplacement()`. Classified **MAJOR** per [AETS-000 §9.1](AETS-000.md#91-per-document-version): every existing caller that constructs a `PostingCommand` now requires an additional constructor argument with no default — a breaking change to an already-Active public contract, not an additive clarification.
- **1.3.0 (2026-09-05):** Resolved a genuine internal conflict discovered while implementing `PostingCommandAccountValidator` (M4-T8): §18's "Distinguishable failure categories" list stated an Account that "does not exist, does not belong to the Tenant, is Inactive, or is not posting-eligible" must be "each its own category, not merged," but the existing, already-committed Account repository contract (`AccountRepository::findById(TenantId, AccountId)`) deliberately returns the same "not found" result for both "does not exist" and "belongs to a different Tenant," by design, to prevent leaking cross-tenant Account existence (`COA-001`; AETS-002 invariant 11). Distinguishing the two would require either a new, tenant-unscoped lookup or weakening that guarantee — both rejected. **Tenant isolation takes precedence over category granularity for this one pair.** §12 gains a new paragraph, and §18's list is reworded, so that: an Account reference unresolvable within the command's own Tenant (whichever of the two physical causes applies) is one distinguishable rejection category; Posting validation MUST NOT perform a tenant-unscoped lookup merely to split it into two; Inactive and non-posting-eligible each remain their own, fully separate categories, unchanged. Both underlying failure modes (`POST-008`, `POST-009`) still independently cause rejection — only the externally observable category is shared, preserving `POST-T047` and `POST-T048` (ATS-007) exactly as already specified; neither test required the two to be distinguishable from each other, so `ATS-007` required no change. No `POST-NNN` invariant, and no other MUST-level requirement, changed — this corrects a self-contradiction in §18's own wording to match an already-settled, security-motivated architectural fact, classified **MINOR** per [AETS-000 §9.1](AETS-000.md#91-per-document-version).
- **1.2.0 (2026-09-05):** Resolved a specification gap discovered while determining the next Posting-domain implementation step after `IdempotencyKey`/`SourceFingerprint` (M4-T4): §5, §8, and §9 already required a Posting Command to carry an Actor and a Source, and §8/§9 already said each needed only "a reference sufficient to identify one," but no minimum contract for that reference was ever named — leaving `POST-T001`/`POST-T004`/`POST-T005` (ATS-007) unimplementable without prematurely inventing an Actor/Source schema. Adds `§8.1` (Actor reference, minimal contract) and `§9.1` (Source reference, minimal contract): each an opaque, immutable reference — deliberately not constrained to any physical representation (a string, a composite value, or otherwise), so neither this document nor the future Identity/Access specification (Actor) or AETS-010 (Source) is locked to today's implementation choice. Explicitly preserves every existing boundary: Accounting Core does not authenticate or authorize an Actor; Actor/Tenant consistency remains a Posting Validation Pipeline responsibility (§7, §14), never something the reference computes; a Source reference does not parse or classify what it points to; Source Fingerprint's own derivation remains entirely undefined (§6.2, unchanged); and Evidence's reference contract remains entirely deferred (§10) — no minimal contract is defined for it here, since no ATS-007 test in the reviewed range (`POST-T001`–`POST-T010`) required one. Updates §5's Actor/Source table rows and §2.2/§26 to cross-reference the new subsections. Mapped to no new `POST-NNN` invariant — this is an additive clarification of already-existing `POST-002`/`POST-005` requirements, not a new obligation. `ATS-007` requires no change: `POST-T001`/`T004`/`T005`/`T010` already describe the required behavior generically, with no reference to a specific type. No existing section renumbered, and no previously specified behavior altered — classified **MINOR** per [AETS-000 §9.1](AETS-000.md#91-per-document-version).
- **1.1.0 (2026-09-05):** Resolved the two governance ambiguities discovered while drafting v1.0.0 (task M4-T0.1), before this document is committed or activated:
  - **Idempotency Key vs. Source Fingerprint (§6, §15, §18, §23, §26).** Aligned to [AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 3 as corrected (v2.0.0 of that document, same task): Idempotency Key remains universal (§6.1, unchanged); Source Fingerprint is now an explicit, conditional field (§5) required only for a command originating from, or materially derived from, external/imported source data requiring duplicate-source detection (§6.2), which MUST fail safely if missing and MUST NOT be fabricated. Adds `POST-026` and `POST-027` (§23, appended — no existing `POST-NNN` ID renumbered, altered, or removed), a new ATS requirement (§24), a new failure category (§18), and a reconciliation note in the validation pipeline (§14) stating this is a well-formedness check within existing step 2, not a new pipeline step.
  - **Scope/roadmap naming (§1, §2).** Retitled from "Posting Command & Posting Pipeline Specification" to "Accounting Commands & Posting Pipeline," claiming in full the working title [AETS-000 §10](AETS-000.md#10-planned-document-structure) already anticipated for this number, while explicitly stating the Posting Command is the only Accounting Command fully specified by this version — invoicing, payment allocation, and every other business-specific command remain deferred future subsections or extensions of this same document, under this same AETS-007 number, never silently designed here. No new AETS number was created; [AETS-000](AETS-000.md) itself required no change, since its existing "Accounting Commands" working title and anticipated-concern description were already broad enough to cover this document's own, now-matching, self-description.

  No core reviewed decision (Part C of the originating task: Posting Command ≠ Journal, Posting Command ≠ Accounting Proposal, no direct AI posting authority, tenant validation precedence, the four-step Account resolution sequence, exact/non-negative Money, explicit Direction, single Currency, exact balance, one authoritative Posted Journal, atomic persistence, Audit Event/Evidence/Outbox atomicity, no network calls in the transaction, database uniqueness as final idempotency authority, zero-effect-on-failure, and no-double-post-on-concurrency) was reopened, and no Actor/Source/Evidence schema was (re)designed.

  **Activation (2026-09-05, M4-T0.2):** The Founder/CTO/Accounting Domain Reviewer review this version's changes required, per [AETS-000 §8.3](AETS-000.md#83-lifecycle), is now complete — the CTO/Technical Partner and Accounting Domain Reviewer approved the universal Idempotency Key requirement, the conditional Source Fingerprint requirement, the companion `AETS-002` v2.0.0 MAJOR classification, this document's `AETS-004` v1.1.0 clarification, this document's phased "Accounting Commands & Posting Pipeline" scope, Posting Command as the first fully-specified Accounting Command contract, and `POST-026`/`POST-027`. The Founder's M4-T0.2 task request constitutes Founder ratification of that review. Status changes from `Draft` to `Active`; this document now governs current Posting Command implementation. No content introduced by v1.1.0 changed as part of activation.
- **1.0.0 (2026-09-05):** Initial creation. Drafted per the Founder-approved task defining the Posting Command contract and Posting Validation Pipeline required before Posting Engine implementation begins. Reconciled to [AETS-004](AETS-004-Journal-Posting-Model.md)'s existing five-step Posting Validation Pipeline (§11) and [AETS-005](AETS-005-Chart-of-Accounts.md)'s existing four-step Account resolution sequence (§19) rather than inventing a competing pipeline — no contradiction with either was found, nor with [AETS-002](AETS-002-Accounting-Invariants.md) or [AETS-003](AETS-003-Money-Specification.md) (§3, §7 of this changelog's originating task). Introduces `POST-001`–`POST-025`, each mapped to an existing `JRN-NNN`/`COA-NNN`/`MON-NNN` invariant or ADR requirement where one exists, stated as a Posting Engine responsibility rather than a duplicate of the underlying accounting fact. Status remains `Draft` pending Founder/CTO/Accounting Domain Reviewer review, per [AETS-000 §8.3](AETS-000.md#83-lifecycle).
