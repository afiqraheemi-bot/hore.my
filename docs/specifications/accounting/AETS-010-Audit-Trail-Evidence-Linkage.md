# AETS-010: Audit Trail & Evidence Linkage

- Status: Active
- Version: 1.0.0
- Effective date: 2026-09-06
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-000](AETS-000.md), [AETS-001](AETS-001-Accounting-Terminology.md), [AETS-002](AETS-002-Accounting-Invariants.md), [AETS-004](AETS-004-Journal-Posting-Model.md), [AETS-007](AETS-007-Posting-Command.md); [ADR-0001](../../adr/0001-modular-monolith-architecture.md), [ADR-0004](../../adr/0004-financial-integrity-principles.md), [ADR-0006](../../adr/0006-transactional-outbox-pattern.md)

## 1. Purpose

This document is the normative specification for the Audit Event schema and the Evidence reference/linkage contract that [AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability) and [AETS-007 §10](AETS-007-Posting-Command.md#10-evidence-references), [§22](AETS-007-Posting-Command.md#22-auditability-requirements) both repeatedly deferred to "a future Audit Trail specification (AETS-010, not yet created)." It exists to close the specific gap the M4 Posting Pipeline and M5 Journal Correction milestones each explicitly left open: neither produces an Audit Event, and neither commits Evidence linkage atomically with a posted Journal, so [AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariants 2 (Atomicity) and 12 (Complete auditability) have not yet been fully satisfiable in the running system.

This document uses **MUST**, **MUST NOT**, **SHOULD**, and **MAY** with their normal RFC 2119 meaning, exactly as [AETS-004 §1](AETS-004-Journal-Posting-Model.md#1-purpose) already establishes for this series.

## 2. Scope

### 2.1 In scope

- The Audit Event schema: the concrete fields [AETS-001](AETS-001-Accounting-Terminology.md#audit-event) already requires "at minimum" (Actor, Tenant, source, time, applicable policy/model version), and what domain action each event records.
- The minimal Evidence reference contract — an opaque, immutable reference sufficient to trace a Journal back to supporting Evidence, mirroring the contract [AETS-007 §9.1](AETS-007-Posting-Command.md#9-source) already establishes for Source.
- The Journal-to-Evidence linkage record: what a linkage row carries, and the atomicity requirement it commits under.
- How Audit Event production and Evidence linkage fit into the M4 Posting Pipeline's and M5 Journal Correction's existing atomic transaction boundaries, without redesigning either.

### 2.2 Out of scope

- **Evidence's own retention and storage mechanics** — the physical file, its storage backend, encryption, hash verification algorithm, and retention/deletion lifecycle. [AETS-001](AETS-001-Accounting-Terminology.md#evidence) states these are "defined elsewhere" — owned by a future Document Processing specification, not yet created, since Evidence's original file is produced and stored by that module, not by Accounting Core.
- **Evidence's own tenant-scoped aggregate and persisted identity.** No module in the current system produces a real, persisted Evidence record — Document Processing does not yet exist. This document defines the reference contract a Posting Command carries and how a linkage row is stored, but does not invent a full Evidence aggregate merely to validate tenant ownership of a reference nothing yet produces (§9, §14).
- **Outbox Event schema, delivery mechanism, and dispatcher** — these remain [ADR-0006](../../adr/0006-transactional-outbox-pattern.md)'s own explicitly deferred scope. No workflow in the current system requires an asynchronous or external effect from a posting (no MyInvois, no notifications, no document-processing callback exist yet); building an Outbox Event table with no producer or consumer would be speculative, not implementation of an active requirement. [ADR-0006](../../adr/0006-transactional-outbox-pattern.md)'s own rollout rule — "the outbox foundation must exist before any workflow relies on external/asynchronous side effects" — is honored by deferring it to whichever future milestone introduces the first such workflow.
- **Audit Trail UI, query API, or reporting** — how an Audit Event is displayed, searched, or exported belongs to a future Reporting/Compliance specification (AETS-009) and UI work, not this document.
- **Policy/model version semantics** — what a "policy or model version" concretely names, and when one is "applicable," is an AI Orchestration concern (AETS-011, not yet created). This document reserves a field for it and requires it be recorded when applicable, but does not define what populates it.
- Implementation code, ORM/schema design, and migrations — this is a specification, not code.

## 3. Authority

This document implements [ADR-0004](../../adr/0004-financial-integrity-principles.md) (financial integrity principles) and [ADR-0006](../../adr/0006-transactional-outbox-pattern.md) (transactional outbox, for the parts of its atomicity requirement this document is responsible for), within the terminology of [AETS-001](AETS-001-Accounting-Terminology.md) and the invariants of [AETS-002](AETS-002-Accounting-Invariants.md). It cannot weaken, override, or contradict any of them, nor [AETS-004](AETS-004-Journal-Posting-Model.md) or [AETS-007](AETS-007-Posting-Command.md), both of which are `Active` and already state normative requirements this document must satisfy, not relax ([AETS-000 §3](AETS-000.md#3-authority-hierarchy)).

Where [AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability) and [AETS-007 §10](AETS-007-Posting-Command.md#10-evidence-references)/[§22](AETS-007-Posting-Command.md#22-auditability-requirements) already state a requirement, this document does not restate it as a new decision — it completes the schema those documents left open.

## 4. Dependencies

- [AETS-000](AETS-000.md) — governance, versioning, and the AI/accounting philosophy this document designs to.
- [AETS-001](AETS-001-Accounting-Terminology.md) — canonical terminology for Audit Event, Evidence, Actor, Source, Tenant; this document reuses, and does not redefine, any of them (§5).
- [AETS-002](AETS-002-Accounting-Invariants.md) — invariants 2 (Atomicity) and 12 (Complete auditability), which this document's `AUD-NNN` invariants (§12) are additional to and must not contradict.
- [AETS-004](AETS-004-Journal-Posting-Model.md) §19, §22, §26 — the Journal-level traceability requirements and deferral this document resolves.
- [AETS-007](AETS-007-Posting-Command.md) §9.1, §10, §17, §22, §26 — the Posting Command-level requirements and deferral this document resolves, including the already-established minimal opaque-reference contract pattern (§9.1) this document extends to Evidence.
- [ADR-0004](../../adr/0004-financial-integrity-principles.md), [ADR-0006](../../adr/0006-transactional-outbox-pattern.md) — the source decisions this document implements the Audit Event/Evidence-linkage portion of.

## 5. Definitions

Every term this document uses that [AETS-001](AETS-001-Accounting-Terminology.md) already defines — Actor, Audit Event, Evidence, Journal, Source, Tenant, and others — carries exactly its AETS-001 meaning, per [AETS-001 §4](AETS-001-Accounting-Terminology.md#4-normative-terminology-rules) rule 6. It is not redefined here.

- **Evidence Reference** — the minimal, opaque reference contract this document defines (§8): an identifier sufficient to trace a Journal back to Evidence, without this document defining Evidence's own schema (§2.2).
- **Evidence Linkage** — the persisted record associating one Journal with one Evidence Reference, committed atomically with that Journal (§9).
- **Audit Action** — the specific domain action an Audit Event records (§7) — for example, that a Journal was newly Posted, Reversed, or Replaced.

## 6. Actor and Source, reused unchanged

Every Audit Event's Actor and Source fields are exactly the `ActorReference` and `SourceReference` contracts [AETS-007 §8](AETS-007-Posting-Command.md#8-actor) and [§9.1](AETS-007-Posting-Command.md#9-source) already establish and the M4 implementation already provides. This document does not introduce a second Actor or Source representation, and does not change either existing contract in any way — an Audit Event simply carries the same Actor and Source a Posting Command (or Journal Correction command) already carried.

## 7. Audit Event schema

Every Audit Event MUST carry, at minimum ([AETS-001](AETS-001-Accounting-Terminology.md#audit-event); [AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 12):

| Field | Requirement |
| --- | --- |
| Tenant | The Tenant the recorded action belongs to. Immutable once recorded (§10). |
| Actor | The `ActorReference` responsible for the action (§6). |
| Source | The `SourceReference` that gave rise to the action (§6). |
| Audit Action | Which domain action this event records (below) — the minimum set this document defines is `JournalPosted`, `JournalReversed`, `JournalReplaced`. Additional actions MAY be added by a later document or a MINOR revision of this one as new material actions are introduced (§13), without removing or redefining an existing one. |
| Subject | The stable identifier of the Journal the action concerns — every Audit Action this document currently defines concerns exactly one Journal. |
| Time | The UTC timestamp the action was recorded, per [`ENGINEERING_BLUEPRINT.md`](../../../ENGINEERING_BLUEPRINT.md) §5.3's boundary-timestamp rule. |
| Policy/model version | Present only where applicable (§2.2) — `null` where no AI or automated policy was involved in producing the action, which is every action this document's current producers (§10) can generate, since no AI Orchestration module exists yet. This field's presence in the schema is not itself a claim that it is currently populated. |

An Audit Event carries no other field. It does not carry a free-text description, a diff of what changed, or a copy of the Journal's own data — the Journal itself, loaded by its Subject identifier, already carries that; an Audit Event's job is to prove *that* an identified Actor, for an identified Source, caused an identified action, on an identified Tenant, at an identified time — not to duplicate the ledger.

**Append-only, no exception.** An Audit Event, once recorded, MUST NOT be updated or deleted through the application, mirroring the append-only rule [AETS-004 §15](AETS-004-Journal-Posting-Model.md#15-append-only-rules) already establishes for Journals ([`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §15, "Audit log append-only").

## 8. Evidence Reference — minimal contract

Pending a future Document Processing specification's full Evidence schema (§2.2), a Journal's Evidence linkage is, at minimum, an **Evidence Reference**: an opaque, immutable reference, mirroring exactly the minimal contract [AETS-007 §9.1](AETS-007-Posting-Command.md#9-source) already establishes for Source:

1. It MUST be opaque to Accounting Core — Accounting Core MUST NOT parse, interpret, or derive meaning from its internal structure.
2. It MUST NOT be fabricated. An Evidence Reference MUST resolve to real, previously retained Evidence — never a value an AI or other proposal-producing process generates merely to satisfy §9's requirement ([AETS-007 §10](AETS-007-Posting-Command.md#10-evidence-references); [AETS-001](AETS-001-Accounting-Terminology.md#evidence)).
3. It MUST be immutable and comparable only for exact equality — no partial or fuzzy matching.

This is the minimum contract this document requires until a future Document Processing specification defines Evidence's full schema and this document, or a successor, is revised to validate a reference against it (§14).

## 9. Evidence Linkage

Where a Posting Command carries one or more Evidence References (per [AETS-007 §10](AETS-007-Posting-Command.md#10-evidence-references)), each MUST be persisted as a linkage record associating it with the Journal being posted, and that persistence MUST commit or roll back together with the Journal, in the same transaction ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 2; [AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability); [AETS-007 §17](AETS-007-Posting-Command.md#17-atomic-transaction-requirements)).

A linkage record carries: the Tenant, the Journal it links (by stable identifier), the Evidence Reference itself, and the time it was linked. It carries nothing about the Evidence's own content, since this document does not define Evidence's schema (§2.2).

**Where no Evidence Reference is supplied.** Per [AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability) and [AETS-007 §10](AETS-007-Posting-Command.md#10-evidence-references), a Journal with no independent evidence of its own is not required to fabricate one, and produces zero linkage records. A Journal Correction (Reversal or Replacement, [AETS-004 §16–§17](AETS-004-Journal-Posting-Model.md#16-reversal)) is the paradigm case §19 already names: its evidentiary basis is the original Journal it references, transitively traceable through the correction chain — a Journal Correction command does not itself carry Evidence References, and this document does not add that field to `ReverseJournalCommand` or `ReplaceJournalCommand`.

## 10. Producers

This document's Audit Event and Evidence Linkage requirements apply to every Posting Command execution and every Journal Correction execution the current system can produce:

- A successful, newly-posted (not replayed) `PostingCommand` execution ([AETS-007](AETS-007-Posting-Command.md), M4) MUST produce exactly one Audit Event, Audit Action `JournalPosted`, and, where the command carried any Evidence References, exactly one linkage record per reference.
- A successful, newly-posted (not replayed) Journal Reversal execution (M5) MUST produce exactly one Audit Event, Audit Action `JournalReversed`.
- A successful, newly-posted (not replayed) Journal Replacement execution (M5) MUST produce exactly one Audit Event, Audit Action `JournalReplaced`.
- An idempotent replay of any of the above MUST produce zero additional Audit Events and zero additional linkage records — replay performs zero writes ([AETS-004 §14](AETS-004-Journal-Posting-Model.md#14-idempotency)), and an Audit Event is not an exception to that rule; the original Audit Event, produced on first submission, remains the complete and correct record.

This document does not define a fourth producer. A future milestone that introduces a new kind of material action (for example, an invoice being issued) MUST define its own Audit Action and producer requirement, either by a MINOR revision of this document or a later one that depends on it (§13) — it MUST NOT reuse `JournalPosted`, `JournalReversed`, or `JournalReplaced` for an unrelated action.

## 11. Atomicity and tenant isolation

**Atomicity.** Audit Event production, and Evidence Linkage where applicable, MUST be part of the exact same PostgreSQL transaction that posts the Journal ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 2; [AETS-004 §13](AETS-004-Journal-Posting-Model.md#13-atomicity), [JRN-012](AETS-004-Journal-Posting-Model.md#22-invariants); [AETS-007 §17](AETS-007-Posting-Command.md#17-atomic-transaction-requirements)) — never a separate, later write, and never best-effort. If the Audit Event write, or a required Evidence Linkage write, fails, the entire transaction MUST roll back, including the Journal and its Lines — there MUST be no Posted Journal with a missing Audit Event, and no Journal claiming Evidence linkage that was not actually recorded.

**Tenant isolation.** Every Audit Event and every Evidence Linkage record carries the same Tenant as the Journal it concerns, immutably. Application, query, and database controls MUST prevent cross-tenant access to either, consistent with [AETS-004 §18](AETS-004-Journal-Posting-Model.md#18-tenant-isolation) and [AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 11.

**Evidence tenant-ownership — a tracked, honest gap, not a violation.** [AETS-007 §14](AETS-007-Posting-Command.md#14-validation-pipeline) step 1 requires "any Evidence's Tenant" to be validated against the command's own Tenant, on the same footing as an Account reference. This document does not yet resolve that check: no module in the current system persists a tenant-scoped Evidence aggregate to validate against (§2.2), and no current or near-term producer (§10) supplies a non-empty Evidence Reference list — the check's precondition never currently arises. Building a full Evidence aggregate solely to validate a reference nothing yet produces would be speculative implementation, not resolution of an active requirement. This gap is deferred, not silently dropped: a future Document Processing specification, when it defines Evidence's persisted schema, MUST also resolve this tenant-ownership check, and no Posting Command MAY treat a supplied Evidence Reference as validated in the interim beyond the format-level checks §8 already requires.

## 12. Invariants

Each invariant below is Audit Trail/Evidence-Linkage-specific, additional to [AETS-002](AETS-002-Accounting-Invariants.md)'s 14 invariants and to [AETS-004](AETS-004-Journal-Posting-Model.md)'s `JRN-NNN`/[AETS-007](AETS-007-Posting-Command.md)'s `POST-NNN` invariants, which each already state the underlying requirement this document completes the schema for.

| ID | Invariant |
| --- | --- |
| AUD-001 | **Audit Event on every material action.** Every successful, newly-posted Posting Command or Journal Correction execution MUST produce exactly one Audit Event (§10). |
| AUD-002 | **No Audit Event on replay.** An idempotent replay MUST produce zero additional Audit Events (§10). |
| AUD-003 | **Minimum captured fields.** Every Audit Event MUST carry Tenant, Actor, Source, Audit Action, Subject, and Time (§7). |
| AUD-004 | **Atomic commit.** An Audit Event MUST commit or roll back together with the Journal it concerns, in the same transaction (§11). |
| AUD-005 | **Append-only.** An Audit Event MUST NOT be updated or deleted through the application once recorded (§7). |
| AUD-006 | **Evidence Reference opacity.** An Evidence Reference MUST NOT be parsed or interpreted by Accounting Core (§8). |
| AUD-007 | **No fabricated Evidence.** An Evidence Reference MUST NOT be generated by AI or any proposal-producing process merely to satisfy a linkage requirement (§8). |
| AUD-008 | **Evidence Linkage atomicity.** Where a Posting Command carries Evidence References, every linkage record MUST commit or roll back together with the Journal, in the same transaction (§9, §11). |
| AUD-009 | **No fabricated linkage on absence.** A Journal with no independent Evidence MUST NOT have a linkage record fabricated for it (§9). |
| AUD-010 | **Correction commands carry no independent Evidence.** A Journal Reversal or Replacement command does not itself carry Evidence References (§9). |

## 13. Governance for future Audit Actions

A future AETS document, or a MINOR revision of this one, MAY add a new Audit Action for a new kind of material action this document does not yet cover (for example, an invoice or payment event), provided it: reuses this document's existing Audit Event schema unchanged (§7), does not redefine `JournalPosted`, `JournalReversed`, or `JournalReplaced` (§10), and states its own producer requirement analogous to §10. Removing or changing the meaning of an existing Audit Action is a MAJOR change (§14) and requires the review [AETS-000 §8.2](AETS-000.md#82-ownership-and-review) requires.

## 14. Examples (Informative)

The following illustrate this document's normative requirements; they are not additional rules.

- A user uploads a receipt, confirms an AI-proposed expense, and Accounting Core posts the resulting Journal: one Audit Event (`JournalPosted`, Actor = the confirming user, Source = the confirming Accounting Command), and one Evidence Linkage record (the receipt's Evidence Reference), both committed atomically with the Journal.
- A user reverses that same Journal: one Audit Event (`JournalReversed`, Actor = the user performing the reversal, Source = the reversal command), zero Evidence Linkage records (the Reversal's evidentiary basis is the original Journal, per §9).
- The same reversal command is retried with the same Idempotency Key (a network retry, for example): zero additional Audit Events, zero additional linkage records — the original result is returned unchanged.

## 15. ATS Requirements

A future `ATS-010` test specification MUST prove, at minimum, for each producer (§10):

- Audit Event atomicity — fault-injection proving that a failure in the Audit Event write rolls back the entire transaction, including the Journal and its Lines, leaving no partially-posted state (`AUD-004`).
- Evidence Linkage atomicity — the same fault-injection proof for a linkage write, using a Posting Command that carries at least one Evidence Reference (`AUD-008`).
- No Audit Event or linkage record on replay (`AUD-002`).
- Every Audit Event's minimum field set is present and correct for each of the three currently-defined Audit Actions (`AUD-003`).
- Tenant isolation — an Audit Event or linkage record for one Tenant is never observable through a query scoped to a different Tenant.
- Append-only — no code path in the current system exposes an update or delete operation for an Audit Event.

## 16. Deferred Items

- **Evidence's full schema, retention, and storage mechanics** — a future Document Processing specification (§2.2).
- **Evidence tenant-ownership validation** — deferred until Evidence has a persisted, tenant-scoped identity to validate against (§11); tracked, not silently dropped.
- **Outbox Event schema, dispatcher, and delivery workers** — [ADR-0006](../../adr/0006-transactional-outbox-pattern.md)'s own deferred scope, to be resolved by whichever future milestone introduces the first asynchronous/external effect from a posting (§2.2).
- **Audit Trail query API, UI, and export** — a future Reporting/Compliance specification (AETS-009, not yet created) and UI work.
- **Policy/model version semantics** — a future AI Orchestration specification (AETS-011, not yet created); this document only reserves the field (§7).
- **Additional Audit Actions** for material actions outside Journal posting/correction (invoicing, payment allocation, bank reconciliation, and so on) — each future milestone that introduces one defines its own producer requirement under this document's governance (§13).

## Changelog

- **1.0.0 (2026-09-06):** Initial version. Resolves the Audit Event schema and Evidence Reference/Linkage contract [AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability)/[§26](AETS-004-Journal-Posting-Model.md#26-deferred-items) and [AETS-007 §10](AETS-007-Posting-Command.md#10-evidence-references)/[§22](AETS-007-Posting-Command.md#22-auditability-requirements)/[§26](AETS-007-Posting-Command.md#26-deferred-items) each deferred by name. Outbox Event schema deliberately excluded from this version's scope (§2.2) since no current workflow requires it; Evidence's full tenant-scoped aggregate deliberately excluded (§2.2, §11) since no current producer supplies a non-empty reference — both tracked as open deferred items (§16), not silent omissions.
