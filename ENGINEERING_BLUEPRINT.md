# hore.my Engineering Blueprint

**Status:** Official engineering baseline  
**Applies to:** All software, infrastructure, data, and documentation maintained for hore.my  
**Effective date:** 2026-09-03  
**Change authority:** Maintainers designated in `CODEOWNERS`  

## 1. Purpose

This blueprint defines the minimum engineering structure and operating rules for hore.my. It is intentionally technology-neutral until architecture decisions select specific languages, frameworks, data stores, and deployment platforms.

All future implementation must conform to this document. A deviation requires an accepted Architecture Decision Record (ADR). If an ADR conflicts with this blueprint, the ADR must identify the affected rule and this document must be updated in the same change.

The normative terms **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT**, and **MAY** indicate requirement strength.

## 2. Engineering Principles

1. **Explicit boundaries:** Business rules belong to domain modules, not delivery frameworks or persistence code.
2. **Dependency direction:** Dependencies point inward—from interfaces and infrastructure toward application and domain contracts.
3. **Secure defaults:** Authentication, authorization, validation, least privilege, and secret isolation are design requirements.
4. **Operational ownership:** Every production capability includes observable behavior, failure handling, and a responsible owner.
5. **Reproducibility:** Builds, tests, migrations, and deployments are automated and repeatable from version-controlled inputs.
6. **Small, reversible change:** Changes are reviewable, independently testable, and safe to roll back or roll forward.
7. **Documentation with code:** Architectural and operational documentation changes alongside the behavior it describes.
8. **Evidence-based quality:** Automated checks and recorded decisions take precedence over undocumented convention.

## 3. Repository Model

hore.my uses a monorepo unless an accepted ADR establishes a different model. The repository is organized by deployable application, reusable package, platform concern, and documentation—not by individual team.

```text
hore.my/
├── apps/                    # Independently deployable applications
│   ├── web/                 # Public/customer-facing frontend
│   ├── api/                 # Primary backend/API
│   └── worker/              # Asynchronous job consumers, when required
├── packages/                # Reusable, non-deployable packages
│   ├── domain/              # Framework-independent business concepts and rules
│   ├── contracts/           # API/event schemas and generated-type inputs
│   ├── config/              # Shared tool configuration
│   └── observability/       # Shared logging, metrics, and tracing interfaces
├── database/
│   ├── migrations/          # Immutable, ordered schema changes
│   ├── seeds/               # Deterministic non-production seed data
│   └── documentation/       # Data model and ownership notes
├── infrastructure/          # Infrastructure-as-code and deployment configuration
│   ├── environments/        # Explicit environment composition
│   └── modules/             # Reusable infrastructure modules
├── tests/
│   ├── contract/            # Cross-boundary compatibility tests
│   ├── end-to-end/          # Critical user journeys
│   └── performance/         # Load and performance checks
├── docs/
│   ├── architecture/        # System context, containers, components, data flows
│   ├── adr/                 # Architecture Decision Records
│   ├── api/                 # Human-readable API guidance
│   ├── product/             # Domain glossary and product behavior
│   ├── operations/          # Runbooks, SLOs, recovery, and incident guidance
│   ├── security/            # Threat models and security policies
│   └── development/         # Local setup and contributor guides
├── scripts/                 # Versioned automation; no application business logic
├── .github/                 # Repository governance and CI workflows
├── CODEOWNERS               # Ownership and required-review rules
├── CONTRIBUTING.md          # Contributor workflow and local verification
├── README.md                # Repository entry point
├── SECURITY.md              # Vulnerability reporting and security expectations
└── ENGINEERING_BLUEPRINT.md # This baseline
```

Directories are created only when they contain an owned artifact. Empty placeholders are not required. Each deployable application and reusable package MUST contain a README describing its purpose, owner, public interface, dependencies, and local validation commands.

Generated files MUST be clearly identified and MUST NOT be edited manually. Build outputs, local secrets, dependency caches, and editor-specific state MUST NOT be committed.

## 4. Module Boundaries

### 4.1 Architectural layers

Backend modules use these logical layers regardless of framework:

```text
interfaces -> application -> domain
                   |
                   v
          infrastructure adapters
```

- **Domain:** Entities, value objects, policies, invariants, and domain events. It MUST NOT depend on web frameworks, database clients, queue clients, or external services.
- **Application:** Use cases and orchestration. It depends on domain code and declared ports, but not concrete infrastructure implementations.
- **Interfaces:** HTTP, command-line, scheduled, event, and message handlers. They translate external input into application requests and map results back to transport-specific output.
- **Infrastructure:** Implementations of persistence, messaging, caching, identity, file storage, and third-party integrations. Implementations satisfy ports owned by inner layers.

Frontend code uses these logical layers:

- **Application shell:** Routing, global providers, error boundaries, and composition.
- **Features:** User-facing capabilities organized by domain outcome.
- **Entities/domain:** Shared business types and client-side domain rules.
- **Shared UI:** Presentational primitives with no product workflow knowledge.
- **Infrastructure:** API clients, telemetry, storage, and platform adapters.

### 4.2 Boundary rules

- A deployable app MAY depend on packages; a package MUST NOT depend on a deployable app.
- Shared packages MUST have a defined consumer and stable responsibility. They MUST NOT become miscellaneous utility collections.
- Modules expose a documented public entry point. Cross-module imports MUST use that entry point and MUST NOT reach into another module's internals.
- Domain modules communicate through explicit application contracts or events, not shared database access.
- A module owns its data and validation rules. Other modules MUST NOT mutate that data outside its published interface.
- Cyclic dependencies are prohibited.
- External services and framework APIs MUST be wrapped at the module boundary when their behavior affects business logic or testability.
- API and event contracts MUST be versioned, machine-validatable, and backward compatible within a supported version.
- Synchronous and asynchronous operations MUST define timeout, retry, idempotency, and failure semantics where applicable.
- Database transactions MUST NOT span network calls.

### 4.3 Ownership

Every application, package, data store, API, queue, scheduled task, and infrastructure module MUST have an owner recorded in `CODEOWNERS` or its README. Security-sensitive and production-critical areas require review from their designated owners.

## 5. Coding Standards

Tool-specific choices are recorded in ADRs and centralized under `packages/config` or repository-root configuration. Until a language is selected, the following rules apply universally.

### 5.1 Source quality

- Use the language's standard formatter; formatting MUST be automated and enforced in CI.
- Enable the strictest practical static-analysis and type-checking settings. Suppressions require an adjacent explanation.
- Prefer clear domain terminology over abbreviations. Names MUST align with the glossary in `docs/product/glossary.md`.
- Functions and classes SHOULD have one responsibility and explicit inputs and outputs.
- Avoid hidden global state and implicit side effects.
- Public interfaces require documentation and compatibility consideration.
- Errors MUST retain actionable context without exposing secrets or personal data.
- Dead code, commented-out code, debugging output, and unresolved placeholders MUST NOT be merged.
- Source files MUST use UTF-8, LF line endings, and a final newline. An `.editorconfig` MUST enforce portable editor defaults.

### 5.2 Security and privacy

- Secrets MUST come from an approved secret manager or local ignored environment files; they MUST never be committed or logged.
- All untrusted input MUST be validated at the system boundary.
- Authorization MUST be enforced server-side at the protected operation, not only in the user interface.
- Queries and commands MUST use safe parameterization; dynamic code execution from untrusted input is prohibited.
- Sensitive data MUST be classified, minimized, encrypted as appropriate, and excluded from telemetry by default.
- Dependencies and container images MUST be pinned through lockfiles or immutable references and scanned in CI.
- New trust boundaries or sensitive data flows require an updated threat model.

### 5.3 APIs, data, and migrations

- APIs MUST use documented schemas and consistent error representations.
- Boundary timestamps MUST include timezone information and use UTC unless the domain explicitly requires local civil time.
- Identifiers MUST be opaque to external consumers.
- Money MUST use an exact decimal representation and an explicit currency.
- Migrations are immutable after deployment to a shared environment.
- Production migrations MUST be backward compatible with the currently deployed application during rollout.
- Destructive data changes require a staged migration, verified backup/recovery path, and explicit reviewer approval.

### 5.4 Observability

- Services MUST emit structured logs with correlation identifiers.
- Logs MUST describe events, not concatenate unstructured diagnostic fragments.
- Production-critical flows MUST define metrics and alertable failure signals.
- Network and background operations MUST expose latency, success, failure, and retry behavior.
- Health endpoints MUST distinguish process liveness from dependency readiness.

### 5.5 Testing

- Tests MUST be deterministic, isolated, and readable as behavioral specifications.
- Domain behavior SHOULD be covered primarily by fast unit tests.
- Database and adapter behavior requires integration tests against representative dependencies.
- Published APIs and events require contract tests.
- Critical user journeys require end-to-end coverage.
- Defect fixes MUST include a regression test when technically feasible.
- Flaky tests MUST be fixed or quarantined with an owner and expiry date; silently retrying them is not an acceptable remedy.
- Coverage is a diagnostic signal, not a substitute for meaningful assertions. Initial thresholds MUST be established by ADR when the first stack is selected and may only increase without an approved exception.

## 6. Git Workflow

The repository uses trunk-based development with short-lived branches.

### 6.1 Branches

- `main` is protected and MUST remain releasable.
- Direct pushes to `main` are prohibited except for explicitly authorized emergency recovery.
- Work branches use `<type>/<issue>-<short-description>`, for example `feat/123-booking-search`.
- Branches SHOULD be rebased or updated frequently and SHOULD normally live less than three working days.
- Long-lived environment, release, and developer branches are prohibited unless an ADR documents the operational need.

### 6.2 Pull requests

- Every change enters `main` through a pull request.
- Pull requests MUST be focused, linked to a tracked outcome, and include intent, validation evidence, risk, and rollback notes where relevant.
- Draft pull requests MAY be used for early collaboration but cannot merge.
- At least one approval from an eligible reviewer is required. `CODEOWNERS` approval is additionally required for owned or sensitive areas.
- Authors MUST NOT approve their own changes.
- All required checks and conversations MUST be resolved before merge.
- Force-pushing after approval dismisses stale approvals.
- Prefer squash merge to keep one coherent conventional commit per pull request. Exceptions for intentionally preserved commit series require maintainer agreement.
- The merge queue, when available, is the authority for validating the final integrated state.

### 6.3 Releases and emergencies

- Releases are created from immutable commits on `main` and tagged using Semantic Versioning where the product has a versioned release artifact.
- Deployment promotion MUST use the same built artifact across environments.
- Rollback or roll-forward procedures MUST be documented for each production service.
- Emergency changes still require a pull request, automated checks where available, explicit incident linkage, and retrospective review. A temporary bypass must be recorded and remediated immediately after stabilization.

## 7. Commit Conventions

Commits use Conventional Commits:

```text
<type>(optional-scope): <imperative summary>

optional body explaining why and important constraints

optional footer(s)
```

Allowed types:

- `feat`: User-visible capability
- `fix`: Defect correction
- `docs`: Documentation-only change
- `test`: Test-only change
- `refactor`: Internal change without behavior change
- `perf`: Performance improvement
- `build`: Build system or dependency change
- `ci`: Continuous-integration or delivery change
- `chore`: Maintenance not covered above
- `revert`: Reversal of an earlier commit

Rules:

- Use lowercase types and concise, imperative summaries without a trailing period.
- Scopes SHOULD identify a stable module or application, such as `web`, `api`, `worker`, or `database`.
- Breaking changes MUST include `!` after the type/scope and a `BREAKING CHANGE:` footer.
- Reference work items in footers when available, for example `Refs: HORE-123`.
- Commits MUST be independently buildable when a multi-commit history is intentionally retained.
- Commit messages MUST NOT contain credentials, personal data, or generated changelog prose.

Examples:

```text
feat(api): expose property availability
fix(worker): prevent duplicate reservation processing
docs(adr): record primary database decision
```

## 8. Documentation Layout and Standards

`README.md` is the repository entry point and MUST link to setup instructions, architecture, contribution rules, security policy, and operational documentation.

```text
docs/
├── architecture/
│   ├── overview.md          # System purpose, context, and constraints
│   ├── containers.md        # Deployable units and dependencies
│   ├── components/          # Significant component descriptions
│   └── data-flows/          # Important trust and information flows
├── adr/
│   ├── README.md            # ADR index and lifecycle
│   └── NNNN-short-title.md  # Immutable decision records
├── api/                     # API usage, lifecycle, and compatibility
├── product/
│   └── glossary.md          # Canonical domain language
├── operations/
│   ├── runbooks/            # Actionable diagnosis and recovery procedures
│   ├── slos.md              # Service objectives and indicators
│   └── disaster-recovery.md
├── security/
│   ├── threat-model.md
│   └── data-classification.md
└── development/
    ├── setup.md
    ├── testing.md
    └── releasing.md
```

Documentation MUST:

- State its owner and last meaningful review date when operationally sensitive.
- Describe current behavior; proposals belong in issues, RFCs, or proposed ADRs.
- Use relative links and pass automated link and formatting checks.
- Avoid duplicating generated API schemas or configuration references.
- Be updated in the same pull request as the behavior or interface it documents.
- Use diagrams only when they clarify relationships or flows, and store editable source alongside rendered output.

## 9. Architecture Decision Records

ADRs live in `docs/adr`, use zero-padded sequential numbers, and are never deleted after acceptance. Superseded decisions remain as historical records and link to their replacements.

Lifecycle: `Proposed` → `Accepted` → `Superseded` or `Deprecated`. Rejected proposals use `Rejected`.

### ADR template

```markdown
# ADR-NNNN: Short decision title

- Status: Proposed
- Date: YYYY-MM-DD
- Deciders: Names or responsible roles
- Owners: Affected module owners
- Related: Issue, RFC, incident, or ADR links

## Context

Describe the problem, constraints, forces, and relevant evidence. State what is
inside and outside the decision's scope.

## Decision drivers

- Driver one
- Driver two

## Considered options

1. Option one
2. Option two
3. Option three

## Decision

State the selected option and the rules it establishes.

## Consequences

### Positive

- Expected benefit

### Negative

- Accepted cost or limitation

### Risks and mitigations

- Risk and corresponding mitigation

## Validation

Describe how the decision will be validated and which observable outcomes will
indicate success or trigger reconsideration.

## Rollout and rollback

Describe adoption stages, compatibility requirements, and the reversal strategy.

## Compliance

List security, privacy, legal, accessibility, or operational implications.
```

An ADR is required for decisions that materially affect system boundaries, foundational technology, persistent data, public contracts, security posture, deployment topology, availability, cost, or organization-wide development practice. Routine implementation details do not require an ADR.

## 10. Definition of Done

A change is done only when every applicable item below is satisfied:

### Intent and design

- Acceptance criteria are explicit and met.
- The change stays within approved module boundaries.
- Material architectural decisions have an accepted ADR.
- Security, privacy, accessibility, data, and operational impacts have been assessed.

### Implementation quality

- Code is understandable, formatted, lint-clean, type-safe, and free of known dead code.
- Public interfaces and failure behavior are explicit.
- Dependencies are justified, pinned, licensed appropriately, and scanned.
- No secrets, sensitive test data, or unnecessary personal data are introduced.

### Verification

- Appropriate unit, integration, contract, and end-to-end tests pass.
- New behavior and fixed defects have meaningful automated coverage.
- Database migrations are tested forward and, where supported, backward.
- Performance-sensitive changes have evidence against an agreed baseline.
- Manual verification, when necessary, is recorded and does not replace feasible automation.

### Delivery and operation

- Observability covers material success and failure paths.
- Deployment and rollback or roll-forward behavior is understood and documented.
- Feature flags have an owner, purpose, expiry condition, and removal plan.
- Required documentation, schemas, runbooks, and changelog/release notes are current.
- Required reviewers approve and all quality gates pass on the merge commit.
- The change is deployed to its intended environment and verified there when deployment is part of the work item.

Items may be marked not applicable only with a brief rationale in the pull request.

## 11. Quality Gates

Quality gates are automated wherever technically possible and are blocking unless explicitly identified otherwise.

### 11.1 Local/pre-commit

- Formatter
- Fast linter checks
- Secret detection
- Validation of changed structured configuration

Local hooks improve feedback but CI remains authoritative.

### 11.2 Pull-request gates

Every pull request MUST pass:

1. Repository policy and commit/PR metadata validation.
2. Formatting, linting, and strict type checking.
3. Unit and relevant integration tests.
4. Contract/schema compatibility checks for changed interfaces.
5. Migration validation for database changes.
6. Dependency, license, secret, and static security scans.
7. Build/package verification for affected deployables.
8. Documentation formatting and link validation.
9. Required approvals and `CODEOWNERS` review.
10. Changed-scope test coverage policy established by the stack ADR.

Critical or high-severity security findings, failing tests, incompatible contracts, invalid migrations, and missing required approvals block merge. Exceptions require a documented risk acceptance with owner, rationale, compensating control, and expiry date.

### 11.3 Main-branch and release gates

- Revalidate the integrated commit rather than relying only on branch results.
- Produce immutable, traceable artifacts with source revision and dependency metadata.
- Generate a software bill of materials for production artifacts.
- Sign or attest release artifacts when platform support is available.
- Run end-to-end smoke tests in a production-like environment.
- Confirm configuration and migration compatibility before promotion.
- Require explicit production approval until an ADR establishes safe automated promotion.
- Verify health signals immediately after deployment and trigger documented recovery on failure.

### 11.4 Scheduled gates

- Full dependency and container vulnerability scans
- Broader end-to-end and performance suites
- Backup restoration exercises at a documented cadence
- Dependency freshness and unsupported-runtime reporting
- Documentation, ownership, access, and feature-flag expiry reviews

Scheduled failures MUST create an owned, tracked item with severity and remediation timing.

## 12. Baseline Governance

- This blueprint is version controlled and reviewed at least annually or after a material architecture or operating-model change.
- Changes to normative rules require maintainer and affected-owner approval.
- Tool and framework selection MUST be captured through ADRs before implementation establishes de facto standards.
- Exceptions MUST be narrow, time-bound, owned, and visible. Repeated exceptions require a policy change or architectural correction.
- Repository configuration and branch protection SHOULD enforce this baseline rather than depend on memory.

Until project-specific ADRs exist, this blueprint is the governing source for engineering practice. Where it is silent, teams choose the smallest reversible approach consistent with the principles above and document consequential choices before implementation.
