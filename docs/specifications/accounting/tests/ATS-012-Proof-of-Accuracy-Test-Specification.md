# ATS-012: Proof of Accuracy Test Specification

- Status: Draft
- Version: 0.2.0
- Effective date: Not effective — pending AETS-012 activation and required review
- Owner: Accounting Core (see [`CODEOWNERS`](../../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-012](../AETS-012-Proof-of-Accuracy.md), [AETS-002](../AETS-002-Accounting-Invariants.md), [AETS-003](../AETS-003-Money-Specification.md), [AETS-004](../AETS-004-Journal-Posting-Model.md), [AETS-007](../AETS-007-Posting-Command.md), [AETS-009](../AETS-009-Financial-Reporting.md), [AETS-010](../AETS-010-Audit-Trail-Evidence-Linkage.md)

## 1. Purpose and status

This Draft specifies the automated and review evidence required by AETS-012. The IDs below are prospective and are not claims of current implementation or certification. A case may be reported as passing only after it maps to a concrete test and approved dataset artifact.

## 2. Test strategy

- One connected, versioned dataset is the shared oracle; unrelated feature fixtures do not collectively become Proof of Accuracy.
- Financial comparisons use exact integer minor units.
- Persistence claims use a clean real PostgreSQL database created by production migrations.
- Expected results are independently reviewed and never generated from the system under test.
- Runs repeat from independent clean states.
- Any skip, missing artifact, missing approval, or mismatch fails the whole gate.

## 3. Traceability matrix

| Criterion | Test IDs |
| --- | --- |
| POA-001 | POA-T001, POA-T002 |
| POA-002 | POA-T003, POA-T004 |
| POA-003 | POA-T005, POA-T006 |
| POA-004 | POA-T007, POA-T008 |
| POA-005 | POA-T009–POA-T014 |
| POA-006 | POA-T015–POA-T017 |
| POA-007 | POA-T018 |
| POA-008 | POA-T019, POA-T020 |
| POA-009 | POA-T021 |
| POA-010 | POA-T022 |
| POA-011 | POA-T023 |
| POA-012 | POA-T024 |

## 4. Test cases

| Test ID | Required proof | Level |
| --- | --- | --- |
| POA-T001 | Manifest validates and names its dataset ID/version plus every required category. | Contract |
| POA-T002 | Every source/expected-output digest matches before execution. | Integrity |
| POA-T003 | Receipt, canonical facts, Expense, Evidence Reference, Source Fingerprint, Journal, and reports form an uninterrupted identity chain. | PostgreSQL integration |
| POA-T004 | Statement/rows, Invoice, Transactions, Journals, Trial Balance, and supported reports occur in one scenario. | PostgreSQL integration |
| POA-T005 | Every returned/persisted Money equals expected Currency and minor units exactly. | PostgreSQL integration |
| POA-T006 | Source scan rejects float Money arithmetic or floating-point fixture expectations in the certification path. | Architecture |
| POA-T007 | Every resulting Journal is Posted, has at least two lines, and balances exactly. | PostgreSQL integration |
| POA-T008 | A Draft Journal deliberately included in the scenario changes no report figure. | PostgreSQL integration |
| POA-T009 | Trial Balance lines/totals match and balance exactly. | PostgreSQL integration |
| POA-T010 | Profit & Loss lines/totals and Net Income match exactly. | PostgreSQL integration |
| POA-T011 | Balance Sheet lines/totals match and balance exactly. | PostgreSQL integration |
| POA-T012 | General Ledger opening/entries/closing and Journal links match exactly. | PostgreSQL integration |
| POA-T013 | Evidence Index presence/absence and links match exactly. | PostgreSQL integration |
| POA-T014 | Aging lines, buckets, outstanding balances, and grand total match the point-in-time oracle. | PostgreSQL integration |
| POA-T015 | Byte-identical statement replay creates no row/economic duplicate. | PostgreSQL integration |
| POA-T016 | An overlapping statement skips only duplicate rows and imports only new rows. | PostgreSQL integration |
| POA-T017 | Material-command replay creates no extra Journal, Audit Event, evidence link, Invoice, or economic effect. | PostgreSQL integration |
| POA-T018 | A second Tenant with colliding visible values observes only its own records across every exercised module/report. | PostgreSQL integration |
| POA-T019 | Receipt-backed Journal has only the approved source/evidence identities; fixture-synthesized evidence is never treated as uploaded evidence. | Integration + review |
| POA-T020 | Every material new posting has the expected Audit Event/evidence links, committed with its Journal. | PostgreSQL integration |
| POA-T021 | Two independent clean-database runs produce identical normalized deterministic-result documents after excluding declared operational timestamps. | Reproducibility |
| POA-T022 | Certification fails on test failure, error, skip, incomplete, risky, warning, or configured deprecation failure. | Harness self-test |
| POA-T023 | Certification record contains every AETS-012 §8 field/approval and matches the executed revision/dataset. | Contract + review |
| POA-T024 | Altered expectation, missing artifact, invalid digest, missing approval, or skipped required test fails aggregate certification. | Harness self-test |

## 5. Existing evidence and current gap

ATS-003, ATS-004/ATS-007, ATS-009, and ATS-010 provide reusable Money, posting, reporting, audit, and evidence proofs. Banking, Transactions, Invoicing, Payments, and Reporting integration suites provide reusable setup patterns. They cannot substitute for the connected dataset above.

The existing two-posting ATS-009 dataset does **not** satisfy AETS-012 §5.2 because it does not connect all seven mandatory artifact categories.

### 5.1 Certification harness (infrastructure only — no dataset, no certification)

As of 2026-09-16, the certification **mechanism** these tests will eventually drive exists and is directly proven, entirely independent of any dataset content — nothing here approves an oracle, runs a real scenario, or claims any `POA-NNN` criterion is satisfied for this product:

| Test ID | Mechanism-level coverage | Executable class |
| --- | --- | --- |
| POA-T001 (partial) | Manifest structural parsing (`dataset_id`/`version`/`artifacts` presence, per-entry category/path/digest validity, duplicate-path rejection) is proven. Confirming "every required category" per an *approved* dataset's §5.2 coverage is not — there is no approved dataset yet. | `GoldenDatasetManifestLoaderTest` |
| POA-T002 | Digest verification against real files — matching digests pass, a missing file is a violation, a tampered file is a violation, and every violation (not just the first) is reported — is proven with synthetic, explicitly-not-a-real-oracle fixtures. | `GoldenDatasetIntegrityVerifierTest` |
| POA-T022 | Each individual failure mode (a test failure, an error, a skip, incomplete, risky) independently flips the aggregate gate to failing, proven directly against `CertificationRecord::isCertifiedPassing()`. | `CertificationRecordTest` |
| POA-T024 | A missing Accounting Domain Reviewer approval, a blank CTO review, a recorded deviation, a failed criterion, and a non-zero-exit command each independently fail the aggregate gate — including the load-bearing case that a record with *no* reviewer approval (the real, current state of this product) is never certified passing no matter how clean everything else is. | `CertificationRecordTest` |

Every other `POA-TNNN` remains exactly as prospective as before — none of this exercises real services, a real scenario, or produces a certification record for anything but test fixtures.

## 6. Required fixture layout

After AETS-012 activation and dataset approval, implementation should use this logical layout (exact filenames may be finalized in review):

```text
apps/api/tests/Fixtures/ProofOfAccuracy/<dataset-version>/
├── manifest.json
├── sources/
│   ├── receipt.<approved-format>
│   └── bank-statement.csv
├── canonical/
│   └── source-facts.json
└── expected/
    └── accounting-results.json
```

No fixture directory is created by this Draft because the accounting oracle and source artifacts are not approved.

## 7. Activation blockers

- Accounting Domain Reviewer approval of AETS-012 and the first accounting oracle.
- Approved non-sensitive, redistributable receipt and bank-statement artifacts.
- Confirmation of the first supported-report certification baseline.
- Controlled certification-record location and retention policy.

## 8. Changelog

- **0.2.0 (2026-09-16):** Adds §5.1: the certification harness's own domain-agnostic mechanism (manifest parsing/integrity verification, and the fail-closed `CertificationRecord` aggregation gate) is now implemented and directly proven with synthetic fixtures, closing mechanism-level gaps in `POA-T001` (partial), `POA-T002`, `POA-T022`, and `POA-T024`. This is infrastructure only: no Golden Dataset exists, no certification scenario has run, and no `POA-NNN` criterion is claimed satisfied for this product. §7's activation blockers are unchanged.
- **0.1.0 (2026-09-13):** Initial Draft with 24 prospective tests tracing every AETS-012 criterion; no implementation or passing-certification claim.
