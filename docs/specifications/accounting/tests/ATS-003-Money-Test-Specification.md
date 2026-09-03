# ATS-003: Money Test Specification

- Status: Active
- Version: 1.1.0
- Effective date: 2026-09-03
- Owner: Accounting Core (see [`CODEOWNERS`](../../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-000](../AETS-000.md), [AETS-001](../AETS-001-Accounting-Terminology.md), [AETS-002](../AETS-002-Accounting-Invariants.md), [AETS-003](../AETS-003-Money-Specification.md); [ADR-0004](../../../adr/0004-financial-integrity-principles.md), [ADR-0007](../../../adr/0007-money-representation-strategy.md) (as amended)

## 1. Purpose

This document is the normative Accounting Test Specification (ATS) proving compliance with [AETS-003: Money Specification](../AETS-003-Money-Specification.md). It exists so that Money implementation work has a precise, testable, traceable target before any code is written — exactly the coverage AETS-003 §24 said a future Money ATS must provide.

Every test defined here is identified by a stable ID (`MON-T001`–`MON-T092`) and traced to the `MON-NNN` invariant(s) it proves (§6). This document does not implement any test; it specifies what must be proven, at what level, and with what data, so that an implementer or an automated agent can build the actual test suite against it.

## 2. Scope

### 2.1 In scope

- Test-level classification (unit, property-based, integration, architecture/static-analysis, boundary/contract, golden) for every requirement in AETS-003.
- A complete traceability matrix from `MON-001`–`MON-015` to test IDs.
- Concrete test cases — including the exact golden MYR values and invalid-input cases this task specifies — for Money construction, Currency, MinorUnits, parsing, arithmetic, comparison, rounding, serialization, persistence, vendor isolation, and failure semantics.
- Generator definitions for property-based testing.
- Coverage requirements and exit criteria gating Money implementation completeness.

### 2.2 Out of scope

- Any implementation code, test framework configuration, or CI wiring — this document specifies tests, it does not write them.
- Anything AETS-003 itself defers: Money sign policy, tax/business-specific rounding policy (including the `RoundingMode` member set), Journal/Posting Engine design, and Chart of Accounts design. Tests that would require any of these are listed as deferred (§23), not designed around a guess.
- Behavior AETS-003 does not define. Where this task's required invalid-input list touches something AETS-003 leaves ambiguous, this document flags it (§7 of this task's return; inline notes below) rather than inventing an answer.

## 3. Authority

This document is subordinate to [AETS-003](../AETS-003-Money-Specification.md), which is itself subordinate to [ADR-0007](../../../adr/0007-money-representation-strategy.md) (as amended) and the authority hierarchy in [AETS-000 §3](../AETS-000.md#3-authority-hierarchy). Where a test specified here appears to require behavior AETS-003 does not define, that is a defect in this document to be corrected, not a license to invent AETS-003 content through a test (per [AETS-000 §8.4](../AETS-000.md#84-relationship-to-adrs-and-conflict-handling)).

## 4. Test Philosophy

- **Prove the invariant, not the implementation.** Every test traces to a `MON-NNN` invariant or an explicit AETS-003 requirement — not to incidental implementation detail.
- **Exact-or-fail, tested both ways.** Per AETS-003 §17, every operation must produce exactly a correct result or a typed failure. This ATS tests both outcomes explicitly for every operation that can fail — a test suite that only exercises the happy path does not prove AETS-003 compliance.
- **No silent behavior is acceptable evidence.** A test that merely checks "no exception was thrown" is insufficient; tests assert the specific expected value or the specific expected failure category (§17 of AETS-003).
- **Determinism is provable, not assumed.** Any test claiming deterministic behavior (parsing, rounding) must demonstrate it by repetition or property, not by a single example.
- **Don't invent AETS-003 content.** Where AETS-003 leaves a case ambiguous, this document says so rather than picking an answer a test would then silently enforce.

## 5. Test Levels

| Level | Purpose | Tooling (as already established for hore.my) | Primary sections |
| --- | --- | --- | --- |
| **Unit** | Isolated behavior of Money, Currency, and MinorUnits methods, no external dependency. | PHPUnit (`apps/api`, per [T0.6](../../../../apps/api/composer.json)) | §7–14, §17 |
| **Property-based** | Behavior proven across a generated range of inputs, not fixed examples. | A property-based testing library — **selection deferred**; not yet chosen for hore.my (§23). | §18 |
| **Integration** | Behavior across a real boundary — the persistence adapter against an actual PostgreSQL instance. | PHPUnit against the Docker Postgres service ([`docker-compose.yml`](../../../../docker-compose.yml), per T0.5) | §15 |
| **Architecture / static-analysis** | Structural guarantees (vendor-type isolation, no native-int accessor) that hold across the whole codebase, not just tested call sites. | Larastan / PHPStan at level 9 (`apps/api/phpstan.neon`, per T0.6); a dedicated architecture rule may be needed for namespace-leakage checks (§23) | §16, parts of §7–9 |
| **Boundary / contract** | Behavior at the untrusted-input boundary — parsing rejection rules, serialization shape. | PHPUnit, data-driven | §10, §14, §20 |
| **Golden cases** | Fixed, exact, hand-verified MYR values that must never silently change. | PHPUnit, data-driven | §19 |

## 6. Invariant Traceability Matrix

Every `MON-NNN` invariant from [AETS-003 §20](../AETS-003-Money-Specification.md#20-invariants) maps to at least one test ID below.

| Invariant | Summary | Test IDs |
| --- | --- | --- |
| MON-001 | No binary float | MON-T034, MON-T042, MON-T056, MON-T085 |
| MON-002 | Exact construction | MON-T001, MON-T003, MON-T004, MON-T007, MON-T008, MON-T022, MON-T073, MON-T082 |
| MON-003 | Immutable Money (and Currency, MinorUnits) | MON-T006, MON-T013 |
| MON-004 | Explicit Currency | MON-T005, MON-T009, MON-T010, MON-T062, MON-T092 |
| MON-005 | Currency owns scale | MON-T011, MON-T014, MON-T063 |
| MON-006 | Cross-currency guard | MON-T036, MON-T038, MON-T047, MON-T049, MON-T074, MON-T083 |
| MON-007 | Deterministic decimal parsing | MON-T007, MON-T022, MON-T058, MON-T073, MON-T082 |
| MON-008 | Exact MinorUnits round-trip | MON-T017, MON-T020, MON-T064, MON-T065, MON-T079, MON-T080 |
| MON-009 | Persistence bounds checking | MON-T059, MON-T060, MON-T061, MON-T066, MON-T077, MON-T084 |
| MON-010 | No vendor-type leakage | MON-T067, MON-T068, MON-T069, MON-T070, MON-T071, MON-T078 |
| MON-011 | Exact-or-fail arithmetic | MON-T035, MON-T037, MON-T039, MON-T040, MON-T041, MON-T043, MON-T044, MON-T072, MON-T075, MON-T076, MON-T081, MON-T086 |
| MON-012 | Serialization round-trip | MON-T055, MON-T056, MON-T057, MON-T058, MON-T065, MON-T079 |
| MON-013 | MinorUnits type-safety | MON-T015, MON-T016, MON-T017, MON-T019, MON-T020 |
| MON-014 | No native-int canonical accessor | MON-T018 |
| MON-015 | Explicit rounding requirement | MON-T040, MON-T041, MON-T051, MON-T052, MON-T053, MON-T054, MON-T076 |

Some tests (e.g. MON-T062, MON-T063, MON-T089–MON-T092) primarily prove an AETS-003 persistence or security requirement rather than a single named `MON-NNN` invariant directly; these are mapped to the invariant whose consequence they enforce at the storage or input-validation boundary, and are cross-referenced again in their own section below.

## 7. Money Construction Tests

| ID | Test |
| --- | --- |
| MON-T001 | `fromDecimalString` succeeds for a valid canonical decimal string at the target Currency's exact scale. |
| MON-T002 | `fromDecimalString` produces a typed failure for input not matching the canonical grammar (general case; specific categories detailed in §10). |
| MON-T003 | `fromMinorUnits` succeeds for any valid MinorUnits and Currency pair. |
| MON-T004 | A Money constructed via `fromDecimalString` and an equivalent Money constructed via `fromMinorUnits` are equal (§12). |
| MON-T005 | Money cannot be constructed without a Currency through any public construction path. |
| MON-T006 | No operation on a constructed Money instance mutates it; the original instance's output is unchanged after any operation is invoked on it. |
| MON-T007 | Repeated construction from the same valid decimal string and Currency is deterministic — same result on every invocation. |
| MON-T008 | Two Money instances independently constructed from equal inputs are value-equal (§12), not merely reference-distinct. |

## 8. Currency Tests

| ID | Test |
| --- | --- |
| MON-T009 | Currency constructed from the canonical `MYR` identifier succeeds and exposes scale `2`. |
| MON-T010 | Currency construction fails for an unsupported or invalid identifier. |
| MON-T011 | No public construction path allows an identifier to be paired with a scale inconsistent with that identifier's canonical scale — scale is derived, never independently settable. |
| MON-T012 | Two Currency instances with the same identifier are equal; two with different identifiers are not. |
| MON-T013 | Currency is immutable. |
| MON-T014 | A Money value's applicable scale (via its Currency) always matches that Currency's canonical scale — no independent drift is possible. |

## 9. MinorUnits Tests

| ID | Test |
| --- | --- |
| MON-T015 | MinorUnits constructed from a valid integer numeral string succeeds. |
| MON-T016 | MinorUnits construction fails for a non-integer or malformed numeral (e.g. contains a decimal point or a non-digit character). |
| MON-T017 | MinorUnits exposes an exact string representation reproducing its constructing exact value. |
| MON-T018 | *(Architecture/static-analysis)* No public method on MinorUnits (or Money) returns a native PHP `int` as the canonical representation of an amount. |
| MON-T019 | *(Architecture/static-analysis)* MinorUnits exposes no arithmetic or business-behavior method of its own (no add/subtract/multiply/divide) — only construction, exact-string output, and equality. |
| MON-T020 | MinorUnits round-trips an integer numeral larger than native `PHP_INT_MAX` without precision loss (arbitrary-magnitude exactness, independent of the separate signed-64-bit persistence bound tested in §15). |
| MON-T021 | Two MinorUnits instances representing the same exact integer are equal; representing different integers, they are not. |

## 10. Parsing and Boundary Tests

All tests in this section target `fromDecimalString` against the canonical decimal grammar defined in [AETS-003 §9](../AETS-003-Money-Specification.md#9-construction-and-parsing): optional leading minus sign, one or more digits, and — only if the Currency's scale is greater than zero — exactly one decimal point followed by exactly that many digits, with no other character permitted.

| ID | Test | Example input (MYR, scale 2) | Expected |
| --- | --- | --- | --- |
| MON-T022 | Valid canonical string parses successfully. | `"10.25"` | Succeeds |
| MON-T023 | Malformed decimal string (general) is rejected. | `"10..25"`, `"abc"` | Typed failure |
| MON-T024 | Over-precision (more digits after the decimal point than the Currency's scale) is rejected, never silently rounded. | `"10.255"` | Typed failure |
| MON-T025 | Under-precision (fewer digits after the decimal point than the Currency's scale, or no decimal point at all) is rejected — the canonical grammar requires exactly the Currency's scale in digits, never fewer. A separate deterministic normalization layer, if implemented, may accept human-friendly under-precision input and produce a canonical string before parsing; `fromDecimalString` itself performs no such normalization. | `"10.2"`, `"10"` | Typed failure |
| MON-T026 | Locale-formatted decimal separator (comma) is rejected — no implicit locale normalization. | `"10,25"` | Typed failure |
| MON-T027 | Thousands-separator input is rejected. | `"1,000.00"` | Typed failure |
| MON-T028 | Currency-symbol-prefixed input is rejected. | `"RM10.25"` | Typed failure |
| MON-T029 | Scientific notation is rejected. | `"1e2"` | Typed failure |
| MON-T030 | Non-numeric sentinel strings are rejected — these are not part of the canonical grammar at all. | `"NaN"`, `"Infinity"` | Typed failure |
| MON-T031 | Empty string is rejected. | `""` | Typed failure |
| MON-T032 | Whitespace-only string is rejected. | `"   "` | Typed failure |
| MON-T033 | Leading/trailing whitespace around an otherwise-valid amount is rejected — whitespace is not part of the canonical grammar, so it is not trimmed-then-accepted. | `" 10.25 "` | Typed failure |
| MON-T034 | A native binary float value (not a string) is rejected at the type level, before grammar validation is even applied. | `10.25` (PHP `float`) | Typed failure |

## 11. Arithmetic Tests

| ID | Test |
| --- | --- |
| MON-T035 | `add` of two same-Currency Money values produces their exact mathematical sum. |
| MON-T036 | `add` between two different-Currency Money values fails with the typed cross-currency exception. |
| MON-T037 | `subtract` of two same-Currency Money values produces their exact mathematical difference, for any pair whose result is non-negative. (A would-be-negative result is out of this test's scope — §23.) |
| MON-T038 | `subtract` between two different-Currency Money values fails with the typed cross-currency exception. |
| MON-T039 | `add` then `subtract` the same Money value returns to the original value exactly, bounded to cases that stay within whatever domain the current (deferred) sign policy permits. |
| MON-T040 | `multiply` by an exact non-Money scalar, with an explicit `RoundingMode`, produces the exactly rounded result per that mode when rounding is required. *(Content deferred until `RoundingMode`'s member set is specified — §23; this test's structure is fixed now.)* |
| MON-T041 | `multiply` whose exact result already fits the Currency's scale is unaffected by which `RoundingMode` was supplied (rounding is a no-op when not needed). |
| MON-T042 | `multiply` and `divide` reject a native float scalar argument at the type level. |
| MON-T043 | `divide` by zero produces a typed failure — never an engine-level error, warning, `NaN`, or `Infinity` result. |
| MON-T044 | `divide` whose result is inexact, called without a `RoundingMode`, produces a typed failure. |

## 12. Equality and Comparison Tests

| ID | Test |
| --- | --- |
| MON-T045 | `equals` returns `true` for two Money values with the same exact quantity and the same Currency. |
| MON-T046 | `equals` returns `false` for two Money values with different quantities, same Currency. |
| MON-T047 | `equals` returns `false` — and never throws — for two Money values of different Currency. |
| MON-T048 | `compare` produces a consistent, transitive three-way ordering for same-Currency Money values (reflexivity, antisymmetry, transitivity). |
| MON-T049 | `compare` throws the typed cross-currency exception for different-Currency Money values. |
| MON-T050 | Equality and comparison are value-based, not identity-based — two distinct instances constructed to equal value must be equal (proves no reliance on native `==`, `<=>`, or default object identity). |

## 13. Rounding Contract Tests

| ID | Test |
| --- | --- |
| MON-T051 | Every `multiply`/`divide` call requires an explicit `RoundingMode` argument — omitting it is not a callable/valid invocation. |
| MON-T052 | *(Architecture/static-analysis)* No vendor `RoundingMode` type is accepted or returned by any public Money method — only hore.my's own type. |
| MON-T053 | The same input and the same explicit `RoundingMode` always produce the same result. **Deferred** — no `RoundingMode` member set exists yet to test against (§23). |
| MON-T054 | A `multiply`/`divide` operation that does not require rounding produces the same result regardless of which `RoundingMode` was supplied. |

## 14. Serialization Tests

| ID | Test |
| --- | --- |
| MON-T055 | Serializing a Money value to its boundary representation (decimal string + Currency identifier) and deserializing it back reproduces an equal Money value. |
| MON-T056 | The serialized amount field is always a string, never a raw JSON number, in the produced boundary representation. |
| MON-T057 | Round-trip (MON-T055) holds for MYR edge-case amounts: zero, the smallest non-zero amount (`0.01`), and a large tested magnitude. |
| MON-T058 | A deserialized boundary payload is revalidated through the same construction path as any other untrusted input (§10) — it is not treated as pre-trusted merely because hore.my produced it originally. |

## 15. Persistence Adapter Contract Tests

*(Integration level — these tests exercise the adapter against a real PostgreSQL instance, per [ADR-0007](../../../adr/0007-money-representation-strategy.md)'s amendment and [AETS-003 §15](../AETS-003-Money-Specification.md#15-persistence-mapping).)*

| ID | Test |
| --- | --- |
| MON-T059 | A MinorUnits value within the signed 64-bit range is accepted and written to a `BIGINT` column successfully. |
| MON-T060 | A MinorUnits value exactly at the signed 64-bit boundary (`9223372036854775807` and `-9223372036854775808`) is accepted. |
| MON-T061 | A MinorUnits value exceeding the signed 64-bit boundary, in either direction, is rejected **before** any database write is attempted — a typed failure raised by the adapter, not a database-level error surfaced afterward. |
| MON-T062 | Currency is written explicitly on every monetary row — the persisted row's currency is never inferred from tenant or another aggregate. |
| MON-T063 | No scale column exists on, or is written to, a monetary row — scale is confirmed absent from the persisted schema. |
| MON-T064 | A Money value read back from a persisted `(MinorUnits, Currency)` pair is reconstructed via the validated `fromMinorUnits` construction path, never treated as pre-trusted raw data. |
| MON-T065 | A full write-then-read persistence round-trip reproduces an exactly equal Money value, for representative and edge-case amounts. |
| MON-T066 | The persistence adapter never narrows, truncates, wraps, silently rounds, or converts to float, in either direction — proven by asserting the specific out-of-range failure occurs rather than any form of silent modification. |

## 16. Vendor Isolation Tests

*(Architecture/static-analysis level, per [AETS-003 §16](../AETS-003-Money-Specification.md#16-vendor-isolation).)*

| ID | Test |
| --- | --- |
| MON-T067 | No `Brick\...` (or other vendor library) namespace type appears in any public Money, Currency, or MinorUnits method signature. |
| MON-T068 | No `Brick\...` namespace type appears in any public application-layer interface anywhere in the codebase — not only within the Money implementation itself. |
| MON-T069 | A vendor-library exception raised internally during any Money operation is caught and re-raised as a hore.my-owned exception before it can reach any caller. |
| MON-T070 | The complete public API surface for Money operations consists only of hore.my-owned types: Money, Currency, MinorUnits, `RoundingMode`, and hore.my-owned exceptions — confirmed by an explicit inventory check. |
| MON-T071 | No public entry point allows constructing Money, Currency, or MinorUnits using a vendor type directly. |

## 17. Failure-Semantics Tests

Per [AETS-003 §17](../AETS-003-Money-Specification.md#17-exception-and-failure-semantics), every operation must produce exactly an exact result or a typed failure, with each failure category distinguishable.

| ID | Test |
| --- | --- |
| MON-T072 | Across a representative sweep of valid and invalid inputs to every specified Money operation, the outcome is always exactly an exact result or a typed failure — never a third outcome. |
| MON-T073 | Malformed or over-precision decimal input produces a failure category distinguishable from every other failure category. |
| MON-T074 | A cross-currency operation produces a failure category distinguishable from every other failure category. |
| MON-T075 | Division by zero produces a failure category distinguishable from every other failure category. |
| MON-T076 | A `multiply`/`divide` missing a required `RoundingMode` produces a failure category distinguishable from every other failure category. |
| MON-T077 | An out-of-range MinorUnits value at the persistence boundary produces a failure category distinguishable from every other failure category. |
| MON-T078 | A translated vendor exception (MON-T069) is observably a hore.my-owned type when inspected by category, not merely "not a vendor type." |

## 18. Property-Based Tests

### 18.1 Generators

| Generator | Produces |
| --- | --- |
| **Valid MYR decimal strings** | Strings conforming exactly to the MYR canonical grammar (§9 grammar, scale 2): optional `-`, digits, `.`, exactly two digits — ranging across small, large, zero, and boundary-adjacent magnitudes. |
| **Valid MinorUnits values** | Arbitrary-magnitude integer numerals, including values within, at, and beyond the signed 64-bit range (the latter for MON-T020, not for persistence tests). |
| **Invalid decimal strings** | Strings drawn from every rejection category in §10 (malformed, over-precision, under-precision, locale-formatted, thousands-separated, symbol-prefixed, scientific notation, sentinel words, empty, whitespace-only, whitespace-padded), plus randomized mutations of valid strings (character insertion/deletion/substitution). |
| **Currency mismatches** | Pairs of Money values constructed with deliberately different Currency identifiers from the currently-supported set and, where useful for negative testing, syntactically well-formed but unsupported identifiers. |
| **BIGINT boundary values** | MinorUnits values clustered around the signed 64-bit boundary: at the boundary, one below/above it, and far beyond it in both directions. |
| **Arithmetic operands** | Pairs/triples of same-Currency Money values and exact non-Money scalars (numeral strings), spanning zero, small, large, and boundary-adjacent magnitudes, for `add`/`subtract`/`multiply`/`divide`. |

### 18.2 Properties

| ID | Property |
| --- | --- |
| MON-T079 | For any generated valid MYR decimal string *d*: `fromDecimalString(d).toDecimalString() == d`. *(decimal → Money → decimal round-trip)* |
| MON-T080 | For any generated valid MinorUnits value *m*: `fromMinorUnits(m).toMinorUnits() == m`. *(minor units → Money → minor units round-trip)* |
| MON-T081 | For any generated same-Currency arithmetic operands: `add` is commutative, and always equals the exact mathematical sum. |
| MON-T082 | For any generated invalid decimal string: `fromDecimalString` always produces a typed failure — never succeeds, never crashes ungracefully. |
| MON-T083 | For any generated currency-mismatched Money pair: every guarded operation (`add`, `subtract`, `compare`) fails with the cross-currency failure; `equals` never throws and returns `false`. |
| MON-T084 | For any generated BIGINT-boundary MinorUnits value: the persistence adapter's accept/reject decision exactly matches whether the value is within the signed 64-bit range — no false accepts, no false rejects. |
| MON-T085 | Across all six generator categories, no generated input ever causes a native binary float to appear in Money's internal state or output. |
| MON-T086 | For any generated Money value *m* and same-Currency delta *d* where the intermediate and final results stay within whatever domain the current (deferred) sign policy permits: `m.add(d).subtract(d) == m`. *(add/subtract inverse property, explicitly bounded pending sign policy — §23)* |

## 19. Golden Test Cases

**Valid MYR golden cases** — `MON-T087`, one data-driven test over this exact table:

| Decimal string | MinorUnits |
| --- | --- |
| `"0.00"` | `"0"` |
| `"0.01"` | `"1"` |
| `"1.00"` | `"100"` |
| `"10.20"` | `"1020"` |
| `"10.25"` | `"1025"` |
| `"999999.99"` | `"99999999"` |

**Invalid golden cases** — `MON-T088`, one data-driven test over this exact table (each input must produce a typed failure, never a value):

| Input | Reason |
| --- | --- |
| `"10.2"` | Under-precision — MYR's canonical grammar requires exactly 2 digits after the decimal point, never fewer. Normative: `"10.2"` is not canonical, even though it is a mathematically equivalent human-friendly form of `"10.20"`. |
| `"10"` | No decimal point at all — under-precision in its most reduced form; MYR (scale 2) requires the decimal point and exactly 2 digits even when the fractional amount is zero. |
| `"10.250"` | Over-precision |
| `"1,000.00"` | Thousands separator |
| `"RM10.25"` | Currency symbol |
| `"1e2"` | Scientific notation |
| `"NaN"` | Non-numeric sentinel |
| `"Infinity"` | Non-numeric sentinel |
| `""` | Empty string |
| `"   "` | Whitespace-only |
| `" 10.25 "` | Leading/trailing whitespace |
| `10.25` (native float) | Binary float input, rejected at the type level |

**Currency identifier golden cases** — part of `MON-T092`, one data-driven test over this exact table:

| Input | Expected | Reason |
| --- | --- | --- |
| `"MYR"` | Valid | Canonical uppercase ISO 4217 form |
| `"myr"` | Typed failure | Lowercase is not canonical — Currency performs no implicit case normalization |
| `"Myr"` | Typed failure | Mixed case is not canonical, for the same reason |

In both tables above, a separate deterministic normalization layer — for decimal strings (e.g. `"10.2"` → `"10.20"`) and for currency identifiers (e.g. `"myr"` → `"MYR"`) — may exist ahead of the Money/Currency boundary and is explicitly permitted by [AETS-003 §9](../AETS-003-Money-Specification.md#9-construction-and-parsing). `fromDecimalString` and Currency's constructor themselves perform no such normalization; that is what these golden cases prove.

## 20. Security / Invalid Input Cases

| ID | Test |
| --- | --- |
| MON-T089 | An adversarially long decimal string input is rejected via a bounded input-length check before full grammar parsing is attempted, per [AETS-003 §18](../AETS-003-Money-Specification.md#18-security-and-validation). |
| MON-T090 | Input containing null bytes or control characters is rejected — not part of the canonical grammar. |
| MON-T091 | *(Architecture/static-analysis)* No Money construction or parsing path passes input to dynamic code evaluation. |
| MON-T092 | Currency identifier validation rejects any identifier outside the currently-supported set (`MYR` for MVP), including syntactically plausible but unsupported codes, and rejects any non-canonical case variant of a supported identifier (e.g. `"myr"`, `"Myr"`) — canonical currency identifiers are uppercase ISO 4217 form only; Currency performs no implicit case normalization. |

## 21. Coverage Requirements

- Every `MON-NNN` invariant (§6) has at least one passing test; none may be satisfied by inference alone.
- Every rejection category in [AETS-003 §9](../AETS-003-Money-Specification.md#9-construction-and-parsing) has a corresponding test in §10 and, where listed, a golden case in §19.
- Every failure category named in [AETS-003 §17](../AETS-003-Money-Specification.md#17-exception-and-failure-semantics) is distinguishably tested (§17 of this document).
- Every public method specified in [AETS-003 §10](../AETS-003-Money-Specification.md#10-public-money-contract) is exercised by at least one success case and, where it can fail, at least one failure case.
- A specific numeric line/branch coverage percentage is not set by this document — hore.my's project-wide coverage policy is established at the stack-ADR level, per `ENGINEERING_BLUEPRINT.md` §5.5; this document's coverage requirement is the invariant- and requirement-level completeness above, which is a stronger and more specific bar than a percentage alone would be.

## 22. Exit Criteria

Money implementation **cannot** be considered complete unless, at minimum:

- All required `MON-T` tests (§6) pass.
- All property-based tests (§18) pass.
- All golden cases (§19) pass, exactly, with no tolerance.
- Static analysis (Larastan/PHPStan, level 9) passes with zero errors on all Money-related code.
- Vendor isolation tests (§16) pass.
- No unexplained cent drift exists across any tested arithmetic, round-trip, or persistence sequence.
- No binary float enters financial logic at any point tested.
- Persistence round-trip is exact for every tested amount, including boundary values.

## 23. Deferred Tests

The following are explicitly not specified in testable detail here, because AETS-003 itself defers the behavior they would need to test:

- **`subtract`'s behavior for a would-be-negative result**, and the full, unbounded form of the add/subtract inverse property (MON-T086) — deferred pending Money sign policy (future Journal & Posting specification).
- **Deterministic rounding-mode content tests** (MON-T053) and the concrete expected values for MON-T040 — deferred pending the `RoundingMode` member set, itself deferred pending tax/business-specific rounding policy.
- **Multi-currency-scale-generality tests** beyond MYR — MVP supports only MYR ([AETS-003 §7](../AETS-003-Money-Specification.md#7-currency-specification)); tests for a second currency's scale are not written against data that does not exist.
- **Journal- or Posting-level integration tests** (e.g. Money inside a posted Journal Line) — out of scope for this Money-only ATS; belongs to a future ATS for AETS-004.
- **Property-based testing tooling selection** — no library is chosen yet for hore.my (§5); §18's properties are specified independent of tooling, to be implemented once a library is selected.
- **The vendor-namespace-leakage architecture rule** (supporting MON-T067/T068) — this document specifies what must be proven, not the specific static-analysis rule implementation; building it is deferred implementation work.

## 24. Change Governance

This document follows [AETS-000](../AETS-000.md)'s governance rules in full — it does not restate them: lifecycle ([AETS-000 §8.3](../AETS-000.md#83-lifecycle)), review requirements ([AETS-000 §8.2](../AETS-000.md#82-ownership-and-review)), and versioning ([AETS-000 §9.1](../AETS-000.md#91-per-document-version)) all apply unchanged.

A change that alters a test ID's expected result, removes a test ID, or changes the `MON-NNN` traceability mapping (§6) is a MAJOR change under that rule and requires the same review as a MUST-level change to AETS-003 itself. Adding new test IDs without altering existing ones, or filling in content for a currently-deferred test (§23) once its blocking AETS-003 dependency resolves, is MINOR.

## Changelog

- **1.1.0 (2026-09-03):** Resolved the two canonical-boundary ambiguities flagged at 1.0.0, per Founder-approved clarification: (1) canonical decimal representation is strict — `"10.2"` and `"10"` are not canonical for MYR and must be rejected, distinct from a permitted separate normalization layer; (2) canonical currency identifiers are uppercase ISO 4217 form only — `"myr"`/`"Myr"` must be rejected. MON-T025 and MON-T092 are now normative rather than flagged; the golden tables (§19) gained the `"10.20"` valid case, the `"10"` and currency-identifier-case invalid cases. No test ID was added, removed, or renumbered, and no `MON-NNN` traceability mapping changed.
