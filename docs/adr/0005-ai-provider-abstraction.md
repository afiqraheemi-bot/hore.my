# ADR-0005: AI Provider Abstraction

- Status: Accepted
- Date: 2026-09-03
- Deciders: Founder / Product Owner; CTO / Technical Partner
- Owners: AI Orchestration; Document Processing; Accounting Core; Security and Operations
- Related: [`HORE_MY_PROJECT_INSTRUCTIONS.txt`](../product/reference/HORE_MY_PROJECT_INSTRUCTIONS.txt), [`HORE_MY_MASTER_CONTEXT.md`](../product/reference/HORE_MY_MASTER_CONTEXT.md), SRS sections 7, 8, and 9; [ADR-0001](0001-modular-monolith-architecture.md); [ADR-0004](0004-financial-integrity-principles.md)

## Context

AI and OCR assist hore.my by interpreting supported instructions, extracting fields, classifying documents, proposing transactions or accounts, scoring confidence, and explaining rationale. The product is task-driven rather than open-ended chat. Financial integrity requires deterministic controls to remain authoritative even when model output is incorrect, unavailable, or changes between versions.

The authoritative sources require a versioned, provider-independent interface, structured output, strict schema validation, confidence policy, evaluation registry, regression gates, cost telemetry, context minimisation, and deterministic/manual fallback. They explicitly prohibit AI from writing directly to the ledger or holding repository/database permission to post journals.

This ADR decides the provider boundary and authority model. It does not select an AI or OCR vendor, model, SDK, routing algorithm, prompt format, confidence threshold, evaluation threshold, retention period, or commercial budget.

## Decision drivers

- Keep ledger authority deterministic and outside probabilistic systems.
- Replace or route providers without changing domain workflows.
- Validate every operational model output before it becomes a proposal.
- Minimize tenant and personal data disclosed to providers.
- Make model behavior reproducible, measurable, explainable, and cost-visible.
- Preserve useful manual workflows during provider outages.

## Considered options

1. Versioned provider abstraction with structured proposals and deterministic validation.
2. Direct use of one provider SDK throughout business modules.
3. Autonomous AI agents with direct ledger or database write access.

## Decision

All AI and OCR calls will pass through AI Orchestration using versioned, provider-independent interfaces. Business modules depend on hore.my-owned request and response contracts, not provider SDK types. Provider-specific adapters translate those contracts at the infrastructure boundary.

Operational output must be structured and valid against a strict, versioned schema. Invalid, unsupported, ambiguous, or insufficiently confident output cannot create an accounting command or record silently; it is rejected, requests limited user clarification, or enters an explicit review workflow according to an approved confidence policy.

AI authority is limited to interpretation, extraction, classification, proposal, confidence, and explanation. AI must never:

- write directly to the ledger;
- update or delete a posted journal;
- have repository or database permission capable of journal posting;
- invent evidence;
- hide uncertainty;
- make autonomous professional tax decisions; or
- bypass deterministic domain validation and user confirmation requirements.

An accepted AI proposal remains separate from a posted journal. Accounting Core revalidates the resulting command and exclusively controls posting under ADR-0004. Material action confirmation remains explicit where required by the product references.

Each decision records the provider/model identifier, model version where available, prompt/configuration version, schema version, confidence, relevant source references, and evaluation release. Only the minimum data needed for the active task and approved references may be sent to a provider. Provider payloads must comply with tenant isolation, data classification, consent, retention, and environment separation.

Model or prompt changes must pass a labeled regression dataset before release. AI usage and cost are measured by operation and tenant. Provider failure must leave original documents intact and allow deferred deterministic or manual processing. AI/document queues remain isolated so their backlog cannot block accounting posting.

Provider/model selection, routing rules, thresholds, fallback ordering, prompt storage representation, evaluation metrics, and retention details are deferred until an approved AI design establishes them. Those choices cannot weaken this authority boundary.

## Consequences

### Positive

- Providers can be replaced or routed without coupling business modules to vendor contracts.
- Invalid or uncertain output is contained before financial mutation.
- Model behavior and cost can be evaluated and reproduced by version.
- AI outages do not corrupt the ledger or destroy original evidence.

### Negative

- Adapters, schemas, evaluation datasets, and registries require ongoing maintenance.
- Provider-specific features may be unavailable until represented safely in the common contract.
- Human confirmation and deterministic validation can add latency to assisted workflows.

### Risks and mitigations

- **Risk:** The abstraction becomes a lowest-common-denominator interface. **Mitigation:** Version task-specific contracts and keep provider capabilities inside adapters without leaking authority.
- **Risk:** Sensitive tenant data is over-shared. **Mitigation:** Enforce task-scoped context minimisation, payload inspection, data classification, and least privilege.
- **Risk:** Model drift silently changes proposals. **Mitigation:** Record versions and require labeled regression gates before release.
- **Risk:** AI is treated as authoritative by downstream code. **Mitigation:** Keep proposal and posted states distinct and enforce Accounting Core permissions and schema/domain validation.
- **Risk:** Provider outage blocks routine accounting. **Mitigation:** Retain evidence and provide deterministic/manual fallback with isolated queues.

## Validation

- Contract tests replace a provider adapter with a test implementation without changing domain workflows.
- Schema tests prove invalid output cannot create an accounting command.
- Security tests prove AI credentials and services cannot post or mutate journals.
- Payload audits prove only active-task minimum context is transmitted and tenant boundaries hold.
- Regression datasets gate every model, prompt, schema, or routing change.
- Resilience tests demonstrate safe provider outage, timeout, retry, and fallback behavior.
- Telemetry verifies model/configuration version, outcome, latency, confidence, cost, and correlation without logging prohibited sensitive content.

## Rollout and rollback

The abstraction, schemas, registry, and deterministic validation precede provider-enabled MVP workflows. Full AI capability follows the Accounting Core Proof of Accuracy rather than preceding it. Providers and model/configuration releases use controlled feature flags where risk requires.

A provider or model release can be rolled back by routing to a previously validated configuration or to deterministic/manual processing. Proposals created under each version remain attributable. Rollback cannot delete or rewrite accepted financial history.

## Compliance

AI processing must observe data minimisation, consent, stated purpose, retention, sharing disclosure, encryption, environment isolation, and audit requirements. Cross-tenant context is prohibited. This ADR does not authorize autonomous tax advice, open-ended chat, new input channels, or any other out-of-MVP capability.
