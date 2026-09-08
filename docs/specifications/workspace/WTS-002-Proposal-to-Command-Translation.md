# WTS-002: Proposal-to-Command Translation

- Status: Active
- Version: 1.0.0
- Effective date: 2026-09-09
- Owner: Workspace and Task (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [WTS-000](WTS-000.md); [WTS-001](WTS-001-Task-Proposal-State-Model.md); [ADR-0004](../../adr/0004-financial-integrity-principles.md); [ADR-0005](../../adr/0005-ai-provider-abstraction.md); [ADR-0009](../../adr/0009-workspace-task-module-boundary.md); [AETS-003](../accounting/AETS-003-Money-Specification.md); [AETS-007](../accounting/AETS-007-Posting-Command.md); [AETS-010](../accounting/AETS-010-Audit-Trail-Evidence-Linkage.md); [AETS-014](../accounting/AETS-014-Period-Management.md)

## 1. Purpose

This document defines the deterministic translation from an approved Workspace Proposal to the existing business-specific Accounting Command that represents the same confirmed intent. It resolves [WTS-000 §9](WTS-000.md#9-planned-document-structure)'s WTS-002 concern and makes [WTS-001](WTS-001-Task-Proposal-State-Model.md) `TSK-003` concrete for every Proposal Command type currently implemented.

The translation is a boundary adapter, not an alternative posting engine. It does not calculate a ledger result, select an Account, infer missing data, relax validation, or write any Accounting Core table. Its sole responsibility is to preserve an approved Proposal's meaning while constructing and submitting the same Command manual entry already uses.

## 2. Scope

### 2.1 In scope

- Translation of the five Command types currently admitted by Workspace/Task: Expense, Income, Transfer, Capital Contribution, and Owner Drawing.
- The exact field and Account-role mapping for each type.
- Tenant, approving Actor, Money, financial date, description, Evidence Reference, identity, and idempotency propagation.
- Validation parity with direct manual entry.
- Success, rejection, retry, crash-recovery, and closed-Period behavior at the translation boundary.
- The rules a future Proposal producer must preserve without gaining posting authority.

### 2.2 Out of scope

- Producing or editing a Proposal, including AI/OCR interpretation, confidence policy, and `NeedsInformation`/`Superseded` flows. These remain owned by future WTS documents identified in [WTS-000 §9](WTS-000.md#9-planned-document-structure).
- Document ingestion, storage, hashing, retention, and source-fingerprint policy. A Proposal may carry the existing nullable Evidence Reference only.
- Adding Invoice Issuing, Payment Recording, Payment Allocation, Bank Reconciliation, MyInvois, Credit Note, or any other new Workspace Command type. Their domains may already have independent commands; that fact alone does not authorize their addition to Workspace/Task.
- Changing any business-specific Accounting Command, Recording Service, Posting Command, Journal mapping, failure category, or Accounting Core invariant.
- UI design.

## 3. Authority and ownership

This document is subordinate to the authority hierarchy in [WTS-000 §3](WTS-000.md#3-authority-hierarchy). Where it describes Money, Account eligibility, Posting, Journal, Audit Event, Evidence linkage, or Period closure, the corresponding AETS document remains authoritative.

Workspace/Task owns the Proposal and the act of translating an approved Proposal. The destination Transactions service owns its business-specific Command validation and record. Accounting Core exclusively owns Posting Command validation and Journal persistence. No ownership transfers across this call chain.

```text
Workspace Proposal
        ↓ deterministic translation
Existing business-specific Accounting Command
        ↓ existing Recording Service
Existing Posting Command translator
        ↓
Deterministic Accounting Core
        ↓
Posted Journal or explicit rejection
```

## 4. Preconditions

Translation may begin only when all of the following are true:

1. The Task belongs to the acting Tenant.
2. The Task has exactly one current Proposal.
3. A human Actor has explicitly approved the Proposal.
4. The Task has reached `Executing` through the transitions allowed by WTS-001.
5. The current Proposal is loaded under the same Tenant as the Task.

A confidence score, including a future high AI confidence score, never replaces condition 3. A Proposal producer is not the approving Actor unless that same human separately performs the authenticated approval action.

## 5. Common field propagation

Every translation preserves or derives the following fields exactly:

| Destination field | Source or derivation | Rule |
| --- | --- | --- |
| Tenant ID | Task/approved request Tenant | Must be identical across Task, Proposal, Command, business record, and resulting Journal. |
| Actor | Authenticated human who approves or resumes execution | The Proposal producer is not substituted for the approving Actor. |
| Amount | `Proposal.amount` | The same immutable Money value is passed; no float conversion, rounding, sign change, or recalculation is permitted. |
| Transaction/financial date | `Proposal.transactionDate` | The exact date is passed and is re-validated against the current closed-Period watermark during command execution. |
| Description | `Proposal.description` | Passed unchanged. |
| Evidence Reference | `Proposal.evidenceReference` | Passed unchanged when present; remains nullable. |
| Command Idempotency Key | Canonical Proposal ID | Stable across approval retries and crash recovery for the same Proposal. |
| Journal ID | Deterministically derived from Tenant ID, Command Idempotency Key, and the `journal` purpose | The same Proposal under the same Tenant always targets the same Journal identity. |
| Business-record ID | Deterministically derived from Tenant ID, Command Idempotency Key, and the type-specific purpose | Stable per Proposal and Command type. |

The deterministic identities above provide retry stability; they do not replace Accounting Core's database uniqueness constraints or logical-equivalence checks.

## 6. Command-type mappings

`primaryAccountId` and `secondaryAccountId` are Workspace-side neutral transport names. They acquire a financial role only through the Proposal's Command type, as defined below.

### 6.1 Expense

| Proposal field | `RecordExpenseCommand` field |
| --- | --- |
| `primaryAccountId` | Expense Account ID |
| `secondaryAccountId` | Payment Account ID |

The destination Expense service and its existing translator remain responsible for enforcing the Account types and producing the ledger mapping specified by [AETS-007 §26](../accounting/AETS-007-Posting-Command.md#26-business-specific-accounting-command-mappings-m6m21): debit Expense, credit Payment Account.

### 6.2 Income

| Proposal field | `RecordIncomeCommand` field |
| --- | --- |
| `primaryAccountId` | Income Account ID |
| `secondaryAccountId` | Deposit Account ID |

The destination Income service enforces the Account types and produces the existing ledger mapping: debit Deposit Account, credit Income.

### 6.3 Transfer

| Proposal field | `RecordTransferCommand` field |
| --- | --- |
| `primaryAccountId` | Source Account ID |
| `secondaryAccountId` | Destination Account ID |

The destination Transfer service enforces that both Accounts are permitted Asset Accounts and are not the same Account. Its existing mapping debits the Destination Account and credits the Source Account.

### 6.4 Capital Contribution

| Proposal field | `RecordOwnerEquityTransactionCommand` field |
| --- | --- |
| Command movement type | Contribution |
| `primaryAccountId` | Cash Account ID |
| `secondaryAccountId` | Equity Account ID |

The `RecordOwnerEquityTransactionCommand` constructor accepts Equity Account before Cash Account; the Workspace-neutral fields are therefore deliberately supplied in reverse constructor order. Its existing mapping debits Cash and credits Equity.

### 6.5 Owner Drawing

| Proposal field | `RecordOwnerEquityTransactionCommand` field |
| --- | --- |
| Command movement type | Drawing |
| `primaryAccountId` | Cash Account ID |
| `secondaryAccountId` | Equity Account ID |

The same deliberate constructor-order rule as §6.4 applies. The existing mapping debits Equity and credits Cash.

## 7. Validation parity

Translation must call the same public Recording Service and construct the same business-specific Command as direct manual entry. It must not duplicate, bypass, pre-approve, or weaken the destination service's validation.

Consequently:

- Account existence, Tenant ownership, active state, posting eligibility, and type are decided by the existing destination and Accounting Core validators.
- Money validity is governed by AETS-003 and the existing Command contract.
- balanced Journal construction and atomic persistence are governed by AETS-007.
- the current closed-Period watermark is checked at execution time under AETS-014, even if the date was open when the Proposal was created.
- an invalid Proposal-originated command must fail wherever the equivalent manual command fails.
- a valid Proposal-originated command must produce the same financial effect as the equivalent manual command.

Workspace-side request validation may reject malformed input earlier for user experience, but passing that validation is never evidence that the Accounting Command will be accepted.

## 8. Idempotency and recovery

One Proposal has one stable Command identity. Approval retry, recovery from `Approved`, and resume from `Executing` reuse that identity.

If Accounting Core already accepted the logically identical Command, the existing Posting pipeline returns its replay result and no second business record, Journal, Journal Line, Audit Event, or Evidence Link is created. A materially conflicting reuse of the same identity is rejected under AETS-007; Workspace must never reinterpret that rejection as success.

The browser disabling a button is not an idempotency mechanism. Database uniqueness and the transactional controls specified by WTS-001 and AETS-007 remain authoritative under concurrent requests.

## 9. Result and failure semantics

On success, Workspace stores the exact Journal ID returned by the destination Recording Service and transitions the Task from `Executing` to `Completed`.

On a specifically designed, user-safe business rejection from an existing destination validator, Workspace stores the rejection message and transitions the Task from `Executing` to `Failed`. This does not authorize Workspace to invent or generalize Accounting Core failure categories.

Unexpected programming defects, infrastructure failures, database availability failures, and other unclassified exceptions must propagate for operational handling. They must not be converted into an ordinary user correction or a false `Completed` state.

Neither success nor failure permits Workspace to write directly to `journals`, `journal_lines`, `accounts`, `audit_events`, or a destination module's owned business table.

## 10. Evidence and external-source boundary

The current five Workspace Command types are human-produced Proposals and may carry a nullable Evidence Reference. Passing that reference into the existing Command preserves evidence linkage; it does not establish that an external document has been ingested, hashed, deduplicated, retained, or authorized for AI use.

No Source Fingerprint is fabricated by this translation. When Document Processing or AI Orchestration introduces a real external source, its specification must decide the source identity and duplicate-source contract consistently with AETS-007 before such a Proposal may execute. WTS-004 must preserve that requirement.

## 11. Invariants (`PTC-NNN`)

- **PTC-001:** Translation occurs only for the current Proposal of a same-Tenant Task that has received explicit human approval and reached `Executing` through WTS-001.
- **PTC-002:** Translation calls the exact existing public Recording Service used by equivalent manual entry; no Proposal-only posting path exists.
- **PTC-003:** Tenant ID and the approving human Actor propagate to the destination Command unchanged; the Proposal producer never silently replaces the approving Actor.
- **PTC-004:** Money, transaction date, description, and nullable Evidence Reference propagate unchanged, without float conversion, rounding, inference, or normalization at translation time.
- **PTC-005:** `primaryAccountId` and `secondaryAccountId` map to the destination Account roles exactly as specified in §6 for each Command type.
- **PTC-006:** A Proposal ID produces one stable Command Idempotency Key, Journal ID, and type-specific business-record ID under its Tenant, reused by every retry or recovery of that Proposal.
- **PTC-007:** Destination services and Accounting Core perform their complete existing validation at execution time; Workspace validation never replaces it.
- **PTC-008:** A successful execution records the exact resulting Journal ID and reaches `Completed`; an allowed business rejection records its real reason and reaches `Failed`; an unexpected failure is never disguised as either.
- **PTC-009:** Translation has no direct write path to Accounting Core or another module's owned persistence tables.
- **PTC-010:** Adding a sixth Workspace Command type requires an approved WTS-002 revision that records its existing destination Command, exact field/Account mapping, validation parity, identity purpose, failure contract, and tests; an existing command elsewhere in the monolith does not add itself implicitly.
- **PTC-011:** No Source Fingerprint is fabricated. A future external-source Proposal must satisfy the source identity and deduplication contract approved for Document Processing/AI Orchestration and AETS-007.
- **PTC-012:** AI confidence, provider output, scheduled work, or system automation can never substitute for the authenticated human approval precondition.

## 12. Required test coverage

A corresponding WT-002 test specification must trace every `PTC-NNN` invariant to concrete tests. At minimum it must include:

- all five type mappings and their exact debit/credit financial effects through real Recording Services;
- propagation of Tenant, approving Actor, Money, financial date, description, and Evidence Reference;
- validation parity for invalid Account type, cross-Tenant Account, inactive/non-posting-eligible Account, same-Account Transfer, malformed Money, and closed Period;
- replay and conflicting-reuse behavior using the Proposal-derived identity;
- architecture proof that Workspace has no direct persistence dependency on Accounting Core-owned tables;
- success, designed business rejection, and unexpected-failure behavior; and
- confirmation that unsupported Command types cannot enter the translation boundary.

Tests proving persistence, concurrency, constraints, or atomicity must use real PostgreSQL where an in-memory substitute cannot prove the behavior.

## 13. Deferred decisions

- Which additional existing business command, if any, should next become a Workspace Proposal Command type.
- Proposal editing and supersession semantics.
- Document identity, Source Fingerprint derivation, evidence ownership validation, and duplicate-document handling.
- AI-produced Proposal fields and producer authorization.
- User-facing presentation of command-specific validation failures.

Each remains deferred to its owning specification or an approved revision. This document creates no implicit commitment to implement any of them.

## Changelog

- **1.0.0 (2026-09-09):** Initial Active version. Records the already-implemented five-type deterministic translation boundary, resolves WTS-001 `TSK-003` field and validation parity concretely, and explicitly defers every unsupported type and external-source decision rather than inventing new MVP behavior.
