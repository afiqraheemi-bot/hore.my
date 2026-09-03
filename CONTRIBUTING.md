# Contributing to hore.my

All contributions must preserve the product scope, locked architecture, financial integrity, tenant isolation, security, and auditability requirements documented by hore.my's authoritative references.

## Before starting

1. Read the relevant source, test, documentation, and accepted ADRs before making changes.
2. Confirm the work belongs to an approved milestone, sprint, and task with one primary objective and explicit acceptance criteria.
3. Resolve instructions using the precedence in [`docs/product/reference/README.md`](docs/product/reference/README.md).
4. Identify the owning module and its public contract.
5. Assess financial, tenant, authorization, security, privacy, accessibility, migration, and operational impact.
6. Create or update an ADR before implementation when a material architecture decision is required. Never change architecture silently.

Do not add product scope without Founder approval. Do not refactor outside the task, overengineer a speculative need, or treat a proposal as an approved decision.

## Official development workflow

```text
Milestone -> Sprint -> Task -> Implement -> Test -> Review -> Commit -> Demo -> Release Gate
```

### Milestone and sprint

- Work must trace to an approved product outcome.
- Break large work into small, independently verifiable tasks.
- Surface changes to scope, cost, risk, or user experience for Founder decision.

### Task

- Keep one primary objective per task.
- State acceptance criteria before implementation.
- Record relevant requirements, ADRs, module ownership, dependencies, and known risks.

### Implement

- Make the smallest coherent change that satisfies the task.
- Preserve module boundaries and dependency direction.
- Use public module contracts; do not reach into another module's internals or mutate its data directly.
- Keep business rules independent of delivery frameworks and infrastructure adapters.
- Never use binary floating point for money.
- Never grant AI direct ledger-write authority.
- Never update or delete posted journals; use reversal and replacement corrections.
- Preserve atomicity, balance, idempotency, tenant isolation, and auditability.
- Do not place network calls inside database transactions.
- Do not commit secrets, production personal data, generated build output, or debugging artifacts.

### Test

- Run targeted tests after each small change.
- Add meaningful regression coverage for corrected defects when feasible.
- Use unit tests for domain behavior and integration tests for databases, queues, storage, and adapters.
- Use contract tests for published APIs and events and end-to-end tests for critical user journeys.
- Financial behavior requires exact arithmetic, property-based invariants where applicable, and approved golden datasets.
- Security-sensitive changes require authorization and tenant-isolation coverage.
- Run the full applicable test suite after a work package and before merge or release.
- Do not claim completion without recorded test evidence.

### Review

- Review the complete diff for scope, correctness, security, migrations, documentation, and accidental files.
- Confirm all acceptance criteria and applicable Definition of Done items.
- Pull requests must explain intent, validation evidence, risk, and rollback or roll-forward behavior where relevant.
- Obtain at least one eligible approval plus required `CODEOWNERS` approval.
- Authors cannot approve their own changes. Resolve every required check and review conversation.

### Commit

Use small, atomic [Conventional Commits](https://www.conventionalcommits.org/) with this format:

```text
<type>(optional-scope): <imperative summary>
```

Allowed types are `feat`, `fix`, `docs`, `test`, `refactor`, `perf`, `build`, `ci`, `chore`, and `revert`. Stable scopes include `web`, `api`, `worker`, and `database`. Mark breaking changes with `!` and a `BREAKING CHANGE:` footer. Reference the work item when one exists.

### Demo

- Demonstrate the acceptance criteria using the intended workflow.
- Record material limitations, deferred work, and operational observations.
- Financial demonstrations must reconcile to authoritative ledger and evidence outputs.

### Release gate

A release is blocked if any of the following applies:

- Trial balance is not balanced.
- Reconciliation has an unexplained difference.
- Duplicate posting or duplicate MyInvois submission remains possible.
- Tenant isolation fails.
- A critical security finding remains unresolved.
- A migration or recovery strategy is untested.
- Backup or restoration is unproved.
- AI regression exceeds its approved threshold.
- Required performance or load tests fail.
- Monitoring, alerting, rollback, or runbooks are unavailable.

Product and accounting-domain acceptance must be recorded where required.

## Branch and pull-request workflow

- `main` is protected and must remain releasable.
- Use short-lived branches named `<type>/<issue>-<short-description>`.
- Update branches frequently; they should normally live less than three working days.
- Submit every change through a pull request. Direct pushes are prohibited except for explicitly authorized emergency recovery.
- Prefer squash merge so each pull request becomes one coherent Conventional Commit.
- Emergency changes still require incident linkage, available automated checks, explicit review, and retrospective remediation.

## Definition of Done

A change is done only when:

- acceptance criteria are met and no unapproved scope is added;
- code and documentation are in the correct owned module;
- architectural decisions are recorded where required;
- formatting, linting, strict static analysis, and applicable tests pass;
- financial calculations are exact and deterministic;
- tenant isolation and authorization are tested where applicable;
- migrations are backward compatible, tested, and recoverable;
- material success and failure paths are observable;
- user interfaces include applicable loading, empty, success, and error states and meet accessibility expectations;
- documentation, contracts, runbooks, and release notes are current;
- the diff has been reviewed and required owners have approved; and
- deployment and verification are complete when deployment is part of the task.

An item may be marked not applicable only with a short rationale in the pull request.

## Documentation and governance

- Update documentation in the same pull request as the behavior it describes.
- Preserve accepted ADRs; supersede them with a new ADR rather than rewriting history.
- Do not edit authoritative reference documents locally. Replace them only with newly authorized source versions.
- Check current official sources before implementing MyInvois, regulatory, security, or dependency behavior that may have changed.
- Keep operationally sensitive documentation owned and review-dated.

## Security reporting

Do not disclose suspected vulnerabilities, credentials, or personal data in public issues or logs. Escalate them privately to the repository owner or designated security owner. A dedicated `SECURITY.md` policy must define the formal reporting channel before public collaboration or production release.
