# ADR-0007: Money Representation Strategy

- Status: Accepted
- Date: 2026-09-03
- Deciders: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Owners: Accounting Core; Invoicing; Banking and Reconciliation; Reporting; Contracts
- Related: [`ENGINEERING_BLUEPRINT.md`](../../ENGINEERING_BLUEPRINT.md), [`HORE_MY_PROJECT_INSTRUCTIONS.txt`](../product/reference/HORE_MY_PROJECT_INSTRUCTIONS.txt), [`HORE_MY_MASTER_CONTEXT.md`](../product/reference/HORE_MY_MASTER_CONTEXT.md), SRS requirements LED-002 and INV-004 and section 5.1; [ADR-0004](0004-financial-integrity-principles.md)

## Context

hore.my must calculate, post, reconcile, display, and export MYR values exactly. Binary floating-point types cannot represent many decimal fractions exactly and can introduce silent rounding and reconciliation differences. The authoritative references permit two exact strategies: integer minor units or controlled `NUMERIC`/`DECIMAL`. They prohibit binary floating point and require currency plus minor-unit scale, with MYR using two decimal places.

The references do not choose one permitted representation over the other. This ADR therefore records the permitted strategy and shared invariants without inventing a narrower implementation decision. The Accounting Core design must make the narrower choice before financial schema and arithmetic APIs are implemented.

This ADR does not decide database precision/scale, integer width, in-memory value-object design, API serialization, rounding modes by operation, tax allocation algorithms, display formatting, or whether different boundaries may use different exact representations.

## Decision drivers

- Guarantee deterministic, exact financial arithmetic and equality checks.
- Keep debit/credit balance and reconciliation accurate to the required unit.
- Preserve amount precision through storage, calculation, APIs, reports, and exports.
- Represent currency and scale explicitly.
- Avoid choosing between two approaches that the authoritative sources both permit.

## Considered options

1. Integer minor units with explicit currency and scale.
2. Controlled PostgreSQL `NUMERIC`/language decimal representation with explicit precision, scale, and rounding rules.
3. IEEE-754 binary floating-point representation.
4. Permit either exact option now and defer the narrower system-wide choice to Accounting Core design.

## Decision

All monetary operations and persisted monetary values must use one of these exact representations:

- **integer minor units**, paired with explicit currency and minor-unit scale; or
- **controlled `NUMERIC`/`DECIMAL`**, with explicit precision, scale, validation, and deterministic rounding policy.

Binary floating-point types are prohibited for monetary storage, calculation, comparison, aggregation, posting, reconciliation, contract values, reports, and exports. A float received from an untrusted boundary cannot enter financial logic without validation and conversion from an authoritative decimal textual representation.

Every amount carries an explicit currency. MVP financial operations use MYR, whose normal minor-unit scale is two decimal places. Currency and scale assumptions must not be inferred silently. Arithmetic, tax, discounts, allocations, totals, reversals, conversions between permitted exact types, serialization, and formatting must be deterministic and tested.

The narrower choice between integer minor units and controlled `NUMERIC`/`DECIMAL` is **explicitly deferred** until the Accounting Core design requires it. *[Resolved 2026-09-03 — see Amendment below.]* That design must select and document:

- canonical domain and database representations;
- allowed precision, scale, range, and overflow behavior;
- parsing and serialization rules at every boundary;
- operation-specific rounding modes and rounding points;
- tax, discount, allocation, and remainder handling;
- database constraints and migration compatibility; and
- whether any conversion between permitted exact representations is allowed.

The deferred decision must be recorded in a later ADR before the financial schema or Accounting Core arithmetic contract is implemented. It may choose either currently permitted strategy, or a rigorously defined combination at boundaries, but cannot introduce binary floating point. *[This instruction is fulfilled — see Amendment below.]*

## Consequences

### Positive

- The non-negotiable exactness rule is established without overruling the references.
- Ledger balance, invoice totals, reports, exports, and reconciliation share determinism requirements.
- Accounting Core retains room to select the exact representation based on validated schema and arithmetic needs.

### Negative

- Financial schema and arithmetic implementation cannot begin until the narrower design decision is accepted.
- Interfaces involving money cannot be finalized solely from this ADR.
- Supporting more than one exact representation at boundaries may require carefully tested conversions if later approved.

### Risks and mitigations

- **Risk:** A library or JSON parser converts money to binary float. **Mitigation:** Represent boundary amounts in an exact documented form, validate contracts, and add static and runtime checks.
- **Risk:** Inconsistent scale or rounding causes one-cent drift. **Mitigation:** Centralize the later-approved policy and verify it with property and golden tests.
- **Risk:** Overflow or excessive decimal scale is silently truncated. **Mitigation:** Define ranges and reject out-of-contract values explicitly in the follow-up ADR.
- **Risk:** Different modules choose incompatible representations before the deferred decision. **Mitigation:** Block financial schema and arithmetic contracts until the Accounting Core ADR is accepted.

## Validation

- Static analysis and review find no binary floating-point money fields or operations.
- Unit and property-based tests cover arithmetic, rounding, tax, discounts, allocation, reversal neutrality, overflow, and invalid scale.
- Golden datasets prove invoice totals, journals, trial balance, reconciliation, UI values, and exports agree exactly.
- Contract and integration tests prove serialization and database round trips lose no precision.
- Reconciliation acceptance requires RM0.00 unexplained difference and every journal requires exact debit/credit equality.

## Rollout and rollback

Before Accounting Core schema or arithmetic implementation, a follow-up ADR must resolve the deferred canonical representation and detailed policies. *[Fulfilled 2026-09-03 — see Amendment below.]* Migrations must reject or safely transform values that cannot be represented exactly and must be tested with production-like data.

Once financial data exists, a representation change requires a superseding ADR, exact reversible conversion or verified roll-forward strategy, full-ledger reconciliation, golden-dataset validation, and backup/recovery evidence. No rollback may round or discard committed value.

## Compliance

Exact representation and reproducible calculation support auditability, financial traceability, and faithful exports. Currency remains MYR for MVP; this ADR does not introduce multi-currency scope. Any tax or statutory rounding rules must be verified against then-current authoritative Malaysian sources before implementation and recorded separately.

## Amendment — 2026-09-03: Canonical persistence representation resolved

- **Authority:** Founder-approved decision.
- **Effective date:** 2026-09-03.
- **Reason:** This ADR originally permitted either integer minor units or controlled `NUMERIC`/`DECIMAL` and explicitly deferred the narrower choice between them "until the Accounting Core design requires it," instructing that "a follow-up ADR must resolve the deferred canonical representation... [b]efore Accounting Core schema or arithmetic implementation." Accounting Core implementation has not yet begun; this amendment fulfills that instruction now, ahead of it, exactly as the original ADR required.
- **Status of the original ADR:** The original Context, Decision, Consequences, Risks, Validation, Rollout, and Compliance sections above remain the historical record of the initial decision and are preserved unchanged. Every principle they establish — exact representation only, binary floating point prohibited without exception, explicit currency and scale, MYR at two decimal places for MVP — remains in force and is not altered by this amendment. Only the single point those sections explicitly left open (the narrower choice between the two permitted representations) is resolved below.

### Decision

The canonical Money representation is now settled across three distinct layers. This separation — domain, boundary, and persistence — is itself part of the decision: each layer is independently governed, and a future change to one does not require reopening the others.

**Domain**

- hore.my owns its own Money contract: a domain value object, not a direct alias for any third-party library's type.
- Currency is a hore.my-owned value object and is the sole owner of a currency's minor-unit scale — scale is never an independent fact paired arbitrarily with an amount.
- MinorUnits is a dedicated hore.my-owned value object representing an exact integer minor-unit amount, distinct from a bare string or a bare native integer.
- Money, Currency, and MinorUnits remain fully **persistence-agnostic**: none of them may know, branch on, or be designed around whether the underlying database column is `BIGINT`, `NUMERIC`, or anything else.
- Whichever underlying arithmetic library or mechanism is eventually selected to implement this contract, its vendor-specific types must never escape the Money wrapper into any public hore.my domain signature.

**Boundary**

- AI, OCR, and every other untrusted financial input source represents a monetary amount as a canonical decimal string paired with an explicit currency — never as a raw float, and never as a pre-scaled integer computed by the untrusted source itself.
- Conversion from that boundary representation into the domain Money type happens only through deterministic, schema-validated parsing performed by Accounting Core — never accepted as already-authoritative.
- Binary floating point remains prohibited at this boundary exactly as everywhere else; this restates the original ADR's absolute prohibition as it applies specifically to untrusted input, and introduces no exception to it.

**Persistence**

- Canonical Money persistence is **PostgreSQL `BIGINT` storing integer minor units**.
- Every monetary database row stores its currency **explicitly** — a validated ISO 4217 code — and never inherits currency implicitly from a tenant or another aggregate.
- Minor-unit scale is **never stored as a column on a monetary row**; it is always derived from that row's stored currency.
- The persistence adapter is solely responsible for validating that a value fits within the signed 64-bit range **before** it is written to a `BIGINT` column. This bounds check is mandatory and must fail loudly — never truncate or wrap silently — if violated.
- Raw, human-readable audit access to monetary values (for example, displaying `RM10.25` rather than `1025`) is provided through read models, database views, or the reporting layer built on top of this canonical storage — never by changing what the canonical storage itself is.

### What remains deferred

This amendment resolves only the representation question the original ADR left open. It does not decide, and these remain deferred to their own future work:

- **Money sign policy** — whether a Money value may itself be negative, or is an inherently non-negative magnitude with direction supplied by context (e.g. a Journal Line's debit/credit side) — deferred to the future Journal & Posting Model design.
- **Tax and rounding business policy** — which rounding modes apply to which operations, and any statutory rounding rules — deferred, and in any case requires verification against then-current authoritative Malaysian sources per this ADR's original Compliance section.
- **Journal and Posting Engine design** — schema, transaction mechanics, and concurrency handling — deferred to future AETS work.
- **The full Money domain value object contract** (exact public API of Money, Currency, and MinorUnits) — this amendment settles what those types must guarantee and must never expose, not their literal method-by-method design, which remains future AETS work.

### Validation

This amendment does not change or relax the validation requirements the original ADR already specifies (static analysis for binary-float absence, property-based arithmetic and rounding tests, golden-dataset reconciliation, contract/round-trip tests). It adds one requirement specific to persistence: an automated test must prove the persistence adapter rejects, rather than silently truncates or wraps, a value that would exceed `BIGINT`'s signed 64-bit range before any write is attempted.

### Rollout and rollback

This amendment is a governance decision, not an implementation. No Money code, database migration, or Composer/Docker dependency change is introduced by it. Implementation may now proceed against this resolved representation; the original ADR's rollback rule — that a representation change after financial data exists requires a superseding ADR, exact reversible conversion or verified roll-forward, full-ledger reconciliation, golden-dataset validation, and backup/recovery evidence, with no rollback permitted to round or discard committed value — applies to this resolved representation exactly as it would have applied to either originally-permitted option.

### Compliance

Unchanged from the original ADR. This amendment introduces no new compliance obligation and no product scope beyond what the original ADR and the authoritative project references already establish.
