# AETS-005: Chart of Accounts & Account Taxonomy

- Status: Active
- Version: 1.0.0
- Effective date: 2026-09-04
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-000](AETS-000.md), [AETS-001](AETS-001-Accounting-Terminology.md), [AETS-002](AETS-002-Accounting-Invariants.md), [AETS-003](AETS-003-Money-Specification.md), [AETS-004](AETS-004-Journal-Posting-Model.md); [ADR-0001](../../adr/0001-modular-monolith-architecture.md), [ADR-0004](../../adr/0004-financial-integrity-principles.md)

## 1. Purpose

This document is the normative specification for hore.my's Account entity and its Chart of Accounts taxonomy — what an Account is, what it must guarantee, and how it relates to a Journal Line, before either is implemented. It is the document [AETS-000 §10](AETS-000.md#10-planned-document-structure) and [AETS-004 §2.2](AETS-004-Journal-Posting-Model.md#22-out-of-scope) repeatedly pointed to as still-future work — Account types, structure, numbering, and ownership rules.

This document uses **MUST**, **MUST NOT**, **SHOULD**, and **MAY** with their normal RFC 2119 meaning, exactly as [AETS-003 §5](AETS-003-Money-Specification.md#5-normative-language) and [AETS-004 §1](AETS-004-Journal-Posting-Model.md#1-purpose) already establish for this series: **MUST**/**MUST NOT** are non-negotiable; **SHOULD** is a strong default that may be deviated from only with a recorded reason; **MAY** is a genuine option.

## 2. Scope

### 2.1 In scope

- The Account entity/value semantics: identifier, code, name, type, normal balance, posting eligibility, and active/inactive status.
- Parent/child Account hierarchy and its constraints.
- The distinction between System Accounts and User-Created Accounts, and the protections a System Account carries.
- Tenant ownership of Account.
- Account lifecycle, including why an Account is never hard-deleted once posted-to.
- The Account-side contract Journal Line's Account reference (AETS-004) depends on.
- A minimal, informative illustrative taxonomy relevant to a Malaysian sole proprietor or micro-enterprise — hore.my's MVP audience ([`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §9) — not a final Chart of Accounts.
- The required coverage of a future Chart of Accounts Test Specification.

### 2.2 Out of scope

- **Posting Command schema** — the concrete command shape and API contract (AETS-007, Accounting Commands, not yet created).
- **Business transaction mappings** — which Account a sale, purchase, or expense posts to (AETS-007).
- **Tax rules** — any tax-specific treatment, including country-specific tax numbering semantics on Account Code; no current project source requires one, so none is introduced here.
- **MyInvois** — e-invoice submission ([AETS-013](AETS-000.md#10-planned-document-structure), not yet created).
- **Bank reconciliation** — matching mechanics ([AETS-008](AETS-000.md#10-planned-document-structure), not yet created).
- **Reporting algorithms** — how a Trial Balance or report is computed from Account balances ([AETS-009](AETS-000.md#10-planned-document-structure), not yet created); this document only requires that any such balance be derived, never stored on Account (§13, §21).
- **Inventory, payroll, multi-entity, multi-currency** — all explicitly out of MVP scope ([`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §9).
- **A full default Chart of Accounts** — no current source document requires designing every account a new Tenant receives at setup; this document specifies the taxonomy rules a default set would have to satisfy, not the set itself (§15, §25).
- **Journal, Posting, and posting-pipeline mechanics already specified by [AETS-004](AETS-004-Journal-Posting-Model.md)** — this document does not redefine any of it; it specifies only the Account-side contract AETS-004 already deferred here.
- Implementation code, ORM/schema design, and migrations — this is a specification, not code.

## 3. Authority

This document implements [ADR-0004](../../adr/0004-financial-integrity-principles.md) (financial integrity principles — tenant isolation, append-only history) within the terminology of [AETS-001](AETS-001-Accounting-Terminology.md), the invariants of [AETS-002](AETS-002-Accounting-Invariants.md), and the Journal Line contract of [AETS-004](AETS-004-Journal-Posting-Model.md). It cannot weaken, override, or contradict any of them ([AETS-000 §3](AETS-000.md#3-authority-hierarchy)); where this document appears to conflict with one of them, the higher-precedence source governs and this document must be corrected ([AETS-000 §8.4](AETS-000.md#84-relationship-to-adrs-and-conflict-handling)). No such conflict was found while drafting this document.

[ADR-0001](../../adr/0001-modular-monolith-architecture.md) governs Accounting Core's exclusive authority over the Chart of Accounts, the same authority it holds over posting. This document does not reopen or restate the Money contract ([AETS-003](AETS-003-Money-Specification.md)) or the Journal/Posting model ([AETS-004](AETS-004-Journal-Posting-Model.md)) — it specifies only what either already deferred to "AETS-005."

## 4. Dependencies

- [AETS-000](AETS-000.md) — governance, versioning, and the accounting philosophy this document designs to.
- [AETS-001](AETS-001-Accounting-Terminology.md) — canonical terminology, including the existing Account term this document elaborates, not redefines (§5).
- [AETS-002](AETS-002-Accounting-Invariants.md) — the 14 financial integrity invariants this document's `COA-NNN` invariants (§21) are additional to and must not contradict, in particular invariant 5 (ledger-derived truth) and invariant 11 (tenant isolation).
- [AETS-003](AETS-003-Money-Specification.md) — the Money contract; referenced only to confirm Account itself never holds a Money value (§13, §21).
- [AETS-004](AETS-004-Journal-Posting-Model.md) — the Journal Line contract (`JRN-010`, `JRN-017`) this document's Account side satisfies (§19).
- [ADR-0001](../../adr/0001-modular-monolith-architecture.md), [ADR-0004](../../adr/0004-financial-integrity-principles.md) — the source decisions this document implements for Account specifically.

## 5. Definitions

Every term this document uses that [AETS-001](AETS-001-Accounting-Terminology.md) already defines — Account, Balance, Credit, Debit, Journal Line, Ledger, Tenant, and others — carries exactly its AETS-001 meaning, per [AETS-001 §4](AETS-001-Accounting-Terminology.md#4-normative-terminology-rules) rule 6. It is not redefined here. This section adds only the terms AETS-001 explicitly deferred to this document.

- **Account Code** — the canonical, human-auditable, tenant-scoped-unique label a Tenant's bookkeeping uses to refer to an Account (§8); distinct from the Account's stable opaque identifier (§7).
- **Account Type** — exactly one of the five canonical double-entry categories an Account belongs to: Asset, Liability, Equity, Revenue, Expense (§10).
- **Normal Balance** — the canonical Debit or Credit side an Account Type is expected to carry, derived deterministically from Account Type, never independently set (§11).
- **Posting Eligibility** — an explicit per-Account state, posting-eligible or non-posting, governing whether a Journal Line may reference that Account (§13).
- **Account Status** — an explicit per-Account lifecycle state, Active or Inactive, governing whether new postings may reference that Account (§14).
- **System Account** — an Account created by deterministic, hore.my-owned tenant-setup logic rather than directly by a user, carrying additional protections (§15).
- **User-Created Account** — an Account created through the ordinary, Tenant-scoped account-creation path available to an authorized Actor (§16).
- **Account Hierarchy** — the optional parent/child relationship between Accounts within one Tenant (§12).

## 6. Account Model

- An Account is reference/master data within a Tenant's Chart of Accounts — a named classification Journal Lines post to (AETS-001) — not a transactional aggregate. It has no lifecycle event stream of its own comparable to Journal's Draft/Posted transition (AETS-004 §9); it is created, optionally edited within the bounds this document allows, and optionally deactivated.
- An Account MUST belong to exactly one Tenant (§17).
- An Account MUST have a stable opaque identifier (§7).
- An Account MUST have a canonical Account Code (§8).
- An Account MUST have a human-readable Name (§9).
- An Account MUST have exactly one Account Type (§10).
- An Account MUST have exactly one Normal Balance, canonically derived from its Account Type (§11).
- An Account MUST have an explicit posting-eligibility state (§13).
- An Account MUST have an explicit active/inactive lifecycle state (§14).
- An Account MUST NOT contain a monetary balance as mutable authoritative state (§21, `COA-012`) — any Account balance a caller observes MUST be derived from posted Journals (AETS-002 invariant 5), exactly as Ledger-derived truth already requires for every other reported figure.
- An Account MAY reference at most one parent Account, forming an optional hierarchy (§12).

## 7. Account Identifier

- Every Account MUST have a stable, opaque identifier, assigned at creation and immutable for the Account's lifetime — never reused, never reassigned to a different Account, mirroring the identifier discipline [AETS-004 §6](AETS-004-Journal-Posting-Model.md#6-journal-aggregate) already establishes for Journal.
- A Journal Line references an Account by this identifier, and only by this identifier (§19) — never by Account Code or Name, both of which carry different mutability rules (§8, §9).
- This document treats the identifier's concrete representation (surrogate key, UUID, or otherwise) as an implementation detail; only its stability and opacity are normative.

## 8. Account Code

- An Account Code MUST be unique within its Tenant (`COA-003`) — two different Tenants MAY independently use the same code; uniqueness is never global.
- An Account Code MUST be deterministic and human-auditable: a short, canonical, business-facing label a bookkeeper can read and recognize — not a random token and not the Account's own opaque identifier (§7). Exposing a raw database identifier (auto-increment integer, UUID, or similar) directly as an Account Code is prohibited (`COA-020`, §22).
- This document does not lock a complex numbering scheme (specific character set, digit count, or range-to-Account-Type convention) — none is required by any current project source, and inventing one here would be unrequested policy. The exact grammar is deferred (§25); only tenant-scoped uniqueness and the "not a raw identifier" rule are normative now.
- No country-specific tax numbering semantics (for example, an SST/GST-derived code range) are introduced — none is currently required by any project source (§2.2), and Account Code remains tax-policy-agnostic until one explicitly is.
- **Immutability.** A System Account's (§15) Account Code MUST NOT change once assigned — deterministic Accounting Core logic and tenant-setup seeding MAY depend on referencing it reliably. A User-Created Account's (§16) Account Code MAY change, provided it continues to satisfy tenant-scoped uniqueness at all times; the workflow and audit mechanics of such a change are deferred (§25) — this document requires only that changing a Code never rewrites the historical meaning of a Journal Line already posted against that Account (§18, §22), since a Journal Line references the Account by its stable identifier (§7), never by Code.

## 9. Account Name

- An Account MUST have a non-empty, human-readable Name (`COA-017`).
- Name uniqueness within a Tenant is not required — this document does not lock that constraint, since Account Code (§8) is already the canonical unique reference; distinct Names are a usability concern, not an integrity one.
- A Name MAY be changed at any time, for either a System or a User-Created Account, subject to remaining non-empty — renaming an Account (for example, localizing a label) is a forward-only relabel and MUST NOT be presented as altering any already-posted Journal Line's historical meaning (§18).

## 10. Account Types

An Account MUST have exactly one of these five canonical Account Types (`COA-004`) — the minimum categories double-entry accounting requires:

| Account Type | Represents |
| --- | --- |
| Asset | What the business owns or is owed. |
| Liability | What the business owes. |
| Equity | The owner's residual claim on the business. |
| Revenue | Income earned by the business. |
| Expense | Cost incurred by the business. |

No sub-types are introduced by this document (for example, no "Current Asset" vs. "Fixed Asset" distinction) — none is required for the MVP accounting model this series currently targets, and inventing one here would be unrequested taxonomy design. A future document MAY introduce sub-typing without contradicting this one, provided every sub-type still resolves to exactly one of these five (§26).

Account Type is assigned at creation and MUST NOT change thereafter — retyping an Account after it has been used would silently reinterpret every Journal Line already posted against it, contradicting Ledger-derived truth (AETS-002 invariant 5) and append-only history (AETS-002 invariant 4) alike.

## 11. Normal Balance

Normal Balance is not an independently chosen field — it is the canonical value derived deterministically from Account Type (`COA-005`):

| Account Type | Normal Balance |
| --- | --- |
| Asset | Debit |
| Expense | Debit |
| Liability | Credit |
| Equity | Credit |
| Revenue | Credit |

- Every Account MUST have exactly one Normal Balance, and it MUST always be the value this table assigns to that Account's Type — an Account MUST NOT be constructible with a Normal Balance inconsistent with its Type.
- **Normal Balance does not substitute for Journal Line Direction.** A Journal Line still explicitly carries its own Debit or Credit Direction (AETS-004 §8) at posting time, regardless of the Account it references. Normal Balance is a classificatory property of the Account itself — never consulted to infer, default, or validate away a Journal Line's own explicit Direction. (Whether, and how, a future reporting or validation feature might use Normal Balance for presentation is out of scope, §2.2.)

## 12. Account Hierarchy

- An Account MAY reference at most one parent Account — hierarchy is optional and MUST NOT be required for every Account (`COA-006`, `COA-007`).
- A parent Account and its child Account MUST belong to the same Tenant (`COA-007`) — a hierarchy relationship MUST NOT cross a Tenant boundary.
- Cycles MUST be prohibited: an Account MUST NOT be its own ancestor, directly or transitively, through the parent/child relationship (`COA-006`).
- **Posting eligibility of parent/group Accounts.** This document does not derive posting eligibility automatically from whether an Account has children — posting eligibility is its own explicit, independent state (§13). It SHOULD, however, be set to non-posting for any Account used purely as a hierarchy grouping node: posting directly to a "header" Account whose balance is also an aggregate of its children's postings undermines the hierarchy's own reporting value. This is a strong default (SHOULD), not an absolute requirement, since this document does not mandate hierarchy usage at all.
- Whether a parent and its children should share the same Account Type is not settled here — a reasonable convention, but not required by any current source; noted as an open, non-normative observation, not a locked invariant (§25).

## 13. Posting Eligibility

- A Journal Line MUST reference a valid, posting-eligible Account (`COA-008`; restates and extends `JRN-010`, AETS-004 §7).
- An Inactive Account (§14) MUST NOT accept a new Journal Line, even if its posting-eligibility state is otherwise posting-eligible (`COA-009`).
- A non-posting/group Account (§12) MUST NOT accept a Journal Line, regardless of its active/inactive state (`COA-010`).
- **Historical postings are never retroactively affected.** A Journal Line already posted against an Account remains valid and unchanged after that Account is later made Inactive (`COA-011`) — posting-eligibility and active/inactive state govern only new postings going forward; neither state ever invalidates, revalidates, or otherwise touches history (AETS-002 invariant 4).

## 14. Account Status

- Every Account MUST have an explicit lifecycle state: Active or Inactive (`COA-019`) — never left undefined.
- An Account defaults to Active at creation.
- An Account MAY transition Active → Inactive (deactivation) and MAY transition Inactive → Active (reactivation) — this document does not restrict reactivation, since nothing in the task or a current source forbids it and forbidding it would be unrequested policy.
- Inactive is not deletion: an Inactive Account retains its identifier, Code, Type, Normal Balance, and full posting history unchanged — it simply cannot receive a new posting (§13) until reactivated.
- A change of Account Status is itself a material action; its actor/traceability treatment follows the same general pattern [AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability) already establishes for Journal, though this document does not redesign that mechanism (§25).

## 15. System Accounts

- A System Account is an Account created by deterministic, hore.my-owned tenant-setup logic — not directly by a user through the ordinary account-creation path (§16).
- A System Account MAY be seeded/created automatically during deterministic tenant setup.
- A System Account's Code, Type, and Normal Balance MUST NOT be altered by any user-facing operation (`COA-013`) — deterministic Accounting Core logic MAY depend on referencing a System Account reliably by these properties.
- A System Account MUST NOT be silently deleted (`COA-014`) — not merely "not deleted once posted-to" (§18 covers that generally for every Account), but never deleted at all through the ordinary application surface, whether or not it has ever been posted to, since its structural presence MAY itself be a precondition Accounting Core relies on.
- A System Account MUST NOT be reassigned across Tenants (`COA-016`) — it belongs, immutably, to the Tenant it was created for (§17), exactly like every other Account, stated here explicitly because a System Account's structural role makes a cross-tenant reassignment attempt a materially more damaging failure mode than for an ordinary Account.
- This document does not design a full default Chart of Accounts — no current project source requires one yet (§2.2, §25). It specifies only the protections a System Account carries once one exists.

## 16. User-Created Accounts

- A User-Created Account is an Account created through the ordinary, Tenant-scoped account-creation path available to an authorized Actor, distinct from System Account seeding (§15).
- A User-Created Account MUST comply with the canonical Account Type and Normal Balance rules (§10, §11) — a user MUST NOT introduce a sixth Account Type or an inconsistent Type/Normal-Balance pairing.
- A User-Created Account MUST NOT override protected System Account semantics (§15) — it MUST NOT be created with a Code that collides with an existing Account's Code in the same Tenant (`COA-003`), including a System Account's Code, and it MUST NOT retype, re-code, or otherwise mutate a System Account.
- A User-Created Account MUST use a Tenant-scoped unique Account Code (§8, `COA-003`).

## 17. Tenant Ownership

- An Account MUST belong to exactly one Tenant (`COA-001`; AETS-002 invariant 11; AETS-001 Tenant term), immutable once set — for both System and User-Created Accounts alike (§15, §16).
- Application, query, database, and storage controls MUST all prevent cross-tenant access to an Account, consistent with [ADR-0004](../../adr/0004-financial-integrity-principles.md); this document does not design the specific enforcement mechanism.
- An Account Hierarchy relationship (§12) MUST NOT cross a Tenant boundary.
- A Journal Line's referenced Account MUST belong to the same Tenant as the Journal it belongs to (`COA-015`) — the Account-side mirror of [AETS-004](AETS-004-Journal-Posting-Model.md)'s own `JRN-001`/`JRN-017` tenant-ownership requirements.

## 18. Account Lifecycle

- An Account is created — either as a System Account via deterministic tenant setup (§15), or as a User-Created Account via the ordinary account-creation path (§16) — in the Active state (§14).
- It MAY transition between Active and Inactive any number of times (§14); it is never hard-deleted while any posted Journal Line references it (`COA-014`), and a System Account is never hard-deleted at all (§15).
- If correction of an Account's own metadata is required (for example, a corrected Name, or, for a User-Created Account, a corrected Code within uniqueness bounds), that change MUST be forward-only: historical Journal references remain intact — a Journal Line always resolves to the same Account by stable identifier (§7) regardless of any later metadata edit — and the change MUST NOT rewrite or reinterpret the historical accounting effect any already-posted Journal Line recorded.
- Account Type and Normal Balance are the one exception this document does not treat as correctable metadata: both are immutable once assigned (§10, §11), because unlike a Name or Code relabel, changing either would change what a historical posting against that Account actually meant.

## 19. Journal Line Integration

This document does not modify [AETS-004](AETS-004-Journal-Posting-Model.md)'s Journal Line contract. Restated for compatibility, a Journal Line remains exactly:

- an Account identifier (§7 of this document; `JRN-010`);
- Money (AETS-003); and
- Direction (AETS-004 §8).

No Account balance is added to this shape, and none is added to Account itself (§6, §21 `COA-012`). This document specifies only what a Journal Line's Account-identifier reference must resolve to before a Posting Command may accept it (extending [AETS-004 §11](AETS-004-Journal-Posting-Model.md#11-posting-validation-pipeline)'s pipeline, not replacing it):

1. The identifier resolves to an existing Account.
2. That Account belongs to the same Tenant as the Journal (§17, `COA-015`).
3. That Account is currently Active (§14, `COA-009`).
4. That Account is currently posting-eligible (§13, `COA-008`, `COA-010`).

## 20. Validation Rules

- **Account construction** (System or User-Created) MUST validate: Tenant is present (§17); Account Code is canonical and Tenant-unique (§8); Name is non-empty (§9); Account Type is exactly one of the five canonical types (§10); Normal Balance is the single canonical value that Type derives (§11), never independently supplied inconsistently; and, if a parent is specified, that the parent belongs to the same Tenant and that the resulting hierarchy contains no cycle (§12).
- **Journal Line posting**, from the Account side, MUST validate the four checks in §19 before a Posting Command may accept the Line — in addition to, not instead of, every check [AETS-004 §11](AETS-004-Journal-Posting-Model.md#11-posting-validation-pipeline) already requires.
- **Account metadata edits** (Name always; Code for a User-Created Account) MUST validate that any resulting state still satisfies every rule in this section — in particular, tenant-scoped Code uniqueness (§8) is re-validated on every Code change, not only at creation.

## 21. Invariants

Each invariant below is Chart-of-Accounts-specific, additional to [AETS-002](AETS-002-Accounting-Invariants.md)'s 14 invariants and [AETS-004](AETS-004-Journal-Posting-Model.md)'s `JRN-NNN` invariants, and testable.

| ID | Invariant |
| --- | --- |
| COA-001 | **Tenant ownership.** Every Account MUST belong to exactly one Tenant, immutable once set. |
| COA-002 | **Stable identifier.** Every Account MUST have a stable, opaque identifier, assigned at creation and immutable for its lifetime. |
| COA-003 | **Tenant-scoped code uniqueness.** An Account Code MUST be unique within its Tenant. |
| COA-004 | **Exactly one Account Type.** Every Account MUST have exactly one of the five canonical Account Types, immutable once assigned. |
| COA-005 | **Canonical Normal Balance.** Every Account's Normal Balance MUST be the canonical value derived from its Account Type; MUST NOT be independently inconsistent. |
| COA-006 | **No hierarchy cycles.** An Account MUST NOT be its own ancestor, directly or transitively. |
| COA-007 | **Same-tenant parent/child.** A parent Account and its child Account MUST belong to the same Tenant. |
| COA-008 | **Posting only to posting-eligible accounts.** A Journal Line MUST reference an Account whose posting-eligibility state is posting-eligible. |
| COA-009 | **Inactive account rejects new posting.** A Journal Line MUST NOT be accepted against an Account currently Inactive. |
| COA-010 | **Non-posting/group account rejects posting.** A Journal Line MUST NOT be accepted against a non-posting Account, regardless of active/inactive state. |
| COA-011 | **Posted history survives deactivation.** A Journal Line already posted against an Account MUST remain valid and unchanged after that Account is later made Inactive. |
| COA-012 | **No authoritative mutable balance on Account.** An Account MUST NOT carry a monetary balance as mutable authoritative state; any presented balance MUST be derived from posted Journals. |
| COA-013 | **System account protection.** A System Account's Code, Type, and Normal Balance MUST NOT be altered by any user-facing operation. |
| COA-014 | **No hard delete after posted reference.** An Account MUST NOT be hard-deleted once any posted Journal Line references it; a System Account MUST NOT be hard-deleted at any time. |
| COA-015 | **Journal Line/Journal tenant match.** The Tenant of a Journal Line's referenced Account MUST match the Tenant of the Journal it belongs to. |
| COA-016 | **System account tenant immutability.** A System Account MUST NOT be reassigned to a different Tenant than the one it was created for. |
| COA-017 | **Account name presence.** Every Account MUST have a non-empty, human-readable Name. |
| COA-018 | **Explicit posting-eligibility state.** Every Account MUST have an explicit posting-eligibility state; it MUST NOT be left undefined. |
| COA-019 | **Explicit active/inactive state.** Every Account MUST have an explicit lifecycle state; it MUST NOT be left undefined. |
| COA-020 | **Account Code is not a raw identifier.** An Account Code MUST NOT be, or be derived from, a raw database identifier exposed directly as the code. |

## 22. Prohibited Operations

The following are explicitly forbidden, without exception, anywhere an Account is used:

- Storing a monetary balance as mutable, authoritative state on an Account.
- Hard-deleting an Account referenced by any posted Journal Line.
- Hard-deleting a System Account, at any time, referenced or not.
- Reassigning any Account — System or User-Created — to a different Tenant.
- Posting a Journal Line to an Inactive Account.
- Posting a Journal Line to a non-posting/group Account.
- Creating a parent/child relationship that forms a hierarchy cycle.
- Creating a parent/child relationship across two different Tenants.
- Altering a System Account's Code, Type, or Normal Balance through any user-facing operation.
- Exposing a raw database identifier as an Account Code.
- Changing an Account's Type or Normal Balance after creation, for any Account.
- Presenting an Account metadata edit (Name, or a User-Created Account's Code) as rewriting the historical accounting effect of any already-posted Journal Line.

## 23. Examples (Informative)

**These examples are illustrative only and are not normative.** They form a minimal starting set relevant to a Malaysian sole proprietor or micro-enterprise — hore.my's MVP audience — not a final or complete Chart of Accounts, and the codes shown are illustrative placeholders, not a locked numbering scheme (§8, §2.2).

| Illustrative code | Name | Type | Normal Balance |
| --- | --- | --- | --- |
| `1000` | Cash / Bank | Asset | Debit |
| `1100` | Accounts Receivable | Asset | Debit |
| `2000` | Accounts Payable | Liability | Credit |
| `3000` | Owner Capital | Equity | Credit |
| `4000` | Sales Revenue | Revenue | Credit |
| `5000` | General Expense | Expense | Debit |

A cash sale posted against this illustrative set (mirroring [AETS-004 §24](AETS-004-Journal-Posting-Model.md#24-examples-informative)'s own income-entry example) would Debit `1000` (Cash / Bank) and Credit `4000` (Sales Revenue) — both posting-eligible, both belonging to the same Tenant, and Debit's magnitude exactly equal to Credit's, as AETS-004 already requires independent of anything this document adds.

## 24. ATS Requirements

This section states what a future Chart of Accounts Test Specification (numbered to match this document, per this series' convention) MUST prove; it does not write that test specification.

A Chart of Accounts ATS MUST include:

- **Account construction tests** — proving every field in §6 is required and validated as specified (§20).
- **Type/Normal-Balance validation tests** — proving an Account cannot be constructed with other than one of the five canonical Types (`COA-004`), or with a Normal Balance inconsistent with its Type (`COA-005`).
- **Code uniqueness tests** — proving Account Code uniqueness is enforced within a Tenant and not across Tenants (`COA-003`).
- **Tenant isolation tests** — proving cross-tenant Account access, cross-tenant hierarchy linkage, and cross-tenant Journal Line reference are all rejected (`COA-001`, `COA-007`, `COA-015`, `COA-016`).
- **Hierarchy cycle detection tests** — proving a direct or transitive self-ancestry attempt is rejected (`COA-006`), ideally including a property-based sweep across generated hierarchy shapes (§18.2-equivalent, mirroring [ATS-004 §18](tests/ATS-004-Journal-Posting-Test-Specification.md#18-property-tests)'s pattern for this series).
- **Posting eligibility tests** — proving a Journal Line is accepted only against a posting-eligible Account (`COA-008`, `COA-010`).
- **Active/inactive behavior tests** — proving an Inactive Account rejects new postings (`COA-009`) while a Journal Line already posted against it remains valid afterward (`COA-011`).
- **Protected system account tests** — proving a System Account's Code, Type, and Normal Balance resist any user-facing edit attempt, and that it resists deletion and cross-tenant reassignment (`COA-013`, `COA-014`, `COA-016`).
- **Historical-reference preservation tests** — proving an Account metadata edit (Name, or a User-Created Account's Code) never alters the observable effect of a Journal Line already posted against it.
- **Journal tenant/Account tenant mismatch tests** — proving a Posting Command referencing an Account of a different Tenant than the Journal itself is rejected before any persistent effect (`COA-015`), consistent with [ATS-004](tests/ATS-004-Journal-Posting-Test-Specification.md)'s own tenant-isolation tests for the Journal side.
- **No mutable authoritative account balance tests** — proving no code path stores or exposes an Account's balance as anything other than a value derived, on demand, from posted Journals (`COA-012`).

## 25. Deferred Items

- **A full default Chart of Accounts** — the specific set of Accounts a new Tenant receives at setup (§2.2, §15); no current source document requires designing it now.
- **Account Code grammar/numbering convention** — exact character set, length, and any range-to-Account-Type mapping (§8); only tenant-scoped uniqueness and the "not a raw identifier" rule are locked.
- **Posting Command schema, specific business transaction mappings** — deferred to AETS-007 (Accounting Commands).
- **Tax rules, country-specific tax numbering, MyInvois** — deferred; none currently required by any project source (§2.2; AETS-013 for MyInvois specifically).
- **Bank reconciliation, reporting/Trial Balance algorithms** — deferred to AETS-008 and AETS-009 respectively.
- **Inventory, payroll, multi-entity, multi-currency** — out of MVP scope entirely ([`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §9).
- **Account Code change workflow and audit mechanics** for a User-Created Account (§8, §14) — this document requires the change be forward-only and re-validated for uniqueness; the concrete Actor-confirmation and audit-event mechanism is deferred, consistent with how [AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability) defers the general Audit Event schema.
- **Whether hierarchy parent/child must share the same Account Type** — noted as an open, non-normative observation (§12), not resolved here.
- **The Chart of Accounts ATS itself** — §24 states its required coverage; the test specification document is not written here.

## 26. Change Governance

This document follows [AETS-000](AETS-000.md)'s governance rules in full — it does not restate them. In particular: lifecycle (`Draft` → `Active` → `Superseded`/`Deprecated`, [AETS-000 §8.3](AETS-000.md#83-lifecycle)), review requirements (CTO/Technical Partner and Accounting Domain Reviewer for any material change; Founder review only where scope, cost, risk, or user experience changes, [AETS-000 §8.2](AETS-000.md#82-ownership-and-review)), and versioning (`MAJOR.MINOR.PATCH` with a changelog note for any `Active`-document change, [AETS-000 §9.1](AETS-000.md#91-per-document-version)) all apply unchanged. This document is `Active` (§ header) and governs current implementation.

A change to any `COA-NNN` invariant, or to any MUST-level requirement in §6–§20, is a MAJOR change under that rule. A later document that adds detail without altering a guarantee stated here (for example, a future default Chart of Accounts content list, or an Account Code numbering convention) does not itself require a MAJOR change to this document, provided it does not weaken anything this document requires (§2.2, §25).

## Changelog

- **1.0.0 (2026-09-04):** Initial creation. Reviewed and marked `Active`. No `COA-NNN` invariant, MUST-level requirement, or any other content changed.
