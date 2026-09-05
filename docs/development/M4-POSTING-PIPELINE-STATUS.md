# M4 — Posting Command Pipeline: Closure Status

- Status: Closed for MVP scope — remaining items are explicitly blocked, not oversights
- Date: 2026-09-06
- **Updated 2026-09-06 (M6 close):** POST-007, POST-019, POST-020, and POST-024 below are corrected from their M4-close status — [AETS-010](../specifications/accounting/AETS-010-Audit-Trail-Evidence-Linkage.md) (Audit Trail & Evidence Linkage) now exists and resolves the Audit Event and minimal Evidence Reference/Linkage gaps this document originally recorded as blocked/deferred. See §7 for the full M6 delta. Every other row is unchanged from M4 close.
- Governs: `App\Domain\Accounting\Posting`, `App\Infrastructure\Accounting\Posting`, `App\Infrastructure\Accounting\Journal`
- Authoritative specs: [AETS-007](../specifications/accounting/AETS-007-Posting-Command.md), [ATS-007](../specifications/accounting/tests/ATS-007-Posting-Pipeline-Test-Specification.md), [AETS-010](../specifications/accounting/AETS-010-Audit-Trail-Evidence-Linkage.md), [ATS-010](../specifications/accounting/tests/ATS-010-Audit-Trail-Test-Specification.md)

## Purpose

This document records, for whoever builds the next module against this Posting pipeline (M5+), exactly what M4 delivers, what it deliberately does not, and why — so a future caller does not need to re-derive this from the commit history or re-ask questions this milestone's own work already answered. This is a status record, not a specification; AETS-007/ATS-007 remain the authoritative contracts.

## 1. What M4 delivers

A first-submission Posting Command reaches durable, atomic, idempotent Posted state, and a genuinely concurrent duplicate — whether an exact retry or a materially conflicting reuse — is correctly recovered or rejected, proven against real PostgreSQL including genuine multi-process races.

**Domain layer** (`App\Domain\Accounting\Posting`): `IdempotencyKey`, `SourceFingerprint`, `ActorReference`, `SourceReference` (opaque VOs); `PostingCommand` (immutable carrier); `DraftJournalAssembler`, `PostingCommandAccountValidator`, `PostingCommandJournalStateResolver` (+ `PostingCommandJournalState`), `PostingCommandCandidateJournalResolver`, `PostingCommandExistingDraftLineValidator`, `PostingCommandJournalExecutor`, `PostingCommandLogicalEquivalence`, `PostingCommandIdempotencyResolver` (+ `PostingCommandIdempotencyDecision`), `PostingCommandTransactionalExecutor` (+ `PostingCommandExecutionResult`).

**Infrastructure layer**: `posting_idempotency_keys` and `posting_source_fingerprints` tables (each a `(TenantId, Key) -> JournalId` mapping with a composite FK back to `journals`); `PostingIdempotencyRepository`, `PostingSourceFingerprintRepository`; `JournalRepository::existsById()` (the global, boolean-only identity-collision primitive); container wiring (`AccountingServiceProvider`) so the whole pipeline resolves through `app()`.

**Proven, against real PostgreSQL, including genuine two-process concurrency** (not simulated): exactly-once posting under a genuine concurrent identical race and a genuine concurrent conflicting race (forked-process technique, `PostingCommandTransactionalExecutorTest`); full atomic rollback of Journal + Lines + idempotency mapping together on a forced mid-transaction failure; cross-tenant Journal identity collision rejected without ever leaking the owning Tenant; architecture-level absence of any network/queue/mail call or any Source-Fingerprint self-fabrication anywhere on the pipeline's call path.

## 2. POST-NNN invariant status

| ID | Invariant | Status | Evidence |
| --- | --- | --- | --- |
| POST-001 | Tenant ownership at acceptance | **Partial** | Account sub-part: done (`PostingCommandAccountValidatorTest`). Existing-Draft-Journal sub-part: done (`PostingCommandJournalStateResolverTest`, M4-T19). Actor/Evidence sub-parts: **blocked** (§4 below). |
| POST-002 | Command identity required | **Done** | `PostingCommandTest` (POST-T002–T005, constructor-level, non-nullable). |
| POST-003 | Idempotent accounting effect | **Done** | `PostingCommandTransactionalExecutorTest` (exact retry → replay, no second write). |
| POST-004 | Conflicting idempotency reuse rejected | **Done** | `PostingCommandTransactionalExecutorTest`, `PostingCommandIdempotencyResolverTest`. |
| POST-005 | Valid Actor and Source required | **Partial** | Presence: done (constructor-level). "Authorized": **blocked** — no Identity/Access system exists to authorize against. |
| POST-006 | AI cannot be recorded as Actor | **Blocked** | No AI/proposal-producing integration exists in this codebase yet. |
| POST-007 | Evidence traceability where applicable | **Partial (was Blocked)** | M6: `EvidenceReference` (minimal opaque contract) and atomic linkage are done and fault-injection proven (`AUD-T010`, `AUD-T011`). Evidence's own full schema/retention and tenant-ownership validation remain deferred (AETS-010 §2.2, §11). |
| POST-008 | Account existence required | **Done** | `PostingCommandAccountValidatorTest`. |
| POST-009 | Account same-Tenant ownership | **Done** | `PostingCommandAccountValidatorTest` (shares one rejection category with POST-008 per `COA-001`, by design). |
| POST-010 | Account must be Active | **Done** | `PostingCommandAccountValidatorTest`. |
| POST-011 | Account must be posting-eligible | **Done** | `PostingCommandAccountValidatorTest`. |
| POST-012 | Minimum Journal Line count | **Done** | Enforced by `Journal::create()` (M3), reused by `DraftJournalAssembler`. |
| POST-013 | Single Currency per Journal | **Done** | `Journal::create()`'s `MixedCurrencyJournalException`. |
| POST-014 | Non-negative Money magnitude | **Done** | Enforced at `Money` construction (AETS-003, proven by ATS-003). |
| POST-015 | Explicit Direction required | **Done** | Structurally impossible to omit — `JournalLine` requires exactly one `JournalDirection` enum value. |
| POST-016 | Exact Debit == Credit | **Done** | `Journal::create()`'s `UnbalancedJournalException`. |
| POST-017 | No binary float anywhere | **Done** | Enforced at `Money` construction (AETS-003). |
| POST-018 | Draft-only candidate input | **Done** | `PostingCommandJournalStateResolverTest` — including the M4-T19 correction for cross-tenant identity collision. |
| POST-019 | Atomic posting | **Partial (narrowed at M6)** | Journal header + Lines + idempotency mapping + Audit Event + Evidence linkage: done, fault-injection proven (`PostingCommandTransactionalExecutorTest`, M6). Outbox event: **not built** (§4) — the sole remaining gap in LED-003. |
| POST-020 | Rollback on failure | **Partial (narrowed at M6)** | Same split as POST-019 — proven for the five writes that exist; Outbox remains the only unbuilt one. |
| POST-021 | One authoritative Posted Journal | **Done** | `PostingCommandTransactionalExecutorTest`'s genuine forked-process concurrency tests — exactly one Journal survives both an identical and a conflicting real race. |
| POST-022 | No network call inside the transaction | **Done** | `PostingNoNetworkInTransactionTest` (architecture/source-scan, mirroring `JRN-T032`/`JRN-T192`'s technique). |
| POST-023 | No direct AI posting authority | **Blocked** | Same as POST-006. |
| POST-024 | Audit traceability | **Done (M6)** | [AETS-010](../specifications/accounting/AETS-010-Audit-Trail-Evidence-Linkage.md) created and implemented; `AuditEventRepository` records one Audit Event per successful Posting/Correction, atomically (`AUD-T001`–`AUD-T007`). |
| POST-025 | Outbox consistency | **Deferred** | ADR-0006's own deferred scope; Outbox mechanism not yet built. |
| POST-026 | Required Source Fingerprint fails safely | **Blocked** | Requirement-detection policy is explicitly a future calling module's decision (§6.2/§26) — no such module exists. |
| POST-027 | No fabricated Source Fingerprint | **Partial** | Architecture half (pipeline never self-generates one): done, `PostingCommandNeverGeneratesSourceFingerprintTest` (POST-T030). Runtime-rejection half (POST-T029): **blocked**, same reason as POST-026. |

**Tally at M4 close:** 16 fully done, 5 partial (each with a concretely done sub-part), 4 blocked, 2 deferred to named future specs, out of 27.

**Tally after M6:** 17 fully done (POST-024 moved from deferred to done), 5 partial (POST-007's status improved; POST-019/POST-020 narrowed to the Outbox gap alone), 4 blocked (unchanged — Actor/AI/Source-Fingerprint-policy items), 1 deferred (Outbox, POST-025, unchanged), out of 27.

## 3. What was found and fixed during closure (not part of the original plan)

- **Cross-tenant Journal identity collision** — a command proposing a `JournalId` already owned by a different Tenant was silently treated as fresh (since `journal_id` is a single global primary key, not a per-Tenant business identifier). Fixed via `JournalRepository::existsById()` (boolean-only, no Tenant leak) and `RejectedJournalIdentityUnavailableException`. Confirmed not to interfere with the genuine concurrent-race recovery path M4-T18B built.
- **Source Fingerprint's own duplicate-source mechanism** had zero coverage (POST-T031/T068) despite `SourceFingerprint` itself being complete since early M4 — closed with its own schema + repository, independent of the still-blocked requirement-detection policy.
- **No architecture-level proof existed** for "no network call in the transaction" (POST-022) or "the pipeline never fabricates a Source Fingerprint" (POST-027/T030) — both closed; neither required a production-code change, only the missing proof.
- **No container wiring existed** — every M4 test class necessarily hand-wired the ~10-class dependency graph itself. `AccountingServiceProvider` now makes the whole pipeline resolvable via `app()`.

## 4. What remains blocked, and exactly why

Every item below requires a specification, contract, or module this codebase does not yet have. None can be implemented without inventing that missing piece — which this milestone deliberately did not do.

| Blocked item | Missing prerequisite | Cited authority |
| --- | --- | --- |
| Actor → Tenant resolution, Actor authorization | A future Identity/Access specification | AETS-007 §8.1: Actor is "an opaque, immutable reference... once an Identity/Access system exists to resolve it further" |
| AI-cannot-be-Actor, AI-authority parity | An AI/proposal-producing integration | No such integration exists anywhere in this codebase yet |
| Evidence's own full schema, retention, storage; Evidence tenant-ownership validation | A future Document Processing specification | AETS-010 §2.2, §11 (M6): the minimal reference/linkage contract is now built; the full aggregate and its tenant check remain deferred, honestly tracked, not silently dropped |
| Source Fingerprint requirement-detection policy (when is one required) | A future calling module (Bank Reconciliation, Document Processing, etc.) | AETS-007 §6.2/§26: "determined by the calling module... this document does not enumerate every business context" |
| Outbox Event | ADR-0006's own delivery/dispatcher design | AETS-007 §22/§26: "ADR-0006's own deferred scope, unchanged here"; AETS-010 §2.2 (M6) confirms no current workflow requires one yet |
| Full atomicity (LED-003 / AETS-002 invariant 2, in full) | Outbox alone, as of M6 | Journal, Lines, idempotency, Audit Event, and Evidence linkage all commit atomically (M6) — only a required Outbox event, when one is ever needed, remains outside that transaction's proven scope |

## 5. Explicitly not built, and not implied by "M4 complete"

- No HTTP route, console command, or queue job. The pipeline is reachable via `app(PostingCommandTransactionalExecutor::class)` and nothing else. Choosing the entry-point shape (REST endpoint? internal service call? queue job?) is a real architectural decision for whoever builds the first calling module — not decided here.
- No public Accounting-module "contract" or facade (the kind ADR-0001 eventually calls for so other modules never import module internals). Every M4 class is already usable directly and is already the smallest correct surface; a wrapping contract without a second module yet consuming it would be a premature abstraction.
- No multi-currency, no correction/reversal workflow mechanics (deferred to AETS-006), no business-specific Accounting Commands (invoicing, payment allocation) — all explicitly out of AETS-007's own scope (§2.2).

## 6. Regression baseline at closure

- Full PostgreSQL suite: **871/871 passing** (2440 assertions), including genuine two-process concurrency proofs.
- Local (SQLite) suite: 871 total, 621 run, 250 skipped (every Postgres-dependent test skips cleanly with no real database reachable).
- `phpstan analyse`: 0 errors.
- `pint --test`: passing.
- `composer validate --strict`: valid.
- `git diff --check`: clean.

## 7. Post-M4 updates (M6 — Audit Trail & Evidence Linkage)

M6 closed the single most-cited gap in this document: Audit Event and a minimal Evidence Reference/Linkage contract, per the new [AETS-010](../specifications/accounting/AETS-010-Audit-Trail-Evidence-Linkage.md).

- `AuditEventRepository` and `JournalEvidenceLinkRepository` (new), wired into `PostingCommandTransactionalExecutor` (M4, extended) and `JournalCorrectionTransactionalExecutor` (M5, extended) — every successful, newly-posted Posting/Reversal/Replacement now records exactly one Audit Event, and any Posting Command carrying Evidence References now links them, all inside the same existing atomic transaction.
- `EvidenceReference` (new Value Object, mirrors `ActorReference`/`SourceReference` exactly).
- Two new tables: `audit_events`, `journal_evidence_links` — each a tenant-safe composite FK back to `journals`, mirroring the established `posting_idempotency_keys`/`posting_source_fingerprints` pattern.
- `ReverseJournalCommand`/`ReplaceJournalCommand` (M5) extended with `ActorReference`/`SourceReference` fields, since Audit Event production requires them and M5 had deliberately omitted both as unused at the time.
- **Not resolved by M6, deliberately:** Evidence's own full schema/retention/storage (Document Processing's future concern), Evidence tenant-ownership validation (blocked on the same prerequisite), and the Outbox Event schema (no current workflow needs one — building it now would be speculative, per ADR-0006's own rollout rule).
- Full regression at M6 close: PostgreSQL suite 312/312 passing (654 assertions) for the Feature-level Accounting Posting/Journal/Audit suite, 656/656 Unit tests passing (2072 assertions); `phpstan analyse`: 0 errors; `pint --test`: passing; `composer validate --strict`: valid; `git diff --check`: clean.

See the M6 closure report (delivered to the Founder alongside this update) for the full self-QA and acceptance-criteria walkthrough.

## 7. Recommendation for M5

Treat every row marked **Blocked** or **Deferred** above as a precondition, not a gap to silently work around, when scoping the next module. If M5 is the first calling module that would supply a Source Fingerprint or reference Evidence, that module's own design is where AETS-007's deferred policy questions (§6.2, §26) finally get answered — not by retrofitting Accounting Core.
