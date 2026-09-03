# AETS-003: Money Specification

- Status: Active
- Version: 1.0.1
- Effective date: 2026-09-03
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-000](AETS-000.md), [AETS-001](AETS-001-Accounting-Terminology.md), [AETS-002](AETS-002-Accounting-Invariants.md); [ADR-0001](../../adr/0001-modular-monolith-architecture.md), [ADR-0004](../../adr/0004-financial-integrity-principles.md), [ADR-0005](../../adr/0005-ai-provider-abstraction.md), [ADR-0006](../../adr/0006-transactional-outbox-pattern.md), [ADR-0007](../../adr/0007-money-representation-strategy.md) (as amended)

## 1. Purpose

This document is the normative specification for hore.my's Money domain model: the `Money`, `Currency`, and `MinorUnits` value objects, their public contracts, their persistence mapping, and the invariants that govern all of them. It formalizes, as a governing specification rather than a design review, the decisions already finalized in [ADR-0007](../../adr/0007-money-representation-strategy.md)'s Founder-approved amendment and the internal design-review series that preceded it (referred to collectively below as "the prior Money review").

This is the document [AETS-000 §10](AETS-000.md#10-planned-document-structure) and [AETS-001 §8](AETS-001-Accounting-Terminology.md) pointed to as still-future work — "formalizing the Money domain value object contract." It exists so that Accounting Core implementation has one precise, testable specification to build against, rather than a decision recorded only in ADR-0007's amendment and prior review conversations.

## 2. Scope

### 2.1 In scope

- The `Money`, `Currency`, and `MinorUnits` value objects: what they contain, what they guarantee, and what they must never expose.
- Construction, parsing, and validation of Money at trusted and untrusted boundaries.
- The public behavioral contract for every Money operation named in [AETS-2.2/2.3's prior review](#3-authority): construction, conversion, arithmetic, comparison, and equality.
- Persistence mapping to PostgreSQL, and the adapter obligations that mapping requires.
- Vendor-library isolation rules.
- Exception and failure semantics for every Money operation.
- Money-specific invariants (§20), each assigned a stable `MON-NNN` identifier.
- Requirements for the future Money test specification (§24).

### 2.2 Out of scope

Per this task's explicit constraints, this document does **not**:

- design a Journal, a Journal Line, or the Posting Engine;
- design the Chart of Accounts;
- decide whether Money permits negative values (Money sign policy) — this remains deferred to the future Journal & Posting specification, exactly as ADR-0007's amendment already states;
- decide tax policy or any business-specific rounding policy, including which `RoundingMode` values exist or which operation uses which mode;
- introduce code, a database migration, a Composer dependency, or a Docker change; and
- modify ADR-0007 — no contradiction with it was found (§23).

## 3. Authority

This specification is subordinate to the authority hierarchy in [AETS-000 §3](AETS-000.md#3-authority-hierarchy) and implements, without contradicting, [ADR-0007](../../adr/0007-money-representation-strategy.md) as amended. Where anything below appears to conflict with ADR-0007, the ADR governs and this document must be corrected (per [AETS-000 §8.4](AETS-000.md#84-relationship-to-adrs-and-conflict-handling)).

This document also formalizes conclusions reached during an internal Money design-review series conducted prior to ADR-0007's amendment (covering the Money domain model, PHP representation options, library selection, and the final persistence decision). That review was not itself published as an AETS document; this specification is its formal successor and supersedes it as the authoritative source for everything within its scope.

## 4. Dependencies

- **[ADR-0007](../../adr/0007-money-representation-strategy.md)** (as amended) — source of the persistence decision (§15) and the domain/boundary/persistence layering this document elaborates.
- **[AETS-000](AETS-000.md)** — authority hierarchy, document governance, and versioning rules this document follows.
- **[AETS-001](AETS-001-Accounting-Terminology.md)** — canonical terminology; this document uses "Money," "Currency," "Tenant," and other AETS-001 terms with their AETS-001 meaning throughout.
- **[AETS-002](AETS-002-Accounting-Invariants.md)** — the 14 financial integrity invariants; this document's `MON-NNN` invariants (§20) are additional to, and must not contradict, AETS-002's invariants.
- **`brick/money`** (and its `brick/math` dependency) is the approved internal library strategy for implementing the contracts specified here, per the prior Money review. **This document does not install it.** No Composer, Docker, or code change results from this document; adding the dependency is future implementation work.

## 5. Normative Language

This document uses **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT**, and **MAY** exactly as defined in [`ENGINEERING_BLUEPRINT.md`](../../../ENGINEERING_BLUEPRINT.md) §1.

Within this document, the capitalized terms **Money**, **Currency**, and **MinorUnits** refer specifically to the hore.my domain types this document defines. Lowercase "money," "currency," and "minor units" refer to the general accounting concepts, as defined in [AETS-001](AETS-001-Accounting-Terminology.md).

## 6. Money Domain Model

Money is a domain Value Object composed of an exact monetary quantity and a Currency. It is the sole authoritative in-application representation of a monetary amount; nothing in Accounting Core or any dependent module represents money any other way.

- Money **MUST** be immutable — no operation on a Money instance changes it; every operation that produces a new value returns a new Money instance.
- Money **MUST** contain exactly an exact monetary quantity and a Currency, and nothing else that is not derivable from those two facts.
- Money **MUST NOT** expose any `Brick\...` (or other vendor library) type from any public method signature, property, or return value.
- Money **MUST NOT** contain presentation-formatting metadata (locale, symbol placement, thousands-separator style, or any display concern).
- Money **MUST NOT** contain database precision metadata (total digit count, column scale, or any storage-column concept) — persistence characteristics are entirely a persistence-adapter concern (§15).
- Money **MUST** reject arithmetic or comparison between two Money values of different Currency, unless and until an explicitly authorized future currency-conversion domain is introduced by its own specification. No such domain exists today; this document introduces none.

Money's internal representation (how the exact quantity is held in memory) is intentionally unspecified by this document at the implementation-syntax level — that is implementation detail behind the contract in §10, consistent with Money remaining persistence-agnostic (§15) and vendor-isolated (§16).

## 7. Currency Specification

Currency is a Value Object identifying a monetary unit and owning that unit's canonical minor-unit scale.

- Currency **MUST** be identified by an explicit canonical currency identifier (an ISO 4217 alphabetic code, e.g. `MYR`).
- Currency **MUST** own the canonical minor-unit scale for that identifier (a non-negative integer count of decimal places) — scale is a property of Currency, never an independently suppliable fact.
- A Currency instance **MUST NOT** be constructible with an identifier paired with a scale inconsistent with that identifier's canonical scale. Currency's constructor accepts an identifier and derives the correct scale from a canonical reference; it **MUST NOT** accept an arbitrary, independently chosen scale for a known identifier.
- **MVP MUST support `MYR`, whose scale is `2`.** No other currency identifier is required to be supported for MVP, and this document does not expand MVP scope to multi-currency.
- The Currency type's *shape* (identifier + owned scale) **MUST** remain currency-agnostic — nothing in Currency's structure may hard-code "only MYR can ever exist." Which identifiers are currently valid/supported is an application-level validation concern (§18), not a structural limitation of the type itself. This is what "structurally future-ready" means in this document: the type does not need to change shape to support a second currency later; only the set of currently-accepted identifiers would need to grow.
- Two Currency instances **MUST** be equal if and only if they share the same canonical identifier.

## 8. MinorUnits Specification

MinorUnits is a narrow, dedicated Value Object representing an exact integer numeral — the count of a currency's minor units — and nothing else.

- MinorUnits **MUST** represent an exact integer numeral of arbitrary magnitude; it **MUST NOT** be bounded by, or lose precision at, native PHP integer range internally.
- MinorUnits **MUST NOT** expose arithmetic or other business behavior that duplicates Money — it is a value carrier, not a second Money type. Addition, subtraction, multiplication, division, allocation, and rounding are Money operations (§11, §13), never MinorUnits operations.
- MinorUnits **MUST NOT** expose a native-int canonical accessor. No method on MinorUnits returns a native PHP `int` as its canonical representation.
- MinorUnits **MUST** support an exact string representation (a numeral string, arbitrary magnitude, lossless) as its canonical output form.
- MinorUnits **MUST** remain a narrow type-safety Value Object: immutable, carrying no Currency, no formatting behavior, and no method beyond construction, exact-string output, and value equality against another MinorUnits.
- Two MinorUnits instances **MUST** be equal if and only if they represent the same exact integer numeral.

MinorUnits carries no inherent currency meaning on its own — "1025 minor units" is only meaningful once paired with a Currency, which is exactly what Money does (§6).

## 9. Construction and Parsing

This section specifies the validation rules governing how an external, untrusted decimal string becomes a Money value. It governs `fromDecimalString` (§10); it does not repeat that method's full behavioral contract.

**Canonical decimal string grammar.** For a given Currency of scale *s*, a canonical decimal string consists of: an optional leading minus sign; one or more ASCII decimal digits; and, if and only if *s* is greater than zero, exactly one decimal point followed by exactly *s* digits. No other character — no whitespace, no thousands separator, no currency symbol, no locale-specific separator, no scientific notation — is part of the canonical grammar.

Parsing **MUST**:

- treat the canonical decimal string as the sole authoritative external representation of an untrusted monetary amount, per [ADR-0007](../../adr/0007-money-representation-strategy.md)'s "authoritative decimal textual representation" requirement;
- **reject** any input presented as a native binary float rather than a string — this is a type-level rejection, before grammar validation even begins;
- **reject** any string that does not conform exactly to the canonical grammar above (malformed decimal strings);
- **reject** locale-formatted or otherwise ambiguous numeric forms (comma decimal separators, thousands separators, currency symbols, surrounding whitespace) — `fromDecimalString` itself performs no locale normalization; if a given input source ever requires normalization, that normalization **MUST** happen in a separately specified, deterministic normalization step that produces a canonical decimal string *before* `fromDecimalString` is invoked, never implicitly inside it;
- **reject** scientific notation (e.g. `1.025e1`) unless and until a future specification explicitly authorizes it;
- **reject** a string whose number of digits after the decimal point exceeds the target Currency's scale (over-precision) — parsing **MUST NOT** silently round such input to fit; and
- be **deterministic**: the same input string against the same Currency **MUST** produce the same result, or the same failure, on every invocation, independent of locale, system time, or any non-deterministic state.

## 10. Public Money Contract

This section specifies normative behavior only — not method syntax, parameter order, or implementation language constructs.

- **`fromDecimalString`** — constructs a Money from a canonical decimal string and a Currency, applying every rule in §9. On any violation, it **MUST** produce a typed failure (§17), never a partially-valid Money.
- **`fromMinorUnits`** — constructs a Money from an already-exact MinorUnits value and a Currency. Because MinorUnits is already exact, this construction path **MUST** succeed for any valid MinorUnits and Currency pair; it introduces no additional precision-loss risk.
- **`toDecimalString`** — produces the canonical decimal string (§9 grammar) representing the Money's exact value. This direction is always lossless for a validly constructed Money and **MUST NOT** fail, and **MUST NOT** accept any formatting or locale parameter (formatting is out of scope for Money, §6).
- **`toMinorUnits`** — produces the Money's exact value as a MinorUnits instance. This direction is always lossless and **MUST NOT** fail for a validly constructed Money. This method **MUST NOT** return a native PHP `int` (§8).
- **`add`** — combines two Money values of the same Currency into their exact sum. **MUST** apply the cross-currency guard (§6, `MON-006`). Addition of two same-scale exact quantities never requires rounding.
- **`subtract`** — combines two Money values of the same Currency into their exact difference, symmetric with `add`. **MUST** apply the cross-currency guard. **This specification does not state what happens when the mathematical result would be negative** — that behavior depends entirely on the still-deferred Money sign policy (§25) and is intentionally left unresolved here rather than answered by implication.
- **`multiply`** — multiplies a Money value by an exact, non-Money scalar. The scalar **MUST** be an exact type (a numeral string or another hore.my-owned exact type) and **MUST NOT** be a native float. This operation **MUST** require an explicit, caller-supplied, hore.my-owned rounding decision (§13) whenever the mathematically exact result cannot be represented at the Money's Currency scale; when the exact result already fits, no rounding is actually applied, but the rounding-decision parameter is still required for contract uniformity.
- **`divide`** — divides a Money value by an exact, non-Money scalar, with the same scalar-exactness and mandatory-rounding-decision requirements as `multiply`. Division by zero **MUST** produce a typed failure (§17), never an engine-level error, warning, or infinite/NaN-equivalent result.
- **`compare`** — produces a three-way ordering result (less than / equal to / greater than) between two Money values of the same Currency. **MUST** apply the cross-currency guard.
- **`equals`** — produces a boolean: true if and only if both Money values have the same exact quantity and the same Currency. Unlike `compare`, `add`, and `subtract`, **`equals` MUST NOT throw on currency mismatch** — comparing Money of different Currency for equality is always a meaningful, always-answerable question, and **MUST** simply return `false`.

No method specified in this section accepts or returns a native PHP `float` in any position, at any point.

## 11. Arithmetic Rules

- Every arithmetic operation (`add`, `subtract`, `multiply`, `divide`) **MUST** operate only on exact inputs — Money, MinorUnits, or an exact scalar type. None **MUST** accept a native float anywhere in its input.
- `add` and `subtract` **MUST** be exact for any two same-Currency Money values — same-scale addition/subtraction of exact quantities never loses precision and therefore never requires a rounding decision.
- `multiply` and `divide` **MUST** treat any precision beyond the Money's Currency scale as requiring an explicit rounding decision (§13) — never resolved by an implicit default.
- Every arithmetic operation **MUST** be side-effect-free with respect to its operands (immutability, §6) and **MUST** return a new Money instance (or a typed failure, §17) rather than mutating an existing one.

## 12. Equality and Comparison

- Money equality is value equality over the pair (exact quantity, Currency) — not identity, and not equality of any underlying vendor-library representation.
- Money comparison (`compare`) is only defined between Money values of the same Currency and **MUST** fail with a typed exception across different Currency, per the cross-currency guard (§6).
- Currency equality (§7) and MinorUnits equality (§8) are both value equality over their respective sole represented fact (identifier; exact numeral).
- No equality or comparison operation specified in this document relies on native PHP `==`, `<=>`, or default object-identity semantics — each is an explicit, named, value-based operation.

## 13. Rounding Contract

- hore.my **MUST** own its own `RoundingMode` type, distinct from any vendor library's rounding-mode type — no vendor `RoundingMode` may appear in any public Money signature (§16).
- Any operation whose exact mathematical result cannot be represented at the applicable Currency's scale **MUST** require an explicit `RoundingMode` argument from the caller; it **MUST NOT** apply a hidden or implicit default rounding behavior.
- **This document does not enumerate which `RoundingMode` values exist, and does not decide which mode applies to which business operation (tax, invoicing, allocation, or any other scenario).** Both the mode vocabulary and the mode-to-scenario mapping are deferred (§25), consistent with this task's explicit constraint against defining tax-specific or business-specific rounding policy.
- A future specification defining hore.my's `RoundingMode` member set and its application per business operation **MUST NOT** weaken any requirement in this section — rounding **MUST** remain explicit at every call site regardless of how many modes eventually exist.

## 14. Serialization and Boundary Representation

- At every boundary crossing a trust boundary — AI/OCR output, an external API request or response, an untrusted import — a Money value **MUST** be represented as its canonical decimal string (§9) paired with its Currency's canonical identifier, never as a raw number.
- This applies regardless of the underlying transport's native number type: a Money amount **MUST NOT** be placed into a raw JSON numeric field, because a consuming JSON parser (including a JavaScript/Nuxt frontend's `JSON.parse`) is not guaranteed to preserve exact decimal or large-integer values.
- Deserializing a boundary representation back into a Money **MUST** go through the same `fromDecimalString` construction and validation path (§9, §10) as any other untrusted input — a boundary payload is never treated as pre-validated merely because it was previously produced by hore.my itself.

## 15. Persistence Mapping

This section restates, as binding specification, the persistence decision [ADR-0007](../../adr/0007-money-representation-strategy.md)'s amendment already made.

- Canonical Money persistence **MUST** use PostgreSQL `BIGINT` storing the Money's MinorUnits value.
- Every monetary database row **MUST** store its Currency explicitly (a validated ISO 4217 identifier) — currency **MUST NOT** be inherited implicitly from a tenant or another aggregate.
- Minor-unit scale **MUST NOT** be stored as a column on any monetary row — it **MUST** always be derived from that row's stored Currency (§7).
- The persistence adapter **MUST** validate, before every write, that the MinorUnits value fits within a signed 64-bit integer range. A value outside that range **MUST** fail loudly (a typed exception, §17) before any write is attempted.
- Persistence **MUST NOT**, at any point in either direction, narrow, truncate, wrap, silently round, or convert to a native float any monetary value.
- Money, Currency, and MinorUnits **MUST NOT** know the database representation — the persistence adapter is the only code with knowledge of both the domain contract and the `BIGINT` column it maps to (consistent with `ENGINEERING_BLUEPRINT.md` §4.1's domain/infrastructure dependency direction).
- Raw, human-readable audit access to monetary values **MUST** be provided through read models, database views, or the reporting layer built on top of this canonical `BIGINT` storage — never by changing what the canonical storage itself is.

## 16. Vendor Isolation

- `brick/money` and `brick/math` types **MUST** be treated as private implementation details of whichever hore.my class(es) implement Money, Currency, and MinorUnits — never as public types themselves.
- hore.my **MUST** own Money, Currency, MinorUnits, `RoundingMode`, and every domain exception type this specification requires (§17). None of these **MUST** be, extend, or type-alias a vendor class.
- Any exception a vendor library raises internally **MUST** be caught and translated into a hore.my-owned domain exception before it can propagate to any caller — a caller of Money **MUST NOT** ever need to catch a `Brick\...` exception type.
- No `Brick\...` namespace type **MUST** appear in the signature of any public domain or application interface, anywhere in hore.my's codebase, not only within the Money implementation itself.

## 17. Exception and Failure Semantics

Every Money operation specified in this document **MUST** produce exactly one of:

1. an exact, valid result; or
2. a typed, deterministic failure.

There **MUST** be no third outcome. In particular, there **MUST** be no silent:

- rounding,
- truncation,
- overflow,
- currency conversion,
- scale conversion,
- conversion to a native float, or
- fallback to a default or best-guess value.

hore.my **MUST** define distinct, typed failure categories for at least:

- malformed or over-precision decimal string input (§9);
- cross-currency operation attempted where a common Currency is required (§6, §11, §12);
- division by zero;
- an arithmetic result requiring rounding where no `RoundingMode` was supplied (§13);
- a MinorUnits value that does not fit within the persistence adapter's signed 64-bit bound (§15); and
- a vendor-library exception translated per §16.

Each failure category **MUST** be distinguishable by callers (e.g. by type), not merged into one generic error, so that a caller can respond appropriately (reject, request clarification, or escalate) rather than treating every failure identically.

## 18. Security and Validation

- Every Money value entering the system from a boundary **MUST** pass through validated, deterministic parsing (§9) before it is treated as authoritative anywhere in financial logic — this restates [ADR-0007](../../adr/0007-money-representation-strategy.md)'s existing requirement as it applies specifically to Money construction.
- `fromDecimalString` **MUST** enforce a bounded input length before attempting to parse it, as a basic defense against resource-exhaustion from adversarially large or malformed input — this is a general input-hygiene requirement, not a business rule.
- No Money operation **MUST** execute dynamic code, dynamic evaluation, or any operation that treats input content as executable, at any point.
- Currency identifier validation (§7) **MUST** reject any identifier outside the currently-supported set (MYR for MVP) — an unsupported identifier is a construction failure (§17), not a silently accepted value.
- This document does not introduce a tenant concept on Money itself — Money has no inherent Tenant. Tenant ownership (per [AETS-001](AETS-001-Accounting-Terminology.md)'s Tenant term and [AETS-002](AETS-002-Accounting-Invariants.md) invariant 11) is a property of the record that holds a Money value, not of Money itself.

## 19. Audit and Traceability Requirements

- A Money value reconstructed from persisted MinorUnits and Currency **MUST** be exactly equal (§12) to the Money value originally posted — no information may be lost across a write/read cycle.
- A rejected construction attempt at a boundary (§9, §17) **MUST** be capturable with enough detail (the failure category, and the rejected input where retention policy permits) to support audit review — this document does not design the audit-event mechanism itself, which remains [AETS-002](AETS-002-Accounting-Invariants.md) invariant 12's and a future Audit Trail specification's responsibility, but a rejected Money construction **MUST NOT** be silently discarded with no trace.
- Every exact value this specification guarantees (§9–§13) directly supports [AETS-002](AETS-002-Accounting-Invariants.md) invariant 12 (complete auditability) and invariant 5 (ledger-derived truth, rebuildable projections) — Money's exactness is a precondition for both, not a separate concern.

## 20. Invariants

Each invariant below is Money-specific, additional to [AETS-002](AETS-002-Accounting-Invariants.md)'s 14 invariants, and testable.

| ID | Invariant |
| --- | --- |
| MON-001 | **No binary float.** Money, Currency, and MinorUnits MUST NOT accept, produce, or internally rely on a native binary floating-point value at any point. |
| MON-002 | **Exact construction.** Money MUST be constructible only via `fromDecimalString` (validated per §9) or `fromMinorUnits` (already exact) — never from an unvalidated or inexact source. |
| MON-003 | **Immutable Money.** Once constructed, a Money, Currency, or MinorUnits instance MUST NOT be mutated; every operation returns a new instance. |
| MON-004 | **Explicit Currency.** Every Money value MUST carry an explicit Currency; a Money value with no Currency MUST NOT be constructible. |
| MON-005 | **Currency owns scale.** Money MUST NOT carry an independent scale; the applicable scale MUST always be obtained from the Money's Currency. |
| MON-006 | **Cross-currency guard.** Any arithmetic or ordering-comparison operation between Money values of different Currency MUST fail with a typed exception rather than silently converting or ignoring the mismatch. |
| MON-007 | **Deterministic decimal parsing.** `fromDecimalString` MUST produce the same result or the same failure for the same input on every invocation, independent of locale or system state. |
| MON-008 | **Exact MinorUnits round-trip.** For any validly constructed Money, converting to MinorUnits and back to a decimal string MUST reproduce the original decimal value exactly. |
| MON-009 | **Persistence bounds checking.** The persistence adapter MUST reject, before write, any MinorUnits value outside the signed 64-bit integer range, without truncating, wrapping, or narrowing it. |
| MON-010 | **No vendor-type leakage.** No public Money, Currency, or MinorUnits method, and no public application interface, MUST accept or return a vendor library type. |
| MON-011 | **Exact-or-fail arithmetic.** Every arithmetic operation MUST produce either an exact result at Currency scale or a typed failure — never a silently inexact result. |
| MON-012 | **Serialization round-trip.** Serializing a Money value to its boundary representation and deserializing it back MUST reproduce a Money value equal to the original. |
| MON-013 | **MinorUnits type-safety.** A MinorUnits value MUST only ever represent a validated exact integer numeral, and MUST NOT be interchangeable with an arbitrary or decimal-formatted string without explicit, validated conversion. |
| MON-014 | **No native-int canonical accessor.** Money and MinorUnits MUST NOT expose a public method whose sole purpose is returning a native PHP `int` as the canonical representation of an amount. |
| MON-015 | **Explicit rounding requirement.** Any `multiply` or `divide` whose exact result cannot be represented at Currency scale MUST require an explicit, caller-supplied `RoundingMode`; no hidden default MUST apply. |

## 21. Prohibited Operations

The following are explicitly forbidden, without exception, anywhere Money, Currency, or MinorUnits is used:

- Float arithmetic on any value representing money, at any layer.
- Treating a raw integer (unwrapped by MinorUnits or Money) as if it were Money.
- Implicit currency conversion of any kind — every conversion, if one is ever introduced, must be an explicit, separately specified operation.
- Silent rounding — any rounding not driven by an explicitly supplied `RoundingMode` (§13).
- Silent truncation of precision, magnitude, or scale, anywhere in construction, arithmetic, serialization, or persistence.
- A vendor Money object (or any other vendor-library monetary type) crossing any domain or application interface boundary (§16).
- Treating a raw database value as Money without passing it through validated reconstruction (§9, §15) — a `BIGINT` column value is not a Money until reconstructed via `fromMinorUnits` with its row's Currency.
- Formatting logic (locale, symbol, display) inside Money, Currency, or MinorUnits (§6).
- Storing minor-unit scale redundantly as a column on a monetary database row (§15).

## 22. Examples (Informative)

**These examples are illustrative only and are not normative.** They do not decide rounding-mode-to-scenario policy or any other deferred item.

- **Canonical round-trip (MYR):** the decimal string `"10.25"` corresponds to a MinorUnits value of `"1025"`. Converting either direction reproduces the other exactly.
- **Valid parsing:** `fromDecimalString("10.25", MYR)` succeeds, because `"10.25"` conforms to MYR's canonical grammar (scale 2).
- **Invalid — over-precision:** `fromDecimalString("10.255", MYR)` fails — three digits after the decimal point exceeds MYR's scale of 2; the value is rejected, not rounded to `"10.25"` or `"10.26"`.
- **Invalid — locale-ambiguous form:** `fromDecimalString("10,25", MYR)` fails — a comma decimal separator is not part of the canonical grammar and is not normalized implicitly.
- **Cross-currency rejection:** attempting `add` between a MYR Money and a hypothetical USD Money fails with a typed cross-currency exception — no conversion is attempted.
- **Exact addition:** `RM10.25 + RM5.00 = RM15.25` — exact, no rounding decision required.
- **Rounding-required division:** `RM10.00 ÷ 3` has no exact result at MYR's 2-decimal scale (`RM3.333...`); this operation requires an explicit `RoundingMode` argument to resolve to a 2-decimal Money value. Which mode produces which result is intentionally not specified here (§13, §25).
- **Persistence bounds failure:** a MinorUnits value larger than `9223372036854775807` (the signed 64-bit maximum) — however it might arise — is rejected by the persistence adapter before any write is attempted, with a typed failure, never a truncated or wrapped value silently written.

## 23. Validation Requirements

The following checks were performed on this document before delivery:

- **Consistency with ADR-0007:** every persistence-layer requirement in §15, and the domain/boundary/persistence layering throughout, was checked against ADR-0007's amendment text; no contradiction was found, so ADR-0007 was not modified.
- **Consistency with AETS-000:** this document's authority framing (§3), governance approach (§26), and dependency on AETS-000's rules were checked against [AETS-000 §3, §8, §9](AETS-000.md); no contradiction was found.
- **Consistency with AETS-001:** every use of an AETS-001-defined term (Money, Currency, Tenant, and others) in this document uses that term's AETS-001 meaning; no redefinition was introduced (per [AETS-001 §4](AETS-001-Accounting-Terminology.md#4-normative-terminology-rules), rule 6).
- **Consistency with AETS-002:** this document's `MON-NNN` invariants (§20) were checked against AETS-002's 14 invariants for contradiction; none was found — `MON-NNN` invariants are additional and Money-specific, not replacements.
- **Relative links:** every relative markdown link in this document was checked to resolve to an existing file (and, where used, an existing heading anchor).
- **No application code changed:** this document introduces no code, migration, Composer, or Docker change.
- **No unresolved BIGINT/NUMERIC language:** this document states the resolved decision (PostgreSQL `BIGINT`, integer minor units) throughout and does not present it as an open choice anywhere.
- **Deferred items not accidentally resolved:** Money sign policy, tax/business-specific rounding policy, and Journal/Posting Engine design are each explicitly named as still-deferred (§2.2, §10 under `subtract`, §13, §25) rather than answered by implication.

## 24. ATS Requirements

*"ATS" is used here as this task specified it — read as Accounting Test Specification, since the term is not yet defined in [AETS-001](AETS-001-Accounting-Terminology.md); see §25 and the Ambiguities discovered in this task's return.* This section states what a future Money ATS must prove; it does not write that test specification.

A Money ATS **MUST** include:

- **Property-based arithmetic tests** — proving `add`/`subtract` exactness and `multiply`/`divide` behave correctly across a wide, generated range of exact inputs, not just hand-picked examples.
- **Decimal ↔ minor-unit round-trip tests** — proving `fromDecimalString` → `toMinorUnits` → `fromMinorUnits` → `toDecimalString` reproduces the original value exactly, for every valid Currency scale.
- **Currency mismatch tests** — proving every operation the cross-currency guard (§6, `MON-006`) applies to fails correctly, and that `equals` alone does not throw.
- **Malformed boundary input tests** — proving every rejection rule in §9 (malformed strings, locale-ambiguous forms, scientific notation, non-string/float input) is actually enforced.
- **Over-precision rejection tests** — proving input with more decimal places than a Currency's scale is rejected, never silently rounded.
- **Overflow/bounds tests** — proving the persistence adapter's signed 64-bit bounds check (`MON-009`) rejects an out-of-range value before write, in both directions (construction from an oversized MinorUnits, and an internal calculation that would produce one).
- **Vendor isolation tests** — proving no `Brick\...` type is reachable from any public Money/Currency/MinorUnits signature (`MON-010`), ideally enforced by an automated architecture/static-analysis rule, not only by manual review.
- **Serialization round-trip tests** — proving `MON-012` across the actual boundary representation (decimal string + currency), not only the in-process representation.
- **Deterministic rounding behavior tests** — deferred in content until a future specification defines hore.my's `RoundingMode` member set (§13, §25), but the ATS **MUST** eventually prove that the same input and the same explicit `RoundingMode` always produce the same result.

## 25. Deferred Items

- **Money sign policy** — whether Money may itself be negative, or is an inherently non-negative magnitude with direction supplied by context — deferred to the future Journal & Posting specification. Not resolved by this document; `subtract`'s behavior for a would-be-negative result (§10) is explicitly left open pending this.
- **Tax and business-specific rounding policy** — which rounding modes exist and which operation uses which — deferred; §13 specifies only that a rounding decision must always be explicit, not what the decision options are.
- **Journal, Journal Line, and Posting Engine design** — out of scope entirely, per §2.2.
- **Chart of Accounts design** — out of scope entirely, per §2.2.
- **The Money ATS itself** — §24 states its required coverage; the test specification document is not written here.

## 26. Change Governance

This document follows [AETS-000](AETS-000.md)'s governance rules in full — it does not restate them. In particular: lifecycle (`Draft` → `Active` → `Superseded`/`Deprecated`, [AETS-000 §8.3](AETS-000.md#83-lifecycle)), review requirements (CTO/Technical Partner and Accounting Domain Reviewer for any material change; Founder review only where scope, cost, risk, or user experience changes, [AETS-000 §8.2](AETS-000.md#82-ownership-and-review)), and versioning (`MAJOR.MINOR.PATCH` with a changelog note for any `Active`-document change, [AETS-000 §9.1](AETS-000.md#91-per-document-version)) all apply unchanged.

A change to any `MON-NNN` invariant, or to any MUST-level requirement in §6–§17, is a MAJOR change under that rule. A change that only adds detail without altering a previously specified guarantee (for example, naming the `RoundingMode` member set once tax/rounding policy is decided) is MINOR.

## Changelog

- **1.0.1 (2026-09-03):** Removed two deferred items that were resolved by the AETS Documentation Alignment task: the AETS numbering conflict (AETS-000 §10's roadmap now correctly lists this document as AETS-003, Money Specification) and the `Currency`/`MinorUnits` terminology gap (both are now formally defined in [AETS-001](AETS-001-Accounting-Terminology.md)). No MUST-level requirement, invariant, or contract in this document changed.
