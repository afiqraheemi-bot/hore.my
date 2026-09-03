# AETS-004: Journal & Posting Model

- Status: Active
- Version: 1.0.0
- Effective date: 2026-09-04
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-000](AETS-000.md), [AETS-001](AETS-001-Accounting-Terminology.md), [AETS-002](AETS-002-Accounting-Invariants.md), [AETS-003](AETS-003-Money-Specification.md); [ADR-0001](../../adr/0001-modular-monolith-architecture.md), [ADR-0004](../../adr/0004-financial-integrity-principles.md), [ADR-0005](../../adr/0005-ai-provider-abstraction.md), [ADR-0006](../../adr/0006-transactional-outbox-pattern.md), [ADR-0007](../../adr/0007-money-representation-strategy.md)

## 1. Purpose

This document is the normative specification for hore.my's Journal, Journal Line, and posting lifecycle — the double-entry structure and posting discipline that make Accounting Core "the exclusive authority for ledger posting" ([ADR-0004](../../adr/0004-financial-integrity-principles.md)). It is the document [AETS-000 §10](AETS-000.md#10-planned-document-structure) and [AETS-001](AETS-001-Accounting-Terminology.md) repeatedly pointed to as still-future work — Journal Line schema, posting mechanics, idempotency derivation, and Accounting Command details.

This document uses **MUST**, **MUST NOT**, **SHOULD**, and **MAY** with their normal RFC 2119 meaning, exactly as [AETS-003 §5](AETS-003-Money-Specification.md#5-normative-language) already establishes for this series: **MUST**/**MUST NOT** are non-negotiable; **SHOULD** is a strong default that may be deviated from only with a recorded reason; **MAY** is a genuine option.

## 2. Scope

### 2.1 In scope

- The Journal aggregate and Journal Line, and the relationship between them.
- Debit/Credit semantics, and how they combine with the Money domain contract ([AETS-003](AETS-003-Money-Specification.md)).
- Journal lifecycle states (Draft, Posted) and the transition between them.
- The Posting Command, its validation pipeline, and balance validation.
- Atomicity, idempotency, append-only rules, and their concurrency/locking implications.
- Reversal and Replacement as the sole correction mechanisms for a Posted Journal.
- Tenant isolation, actor/source/evidence traceability, and failure semantics as they apply to Journal and posting.
- The required coverage of a future Journal & Posting Test Specification.

### 2.2 Out of scope

- **Chart of Accounts taxonomy** — Account types, structure, numbering, and ownership rules ([AETS-005](AETS-000.md#10-planned-document-structure), not yet created). This document uses only opaque Account identifiers (§7, §24).
- **Specific business transaction mappings** — which Accounts a sale, purchase, or expense posts to ([AETS-007](AETS-000.md#10-planned-document-structure), Accounting Commands, not yet created).
- **MyInvois** — e-invoice submission ([AETS-013](AETS-000.md#10-planned-document-structure), not yet created).
- **Bank reconciliation** — matching mechanics ([AETS-008](AETS-000.md#10-planned-document-structure), not yet created).
- **Reporting algorithms** — how a Trial Balance or report is computed from the Ledger ([AETS-009](AETS-000.md#10-planned-document-structure), not yet created).
- **Tax policy** — any tax-specific treatment or rounding policy ([AETS-003 §25](AETS-003-Money-Specification.md#25-deferred-items)).
- **AI classification logic** — how a proposal is produced ([AETS-011](AETS-000.md#10-planned-document-structure), not yet created); this document only states what Accounting Core requires from, and how it constrains, whatever produced a candidate command (§10).
- **Detailed correction workflow and period-close posting controls** — per [AETS-000 §10](AETS-000.md#10-planned-document-structure), the deeper mechanics of correction workflow and period-close interaction with posting are anticipated for AETS-006 (Posting Rules). This document defines the normative Reversal/Replacement contract Journal itself depends on (§16, §17); AETS-006 may add workflow detail but must not weaken it (§27).
- Implementation code, ORM/schema design, and migrations — this is a specification, not code (see the task instruction this document was produced under).

## 3. Authority

This document implements [ADR-0004](../../adr/0004-financial-integrity-principles.md) (financial integrity principles) and [ADR-0006](../../adr/0006-transactional-outbox-pattern.md) (transactional outbox), within the terminology of [AETS-001](AETS-001-Accounting-Terminology.md), the invariants of [AETS-002](AETS-002-Accounting-Invariants.md), and the Money contract of [AETS-003](AETS-003-Money-Specification.md). It cannot weaken, override, or contradict any of them ([AETS-000 §3](AETS-000.md#3-authority-hierarchy)); where this document appears to conflict with one of them, the higher-precedence source governs and this document must be corrected ([AETS-000 §8.4](AETS-000.md#84-relationship-to-adrs-and-conflict-handling)). No unresolved conflict remains: §7's minimum Journal Line count is confirmed consistent with [AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 1.

[ADR-0005](../../adr/0005-ai-provider-abstraction.md) governs this document's treatment of AI's limits (§10, §23). [ADR-0007](../../adr/0007-money-representation-strategy.md) and [AETS-003](AETS-003-Money-Specification.md) govern every Money value a Journal Line carries; this document does not restate or reopen the Money domain contract.

## 4. Dependencies

- [AETS-000](AETS-000.md) — governance, versioning, and the AI/accounting philosophy this document designs to.
- [AETS-001](AETS-001-Accounting-Terminology.md) — canonical terminology; this document reuses, and does not redefine, every term it already defines (§5).
- [AETS-002](AETS-002-Accounting-Invariants.md) — the 14 financial integrity invariants this document's `JRN-NNN` invariants (§22) are additional to and must not contradict.
- [AETS-003](AETS-003-Money-Specification.md) — the Money, Currency, and MinorUnits contract every Journal Line's amount uses unchanged; this document does not modify it.
- [ADR-0001](../../adr/0001-modular-monolith-architecture.md) — Accounting Core's exclusive posting authority within the modular monolith.
- [ADR-0004](../../adr/0004-financial-integrity-principles.md), [ADR-0006](../../adr/0006-transactional-outbox-pattern.md) — the source decisions this document implements in detail.

## 5. Definitions

Every term this document uses that [AETS-001](AETS-001-Accounting-Terminology.md) already defines — Account, Accounting Command, Actor, Audit Event, Balance, Credit, Debit, Evidence, Idempotency Key, Journal, Journal Line, Ledger, Posting, Reversal, Replacement, Source Fingerprint, Tenant, and others — carries exactly its AETS-001 meaning, per [AETS-001 §4](AETS-001-Accounting-Terminology.md#4-normative-terminology-rules) rule 6. It is not redefined here. This section adds only the terms AETS-001 explicitly deferred to this document.

- **Posting Command** — the specific Accounting Command (AETS-001) whose accepted effect is transitioning a Journal from Draft to Posted (§10).
- **Draft Journal** — a Journal in the Draft state (§9): assembled but not yet posted, with no ledger effect.
- **Direction** — the Debit/Credit classification of a Journal Line (§8); exactly one of two mutually exclusive values.
- **Correction chain** — the linked sequence Original Journal → Reversal → (optionally) Replacement that together record a correction without editing or deleting the original (§16, §17).
- **Neutralize** — to produce, for every line of an original Journal, a corresponding line with the same Account and the same exact Money magnitude but the opposite Direction, so the combined net effect per Account is exactly zero (§16).

## 6. Journal Aggregate

- A Journal is the aggregate root of one accounting effect (AETS-001). It owns exactly its own Journal Lines (§7); it references, by stable identifier, but does not own, the Account(s), Evidence, Actor, and Audit Event a Posting Command associates with it.
- A Journal MUST belong to exactly one Tenant (§18).
- A Journal MUST contain at least two Journal Lines (§7, `JRN-002`) — a Journal is never a single-sided entry.
- A Journal MUST have a stable, opaque identifier, assigned at creation and never reused or changed for the Journal's lifetime.
- A Journal MUST record its current lifecycle state (§9) and only a permitted transition (Draft → Posted) may ever change it.
- A Journal MUST retain actor/source/evidence traceability where applicable (§19).
- A Posted Journal MUST be immutable (§15).
- A Journal that is a Reversal or a Replacement additionally carries the correction-chain reference(s) §16/§17 require; an ordinary Journal carries none.
- This document does not decide whether a Draft Journal is ever separately persisted before posting, or is assembled transiently from a Posting Command and validated in one step (§9) — both are valid implementations of this aggregate; what a rejected posting attempt must never do is produce a Posted Journal or any partial ledger effect.

## 7. Journal Line

- A Journal Line MUST belong to exactly one Journal; it has no independent identity or meaning outside it (AETS-001).
- A Journal Line MUST reference exactly one Account identifier (`JRN-010`). This document treats an Account identifier as opaque — its structure and taxonomy are Chart of Accounts design (§2.2, AETS-005).
- A Journal Line MUST contain exact Money (AETS-003) — never a raw number, never a formatted string (`JRN-009`).
- A Journal Line MUST represent either Debit or Credit direction, and MUST NOT represent both directions simultaneously, nor neither (§8, `JRN-008`).
- A Journal Line MUST NOT contain a binary float amount, at any point (`JRN-009`, restating `MON-001` in this context).
- A Journal Line's Money MUST be a non-negative magnitude; the accounting sign is carried entirely by Direction, never by the sign of the Money value (§8).

A Journal's minimum line count is **at least two**, per [AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 1 and [AETS-001](AETS-001-Accounting-Terminology.md)'s own Journal definition — a single-sided entry cannot balance. This is final for the current specification baseline (`JRN-002`, §22).

## 8. Debit and Credit Semantics

This section resolves, for Journal Line's purposes only, the practical question [AETS-003 §25](AETS-003-Money-Specification.md#25-deferred-items) left open: whether Money may itself be negative, or is an inherently non-negative magnitude with direction supplied by context.

- **Resolution: magnitude-with-direction.** A Journal Line's Money MUST always be a non-negative magnitude (already guaranteed by Money's current construction — AETS-003 permits no negative Money today). The accounting sign is carried entirely by a separate, explicit Direction value — Debit or Credit — never by negating the Money amount. This is the standard double-entry representation: no real ledger ever writes "a debit of −RM10.00"; it writes "a credit of RM10.00."
- Direction MUST be represented as exactly one of two mutually exclusive values (Debit, Credit) — never a signed number, never a nullable/optional field defaulting to one side.
- Balance validation (§12) treats a Debit line's Money as a positive contribution to the total-debit side and a Credit line's Money as a positive contribution to the total-credit side; it never negates a Money value to fold both directions into one signed sum, since Money's cross-currency-guarded, exact `add` (AETS-003 §11) is defined for non-negative magnitudes only.
- **What this does not resolve.** Money's own general sign-policy deferral (AETS-003 §25) — whether Money may ever be negative in some other, non-Journal-Line context (e.g. a hypothetical single signed net figure) — is untouched by this resolution and remains open. No change was made, or is needed, to `Money`, `Currency`, or `MinorUnits` (AETS-003) to support this resolution.

## 9. Journal States

A Journal has exactly two lifecycle states:

| State | Meaning | Ledger effect | Mutable |
| --- | --- | --- | --- |
| Draft | Assembled, not yet posted. | None. | Yes — lines may be added, changed, or removed. |
| Posted | Result of a successful Posting Command (§10–§12). | Authoritative (AETS-002 invariant 5). | No — append-only (§15). |

- The only permitted transition is Draft → Posted, and it happens exclusively via a successful Posting Command.
- A Posted Journal MUST NOT transition back to Draft, or to any other state.
- A Draft Journal MUST NOT be treated as authoritative for Balance, Trial Balance, or any other Projection (AETS-001, AETS-002 invariant 5) — only a Posted Journal has ledger effect.
- A rejected Posting Command MUST NOT produce a Posted Journal or any partial ledger effect; a Draft Journal it was attempted against (if one was persisted) remains in Draft, unchanged.

## 10. Posting Command

- A Posting Command is the Accounting Command (AETS-001) whose accepted effect is posting a Journal. It is the only mechanism by which a Journal may transition to Posted — no other code path may perform that transition.
- A Posting Command MUST carry: an Idempotency Key (§14), the Tenant it is scoped to, an identified Actor (§19), and the Journal Line set to post (whether freshly assembled or referencing an existing Draft Journal), together with Source and Evidence references where applicable (§19).
- A Posting Command MUST be validated by, and only accepted through, deterministic Accounting Core controls (AETS-000 §5; AETS-002 invariant 13) — never by AI, and never by any other proposal-producing module.
- An AI-originated Accounting Proposal (AETS-001) has no posting authority of its own. It MUST be explicitly confirmed by an authorized Actor, or deterministically accepted through Accounting Core's own controls, before it can become a Posting Command. Neither AI nor any automated confidence threshold MUST be able to bypass that confirmation step, or bypass any stage of the Posting Validation Pipeline (§11), regardless of the proposal's origin or confidence score.

## 11. Posting Validation Pipeline

Every Posting Command MUST be validated, in full, before any persistent effect occurs. The pipeline is, logically:

1. **Tenant ownership** (§18) — every referenced Account, Actor, Evidence, and Draft Journal (if any) belongs to the command's Tenant.
2. **Idempotency check** (§14) — whether a Journal already exists for this (Tenant, Idempotency Key) pair; if so, the remaining steps are skipped and the original result is returned.
3. **Journal-state validation** (§9) — if referencing an existing Draft Journal, it is still Draft, not already Posted or otherwise consumed.
4. **Line-level validation** (§7) — every line references exactly one Account, carries exactly one Direction, and carries exact, valid, non-float Money.
5. **Balance validation** (§12) — total Debit Money exactly equals total Credit Money.

Only once every step above succeeds does the atomic persistence step (§13) run. Implementations MAY combine or reorder these checks internally for efficiency, provided the observable guarantee — no persistent ledger effect occurs before every check has passed — holds exactly.

## 12. Balance Validation

- Total Debit Money MUST equal total Credit Money before a Journal may be posted (`JRN-007`), computed via Money's own exact addition and equality (AETS-003 §11–§12) — never native numeric comparison, never float, never a tolerance window.
- All Journal Lines within one Journal MUST share the same Currency (`JRN-011`) — Money's cross-currency guard (AETS-003 §6, `MON-006`) forbids comparing or summing Money of different Currency, and multi-currency Journals are out of MVP scope ([`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §9).
- Zero-difference is mandatory: there is no concept of an "immaterial" or rounding-tolerated imbalance. Total Debit minus total Credit MUST be exactly zero, not merely close to zero.
- An unbalanced Journal MUST NOT be posted, under any circumstance. Balance validation failure produces a typed failure (§21), not a partial or best-effort posting.

## 13. Atomicity

- A Posting Command's persistent effect — the Journal, every Journal Line, its Evidence linkage, its Audit Event, and any required outbox event — MUST commit or roll back together, as one PostgreSQL transaction (AETS-002 invariant 2; [ADR-0004](../../adr/0004-financial-integrity-principles.md)).
- No network call, queue publication, or other external/asynchronous work MUST execute inside that transaction ([ADR-0006](../../adr/0006-transactional-outbox-pattern.md)); any such work is handed off via the transactional outbox after commit.
- If any required write within the transaction fails, the entire transaction MUST roll back — there MUST be no partially posted Journal, no orphaned line, and no orphaned audit or outbox record observable afterward.

## 14. Idempotency

This section states the required conceptual contract only; it does not invent a final database schema.

- Every Posting Command carries an Idempotency Key (AETS-001), scoped to its Tenant.
- Before creating any new Journal, Accounting Core MUST be able to determine whether a Journal already exists for the (Tenant, Idempotency Key) pair of the incoming command.
- If one exists: the command MUST NOT create a duplicate Journal. It MUST instead return or identify the existing Journal's result, deterministically, so a caller cannot tell "first success" from "safe replay" apart from an explicit indicator that this document does not design.
- If none exists: the command proceeds through the full validation pipeline (§11) and, on success, the (Tenant, Idempotency Key) → Journal association MUST be recorded durably as part of the same atomic transaction (§13) that posts the Journal — so a crash between checking and committing cannot itself create a duplicate.
- The same logical Posting Command MUST NOT create duplicate Journals under any sequence of retries, including concurrent retries (§20).
- Repeated safe retry MUST return or identify the existing result where appropriate — never a second economic effect, and never a hard failure solely because the command was already, successfully, processed once.

## 15. Append-Only Rules

- A Posted Journal, and every one of its Journal Lines, MUST be append-only: neither MUST ever be updated or deleted through the application, by any operation, for any reason (AETS-002 invariant 4).
- This includes every field — amount, Account, Direction, Currency, Evidence linkage, and correction-chain references — once a Journal is Posted.
- The only sanctioned way to change a Posted Journal's recorded financial effect is a Reversal (§16) and, where a corrected effect is also needed, a Replacement (§17). Neither is an edit: both are new Journals.
- Database-level enforcement mechanism (permissions, triggers, or an equivalent control) is an implementation detail this document does not design; the requirement itself — no update, no delete, ever, through the application — is normative and testable independent of that mechanism (§25).

## 16. Reversal

- A Reversal MUST be a new Journal, with its own stable identifier and its own lifecycle (§9) — it is posted through the ordinary Posting Command pipeline (§10–§12) like any other Journal, and must itself balance.
- A Reversal MUST reference the original Journal it reverses.
- A Reversal MUST neutralize the original Journal's financial effect exactly (`JRN-018`): for every Journal Line in the original, the Reversal contains a corresponding line with the same Account and the same exact Money magnitude, but the opposite Direction. The combined net effect of the original and its Reversal, per Account, is therefore always exactly zero.
- The original Journal MUST remain unchanged — a Reversal is additive, never a mutation of the record it corrects (§15).

Whether, or how many times, a given Journal may be reversed, and any workflow restriction preventing a redundant reversal, is deferred to the Posting Rules specification (§2.2, §26; AETS-006).

## 17. Replacement

- A Replacement MUST be a new Journal, distinct from its Reversal, recording the corrected accounting effect once the original has been neutralized.
- A Replacement MUST reference the correction chain (§5) — at minimum its Reversal, and, transitively, the original Journal being corrected — so Original → Reversal → Replacement is traceable in one unambiguous direction.
- A Replacement MUST NOT overwrite the original Journal's stored data, and MUST NOT be substitutable for the original in any query or view that requires the original's own historical record (§15).
- A Replacement is posted through the ordinary Posting Command pipeline (§10–§12) like any other Journal — it is not a structurally special posting type; only its correction-chain reference distinguishes it.

## 18. Tenant Isolation

- A Journal, and every one of its Journal Lines, MUST belong to exactly one Tenant (AETS-002 invariant 11; AETS-001 Tenant term); immutable once set.
- A Posting Command MUST fail — before any persistent effect (§11 step 1) — if any Account it references, any Evidence it links, its Actor, or (when applicable) the Draft Journal it posts, does not belong to the command's own Tenant.
- A Reversal or Replacement MUST belong to the same Tenant as the Journal(s) it references — cross-tenant correction MUST NOT be possible.
- Application, query, database, and storage controls MUST all prevent cross-tenant access to a Journal or Journal Line, consistent with [ADR-0004](../../adr/0004-financial-integrity-principles.md); this document does not design the specific enforcement mechanism (row-level security, query scoping, or otherwise).

## 19. Actor / Source / Evidence Traceability

- Every Posting Command MUST record an identified Actor (AETS-001) — the human user, operator, or authorized system process responsible for it.
- AI, or any other proposal-producing process, MUST NOT itself be recorded as the Actor accepting a Posting Command (AETS-000 §5) — it may only be recorded as the originator of the Accounting Proposal a human or deterministic process subsequently confirmed (§10).
- Every Journal MUST retain a traceable Source reference — the Accounting Command, accepted Accounting Proposal, or correction reference that gave rise to it.
- Every Journal MUST retain Evidence linkage where applicable — where the underlying Business Transaction is evidence-backed, that linkage commits atomically with the Journal (AETS-002 invariant 2). A Journal with no independent evidence of its own (for example, a pure Reversal, whose evidentiary basis is the original Journal it references) is not required to fabricate one.
- Every material action this document describes MUST produce an Audit Event capturing Actor, Tenant, source, and time, committed atomically with the domain change it records (AETS-002 invariant 12); this document does not design the Audit Event schema itself (§2.2; a future Audit Trail specification, AETS-010).

## 20. Concurrency and Locking Expectations

- Two concurrent Posting Commands carrying the same Idempotency Key for the same Tenant MUST result in exactly one Journal being created; the command that loses the race MUST detect the conflict and return the winner's result, never error destructively and never create a second Journal (§14).
- A Draft Journal posted concurrently by two different Posting Commands (for example, a double-submit) MUST NOT produce two Posted Journals from the same Draft — either idempotency-key coupling (if the retry reuses the same key) or a Journal-state check (§9, §11 step 3) MUST detect and reject the second attempt with a typed "already posted" failure.
- Because a Posted Journal is created only by an append-only insert — never by an update to a shared, mutable running balance — the classic lost-update race on an "account balance" column structurally cannot occur here: Balance is always derived from posted Journals (AETS-002 invariant 5), never stored and decremented in place.
- Two concurrent, unrelated Posting Commands (different Idempotency Keys, potentially touching the same Account) MUST both succeed independently and correctly, with no lost update and no observable partially-posted state from either (§13).
- The specific locking or isolation-level mechanism used to guarantee the above is an implementation detail this document does not mandate.

## 21. Failure Semantics

Every Posting Command MUST produce exactly one of:

1. a newly Posted Journal;
2. the original result of an idempotent replay (a success, not a failure, per §14); or
3. a typed, deterministic failure.

There MUST be no third or ambiguous outcome, and no silent partial effect. Distinct, typed failure categories MUST exist for at least:

- an unbalanced Journal (§12);
- an invalid Journal Line (missing or multiple Account references, missing or dual Direction, invalid or float Money) (§7, §8);
- a Tenant-ownership mismatch (§18);
- a Journal not in a postable state (for example, already Posted) (§9);
- a persistence/infrastructure failure (for example, a Money value's `BIGINT` bounds, per [AETS-003 §15](AETS-003-Money-Specification.md#15-persistence-mapping) and its Persistence Adapter); and
- a vendor-library exception translated per Money's own vendor isolation (AETS-003 §16), which MUST NOT escape as a vendor type here either.

Each category MUST be distinguishable by callers (e.g. by type), not merged into one generic error (AETS-002 §4, restating invariant framing already established for Money in [AETS-003 §17](AETS-003-Money-Specification.md#17-exception-and-failure-semantics)).

## 22. Invariants

Each invariant below is Journal/Posting-specific, additional to [AETS-002](AETS-002-Accounting-Invariants.md)'s 14 invariants, and testable.

| ID | Invariant |
| --- | --- |
| JRN-001 | **Tenant ownership.** Every Journal and Journal Line MUST belong to exactly one Tenant; a Journal with no Tenant MUST NOT be constructible. |
| JRN-002 | **At least two lines.** A Journal MUST contain at least two Journal Lines. Final for the current specification baseline (§7). |
| JRN-003 | **Stable opaque identifier.** Every Journal MUST have a stable, opaque identifier, assigned at creation and immutable for its lifetime. |
| JRN-004 | **Recorded lifecycle state.** Every Journal MUST record its current state (Draft or Posted); only the Draft → Posted transition is permitted. |
| JRN-005 | **Immutable posted Journal.** Once a Journal is Posted, no field of the Journal or any of its Lines MUST ever change. |
| JRN-006 | **No direct posted-Journal mutation.** The application MUST NOT expose any operation capable of directly updating or deleting a Posted Journal or its Lines; only Reversal and Replacement may change its recorded effect. |
| JRN-007 | **Exact debit/credit balance.** Total Debit Money MUST equal total Credit Money exactly, via Money's own exact comparison, before posting. |
| JRN-008 | **No dual-direction line.** A Journal Line MUST represent exactly one of Debit or Credit — never both, never neither. |
| JRN-009 | **No binary float.** No Journal Line MUST accept, store, or produce a binary floating-point amount at any point. |
| JRN-010 | **Single Account reference.** Every Journal Line MUST reference exactly one Account identifier. |
| JRN-011 | **Single Currency per Journal.** All Journal Lines within one Journal MUST share the same Currency. |
| JRN-012 | **Atomic posting.** The Journal, its Lines, evidence linkage, audit event, and any required outbox event MUST commit or roll back together, as one transaction. |
| JRN-013 | **No network call inside the posting transaction.** No network call or queue publication MUST execute inside the posting database transaction. |
| JRN-014 | **Idempotent posting.** Repeating or concurrently retrying the same logical Posting Command MUST NOT create a second Journal; it MUST return or identify the original result. |
| JRN-015 | **Duplicate-effect prevention.** Accounting Core MUST reject or safely deduplicate any Posting Command that would otherwise create a duplicate financial effect for the same logical economic event. |
| JRN-016 | **Full pre-persistence validation.** Every Journal Line, the balance check, and tenant ownership MUST be validated in full before any persistent effect of a Posting Command occurs. |
| JRN-017 | **Tenant ownership validated at posting.** A Posting Command MUST fail, before any persistent effect, if any referenced Account, Actor, Evidence, or Draft Journal does not belong to the command's Tenant. |
| JRN-018 | **Reversal neutrality.** For every line of an original Journal, its Reversal MUST contain a corresponding line with the same Account and the same exact Money magnitude and the opposite Direction, so the combined net effect per Account is exactly zero. |
| JRN-019 | **Reversal traceability.** A Reversal MUST reference the original Journal it reverses, and the original Journal MUST remain unchanged. |
| JRN-020 | **Replacement traceability.** A Replacement MUST reference the correction chain (its Reversal and, transitively, the original Journal) and MUST NOT overwrite the original Journal's stored data. |
| JRN-021 | **Actor traceability.** Every Posting Command MUST record an identified Actor; AI or any proposal-producing process MUST NOT be recorded as the Actor accepting a Posting Command. |
| JRN-022 | **Source/evidence traceability.** Every Journal MUST retain a traceable Source reference, and Evidence linkage where applicable, committed atomically with the Journal. |
| JRN-023 | **No AI posting authority.** AI and other proposal-producing processes MUST NOT execute, confirm, or otherwise bypass validation for a Posting Command. |

## 23. Prohibited Operations

The following are explicitly forbidden, without exception, anywhere a Journal or Journal Line is used:

- Updating or deleting a Posted Journal or Journal Line through the application, by any means other than Reversal and, where needed, Replacement.
- Posting an unbalanced Journal, under any circumstance.
- A Journal Line representing both Debit and Credit simultaneously, or neither.
- Float arithmetic on, or a float value assigned to, any Journal Line amount, at any layer.
- A network call or queue publication executing inside the posting database transaction.
- AI, or any proposal-producing module, directly writing, confirming, or posting a Journal, or bypassing the Posting Validation Pipeline (§11).
- Creating a second Journal for a Posting Command already successfully processed under the same Idempotency Key.
- A Reversal or Replacement silently modifying, deleting, or being substituted for the original Journal's stored data.
- Treating a Draft Journal as having ledger effect before it is Posted.
- A Journal Line referencing an Account, or a Journal referencing Evidence or an Actor, belonging to a different Tenant than the Journal itself.

## 24. Examples (Informative)

**These examples are illustrative only and are not normative.** Account identifiers are abstract placeholders (`ACCOUNT-CASH`, `ACCOUNT-INCOME`, and so on) — this document does not design final account codes or a Chart of Accounts (§2.2).

- **Simple balanced income entry.** A Journal with two lines: Debit `ACCOUNT-CASH` RM100.00, Credit `ACCOUNT-INCOME` RM100.00. Total debit (RM100.00) equals total credit (RM100.00) exactly — posts successfully.
- **Simple expense entry.** A Journal with two lines: Debit `ACCOUNT-EXPENSE` RM45.50, Credit `ACCOUNT-CASH` RM45.50. Balanced — posts successfully.
- **Rejected unbalanced entry.** A Journal with two lines: Debit `ACCOUNT-EXPENSE` RM45.50, Credit `ACCOUNT-CASH` RM45.00. Total debit ≠ total credit — the Posting Command fails balance validation (§12); no Journal is Posted, no partial effect occurs.
- **Repeated idempotent posting.** The income entry above is submitted as a Posting Command with Idempotency Key `K1`. It posts, creating Journal `J1`. The same command, same key `K1`, is submitted again (client retry after a timeout) — Accounting Core recognizes the existing (Tenant, `K1`) association and returns Journal `J1`'s result; no second Journal is created.
- **Reversal.** Journal `J1` (the income entry above) is later found to be wrong. A Reversal Journal `J2` is posted: Debit `ACCOUNT-INCOME` RM100.00, Credit `ACCOUNT-CASH` RM100.00 — the same accounts and amount as `J1`, every Direction flipped. `J2` references `J1`. Combined, `ACCOUNT-CASH` and `ACCOUNT-INCOME` each show a net effect of RM0.00 from `J1` + `J2`. `J1` itself remains unchanged.
- **Reversal + Replacement.** The correct entry was actually RM120.00, not RM100.00. After `J2` reverses `J1`, a Replacement Journal `J3` is posted: Debit `ACCOUNT-CASH` RM120.00, Credit `ACCOUNT-INCOME` RM120.00. `J3` references the correction chain (`J2`, and transitively `J1`). `J1` and `J2` remain unchanged; `J3` is the corrected, currently-effective entry.

## 25. ATS Requirements

This section states what a future Journal & Posting Test Specification (numbered to match this document, per this series' convention) MUST prove; it does not write that test specification.

A Journal & Posting ATS MUST include:

- **Invariant traceability** — every `JRN-NNN` invariant (§22) traced to at least one test, mirroring [ATS-003](tests/ATS-003-Money-Test-Specification.md)'s traceability matrix pattern.
- **Balance property tests** — proving, across a wide, generated range of exact Journal Line sets, that balanced entries post and unbalanced entries never do, with zero tolerance (`JRN-007`).
- **Atomic rollback tests** — fault-injection proving that any failure during posting leaves no partial Journal, line, evidence linkage, audit event, or outbox record (`JRN-012`).
- **Idempotency retry tests** — proving a repeated Posting Command with the same Idempotency Key returns the original result and never creates a second Journal (`JRN-014`).
- **Duplicate concurrency attempts** — concurrency tests proving two simultaneous Posting Commands with the same Idempotency Key produce exactly one Journal (`JRN-014`, `JRN-015`, §20).
- **Reversal neutrality tests** — proving a Reversal's combined effect with its original is exactly zero, per Account, for a representative and generated range of Journals (`JRN-018`).
- **Immutable posted Journal tests** — proving no application-level operation can update or delete a Posted Journal or its Lines (`JRN-005`, `JRN-006`).
- **Tenant isolation tests** — proving a Posting Command referencing a cross-tenant Account, Evidence, Actor, or Draft Journal fails before any persistent effect (`JRN-001`, `JRN-017`).
- **Failure-before-side-effect tests** — proving every rejection category in §21 occurs with zero persistent effect, not merely that an exception is thrown.
- **No-float tests** — proving no Journal Line construction or posting path accepts, stores, or produces a binary float (`JRN-009`).
- **Audit traceability tests** — proving every posted Journal's Actor, Source, and (where applicable) Evidence linkage is present and committed atomically with it (`JRN-021`, `JRN-022`).

## 26. Deferred Items

- **Chart of Accounts taxonomy** — Account structure, types, and numbering (§2.2; AETS-005).
- **Specific business transaction mappings** — which Accounts a given business transaction posts to (§2.2; AETS-007).
- **MyInvois, bank reconciliation, reporting algorithms, tax policy, and AI classification logic** — each explicitly out of scope (§2.2).
- **Detailed correction workflow mechanics** — whether/how many times a Journal may be reversed, approval requirements for a Replacement, and period-close interaction with posting (§16; AETS-006, Posting Rules, per [AETS-000 §10](AETS-000.md#10-planned-document-structure)).
- **Concrete Posting Command schema and API contract** — the actual command shape, transport, and validation error format (AETS-007, Accounting Commands).
- **Idempotency key derivation and storage schema** — this document states the conceptual contract only (§14); the concrete mechanism is a later implementation decision.
- **Period management interaction** — how a closed Accounting Period rejects ordinary posting (AETS-002 invariant 6; AETS-014, Period Management, not yet created).
- **Audit Event schema** — the detailed shape of the audit record §19 requires (AETS-010, Audit Trail, not yet created).
- **Money's general sign-policy deferral** — [AETS-003 §25](AETS-003-Money-Specification.md#25-deferred-items)'s open question is resolved by this document only for Journal Line's own usage (§8); it is not otherwise resolved.
- **The Journal & Posting ATS itself** — §25 states its required coverage; the test specification document is not written here.

## 27. Change Governance

This document follows [AETS-000](AETS-000.md)'s governance rules in full — it does not restate them. In particular: lifecycle (`Draft` → `Active` → `Superseded`/`Deprecated`, [AETS-000 §8.3](AETS-000.md#83-lifecycle)), review requirements (CTO/Technical Partner and Accounting Domain Reviewer for any material change; Founder review only where scope, cost, risk, or user experience changes, [AETS-000 §8.2](AETS-000.md#82-ownership-and-review)), and versioning (`MAJOR.MINOR.PATCH` with a changelog note for any `Active`-document change, [AETS-000 §9.1](AETS-000.md#91-per-document-version)) all apply unchanged. This document is `Active` (§ header) and governs current implementation.

A change to any `JRN-NNN` invariant, or to any MUST-level requirement in §6–§21, is a MAJOR change under that rule. A later document that adds workflow detail without altering a guarantee stated here (for example, AETS-006 detailing correction-workflow mechanics, or AETS-007 defining the concrete Posting Command schema) does not itself require a MAJOR change to this document, provided it does not weaken anything this document requires (§2.2, §26).

## Changelog

- **1.0.0 (2026-09-04):** Initial creation. Reviewed and marked `Active`. The Journal minimum-line-count requirement (`JRN-002`) is confirmed final for the current specification baseline: at least two Journal Lines, per [AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 1 — the drafting-stage note flagging this for Founder confirmation is resolved and removed. No `JRN-NNN` invariant, MUST-level requirement, or any other content changed.
