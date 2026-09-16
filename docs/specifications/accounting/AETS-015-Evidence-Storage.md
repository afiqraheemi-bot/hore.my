# AETS-015: Evidence Storage

- Status: Draft
- Version: 0.1.0
- Effective date: Not effective — pending required review
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: CTO / Technical Partner; Accounting Domain Reviewer (outstanding — same honest gap [AETS-008](AETS-008-Bank-Reconciliation.md)/[AETS-012](AETS-012-Proof-of-Accuracy.md) each record); Founder / Product Owner (scope/UX approval obtained 2026-09-16 — see §3)
- Related: [AETS-000](AETS-000.md), [AETS-001](AETS-001-Accounting-Terminology.md), [AETS-004](AETS-004-Journal-Posting-Model.md) §19, [AETS-007](AETS-007-Posting-Command.md) §10, [AETS-010](AETS-010-Audit-Trail-Evidence-Linkage.md) §2.2/§8/§11 — the specific deferred item this document resolves; [ADR-0004](../../adr/0004-financial-integrity-principles.md)

## 1. Purpose

[AETS-010 §2.2](AETS-010-Audit-Trail-Evidence-Linkage.md#22-out-of-scope) explicitly names "Evidence's own retention and storage mechanics" and "Evidence's own tenant-scoped aggregate and persisted identity" as owned by "a future Document Processing specification, not yet created." This document is that specification's first, deliberately narrow slice: a real, persisted Evidence record for an uploaded source file, and the real upload/retrieval boundary around it — nothing about interpreting that file's content.

**This Draft is not governing implementation and is not an accuracy or release certification.** Per this repository's own established convention ([AETS-008](AETS-008-Bank-Reconciliation.md) §1), a Draft may describe and even accompany real, running code — existing code described here is evidence of current behaviour, not authority for it, until an Accounting Domain Reviewer's sign-off is recorded.

## 2. Scope

### 2.1 In scope

- One Evidence aggregate: Tenant, original filename, declared MIME type, byte size, SHA-256 digest, storage path, uploading Actor, and upload time.
- A real upload boundary: an authenticated HTTP endpoint that stores the file on a private filesystem disk and persists the Evidence record atomically, returning an identifier usable as an [AETS-010 §8](AETS-010-Audit-Trail-Evidence-Linkage.md#8-evidence-reference--minimal-contract) Evidence Reference.
- A real, tenant-scoped download boundary for a previously uploaded Evidence file.
- Resolving [AETS-010 §11](AETS-010-Audit-Trail-Evidence-Linkage.md#11-atomicity-and-tenant-isolation)'s own named gap ("Evidence tenant-ownership — a tracked, honest gap, not a violation"): an Evidence Reference this document produces now names a real, tenant-scoped row a validator can check — though wiring that check into the Posting Command validation pipeline is explicitly out of scope below.

### 2.2 Out of scope

- OCR, AI classification, field extraction, or any interpretation of a stored file's content. [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §19 gates that behind Proof of Accuracy ([AETS-012](AETS-012-Proof-of-Accuracy.md)) passing; this document's storage boundary does not anticipate or shortcut it.
- Actually adding the [AETS-010 §11](AETS-010-Audit-Trail-Evidence-Linkage.md#11-atomicity-and-tenant-isolation) tenant-ownership check to `PostingCommandAccountValidator` or any Command translator. This document only makes a real row exist to check *against*; wiring that check into the validation pipeline is a separate, future change to [AETS-007](AETS-007-Posting-Command.md)'s own validator, not introduced here.
- Encryption at rest beyond the filesystem disk's own protection, malware/virus scanning, retention/deletion lifecycle policy, and any non-local storage backend (S3, etc.). Deferred to a future revision if a production-readiness review requires them — [ADR-0004](../../adr/0004-financial-integrity-principles.md)'s integrity principles govern exact digest/identity, not storage-medium hardening.
- Automatically attaching an uploaded Evidence's identifier to any specific Accounting Command. Each of the five existing Command translators already carries its own optional `evidenceReference` field ([AETS-007 §10](AETS-007-Posting-Command.md#10-evidence-references)); a caller supplies the identifier this document's upload endpoint returns, unchanged.
- Multiple files per Evidence record, versioning, or replacing an already-uploaded file. One upload produces one immutable Evidence record; a mistaken upload is superseded by a new one, never edited in place, mirroring this project's append-only convention everywhere else.

## 3. Authority and Founder approval

This document resolves [AETS-010 §2.2](AETS-010-Audit-Trail-Evidence-Linkage.md#22-out-of-scope)'s own explicitly-deferred item, within [ADR-0004](../../adr/0004-financial-integrity-principles.md)'s financial-integrity principles (exact digest, tenant isolation, no fabrication). It cannot weaken [AETS-010](AETS-010-Audit-Trail-Evidence-Linkage.md)'s own `AUD-006`/`AUD-007` (Evidence Reference opacity; no fabricated Evidence).

Introducing a real upload/storage/download boundary is a user-experience and product-scope change per [AETS-000 §8.2](AETS-000.md#82-ownership-and-review), requiring Founder / Product Owner review. That approval was obtained 2026-09-16: the Founder explicitly approved a CTO proposal ("ok sempurnakan") naming "Universal composer dan lampiran bukti sebenar" (a universal composer and real evidence attachment) as the deliverable. An Accounting Domain Reviewer's independent sign-off remains outstanding, consistent with the same honest gap [AETS-008](AETS-008-Bank-Reconciliation.md)/[AETS-012](AETS-012-Proof-of-Accuracy.md) each already record — this document's scope is storage plumbing, not a new accounting rule, but AETS-000 §8.2 names that review unconditionally and this document does not claim an exception to it.

## 4. Evidence aggregate

| Field | Requirement |
| --- | --- |
| Evidence ID | A stable, tenant-scoped identifier, usable as an [AETS-010 §8](AETS-010-Audit-Trail-Evidence-Linkage.md#8-evidence-reference--minimal-contract) Evidence Reference. |
| Tenant | The uploading Tenant. Immutable. |
| Original filename | As supplied by the uploader, for display only — never used to resolve a storage path. |
| MIME type | The declared content type, validated against an explicit allow-list (§6). |
| Byte size | The exact uploaded file size. |
| SHA-256 digest | Computed server-side from the actual stored bytes, never trusted from the client. |
| Storage path | Opaque to every caller outside this module; never derived from the original filename (path-traversal/collision safety). |
| Uploaded by | The `ActorReference` who performed the upload. |
| Uploaded at | The UTC time of upload. |

**Immutable, append-only.** No field of a persisted Evidence record is ever updated after creation. There is no "delete" operation in this document's scope (§2.2).

## 5. Upload boundary

`POST` accepts one multipart file per request, under an authenticated, tenant-resolved session. On success it:

1. reads the file and computes its SHA-256 digest server-side;
2. validates size and MIME type against explicit bounds (§6) before ever writing to disk;
3. stores the file on the private local filesystem disk, at a path derived from the Tenant and a newly generated opaque identifier — never from the original filename;
4. persists the Evidence record and the stored file together — a failure of either leaves neither (§7);
5. returns the Evidence ID.

## 6. Failure semantics

- A request with no file, an empty file, a MIME type outside the allow-list, or a file exceeding the size bound is rejected with a validation error and writes nothing — mirroring [AETS-008 §5](AETS-008-Bank-Reconciliation.md#5-import-contract-already-evidenced)'s own "malformed input, zero persistent effect" precedent for the only other real file-upload boundary in this system.
- A persistence failure after the file is written to disk removes the orphaned file before returning an error — there is no Evidence record without a corresponding stored file, and no stored file without a corresponding record.
- A tenant mismatch on download (an Evidence ID that exists but belongs to another Tenant) fails closed as not found, never as a distinguishable "exists but forbidden" response, matching this project's established tenant-isolation convention.
- No failure may be translated into a successful upload result.

## 7. Invariants

| ID | Invariant |
| --- | --- |
| EVI-001 | An Evidence record and its stored file are created atomically — a failure of either leaves neither. |
| EVI-002 | An Evidence record's SHA-256 digest is always computed server-side from the actual stored bytes, never supplied or trusted from the client. |
| EVI-003 | An Evidence record's storage path is never derived from the original filename. |
| EVI-004 | Every Evidence record and its file are Tenant-isolated; a cross-Tenant read fails closed as not found. |
| EVI-005 | An Evidence record, once created, is never updated or deleted through the application. |
| EVI-006 | An upload rejected by validation (size, MIME type, missing file) persists nothing and stores nothing. |
| EVI-007 | An Evidence ID this document produces is a valid [AETS-010 §8](AETS-010-Audit-Trail-Evidence-Linkage.md#8-evidence-reference--minimal-contract) Evidence Reference — opaque, immutable, exactly comparable. |

## 8. Relationship to AETS-010

This document does not amend [AETS-010](AETS-010-Audit-Trail-Evidence-Linkage.md) directly; AETS-010 §2.2/§11 are updated separately (see that document's own changelog) to point here for the storage mechanics and tenant-ownership resolution it named as deferred. AETS-010's own Evidence Reference contract (§8), Evidence Linkage record (§9), and `AUD-006`–`AUD-009` invariants are unchanged by this document and continue to govern how a Posting Command carries and links an Evidence Reference once obtained.

## 9. Required test specification

A dedicated ATS-015 companion document is deferred for this initial slice — the seven `EVI-NNN` invariants above are proven directly by the executable test suite named in this document's own commit, following this repository's established real-PostgreSQL, no-mocked-persistence discipline. A dedicated ATS-015 document will be added if this module's scope grows beyond this initial upload/retrieval boundary.

## 10. Deferred decisions

- Whether/when to add the [AETS-010 §11](AETS-010-Audit-Trail-Evidence-Linkage.md#11-atomicity-and-tenant-isolation) tenant-ownership check into the Posting Command validation pipeline.
- OCR/AI interpretation of stored Evidence content (Master Context §19 gate).
- Encryption at rest, malware scanning, retention/deletion policy, non-local storage backends.
- Multi-file Evidence, versioning, or supersession semantics.

## Changelog

- **0.1.0 (2026-09-16):** Initial Draft. Resolves AETS-010 §2.2's own named "Evidence's own retention and storage mechanics" deferral with a minimal, real, tenant-scoped Evidence aggregate and upload/download boundary. Founder scope/UX approval obtained; Accounting Domain Reviewer sign-off outstanding. No AI/OCR interpretation, no Posting Command validation wiring, and no AETS-010 schema change are introduced.
