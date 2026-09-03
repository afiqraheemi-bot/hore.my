# ADR-0003: Repository Structure

- Status: Accepted
- Date: 2026-09-03
- Deciders: Founder / Product Owner; CTO / Technical Partner
- Owners: Repository maintainers; All module owners
- Related: [`ENGINEERING_BLUEPRINT.md`](../../ENGINEERING_BLUEPRINT.md), [`HORE_MY_PROJECT_INSTRUCTIONS.txt`](../product/reference/HORE_MY_PROJECT_INSTRUCTIONS.txt), [`HORE_MY_MASTER_CONTEXT.md`](../product/reference/HORE_MY_MASTER_CONTEXT.md); [ADR-0001](0001-modular-monolith-architecture.md); [ADR-0002](0002-technology-stack-selection.md)

## Context

hore.my needs one governed home for the Nuxt PWA, Laravel modular monolith, workers, shared contracts, database changes, infrastructure, cross-application tests, and engineering documentation. The Engineering Blueprint establishes a monorepo organized by deployable application, reusable package, platform concern, and documentation rather than by team.

The business modules listed in the project references must remain explicit inside the backend, while shared packages must not become an alternative location for unowned domain logic. The repository must also preserve traceability between decisions, requirements, migrations, tests, and operational material.

This ADR accepts the top-level structure from the Engineering Blueprint. It does not decide precise Laravel module directories, PHP namespaces, Nuxt feature directories, workspace tooling, or whether a worker is packaged from the API artifact or built as a separate artifact.

## Decision drivers

- Keep deployable units, reusable packages, data changes, infrastructure, tests, and documentation visibly distinct.
- Make module ownership and dependency direction enforceable.
- Support atomic cross-cutting changes in one repository.
- Keep architecture decisions and authoritative product references adjacent to implementation history.
- Avoid premature repositories or packages with unclear ownership.

## Considered options

1. The Engineering Blueprint monorepo organized by applications, packages, database, infrastructure, tests, and documentation.
2. Separate repositories for frontend, backend, worker, infrastructure, and documentation.
3. A single undifferentiated application directory organized primarily by framework file type.

## Decision

hore.my will use the monorepo structure defined in the Engineering Blueprint:

```text
hore.my/
├── apps/
│   ├── web/
│   ├── api/
│   └── worker/
├── packages/
│   ├── domain/
│   ├── contracts/
│   ├── config/
│   └── observability/
├── database/
│   ├── migrations/
│   ├── seeds/
│   └── documentation/
├── infrastructure/
│   ├── environments/
│   └── modules/
├── tests/
│   ├── contract/
│   ├── end-to-end/
│   └── performance/
├── docs/
├── scripts/
├── .github/
├── CODEOWNERS
├── CONTRIBUTING.md
├── README.md
├── SECURITY.md
└── ENGINEERING_BLUEPRINT.md
```

`apps/web` owns the Nuxt PWA. `apps/api` owns the Laravel modular-monolith application and its explicit business modules. `apps/worker` is reserved for independently runnable asynchronous consumers when required. Creating the directory does not authorize a separate business service or a duplicated domain model.

`packages` contains reusable, non-deployable packages with defined consumers and public interfaces. Application or module-specific business behavior stays with its owner. `packages/domain` may contain only genuinely shared, framework-independent concepts whose ownership and consumers are documented; it must not become a global domain dumping ground.

Database migrations remain ordered and immutable after shared deployment. Cross-boundary contract, end-to-end, and performance tests live under `tests`; module-local tests stay with their owning app or package. Authoritative project references remain under `docs/product/reference`, and ADRs remain under `docs/adr`.

Each populated deployable app and reusable package requires a README describing purpose, owner, public interface, dependencies, and validation commands. Ownership is enforced through `CODEOWNERS`. Directories are created only when they contain an owned artifact.

Exact internal structures for Nuxt features, Laravel modules, migrations, infrastructure providers, and generated contract outputs are deferred until the applicable foundation design. Those choices must obey ADR-0001 and the Engineering Blueprint and must not silently create new deployables or module coupling.

## Consequences

### Positive

- Cross-stack changes and contracts can be reviewed and validated atomically.
- Top-level ownership and artifact intent are predictable.
- Architecture, product references, operations, and implementation share traceable history.
- The structure supports separate deployment artifacts without requiring separate repositories.

### Negative

- CI must detect affected scopes to avoid running every expensive check for every change.
- Repository tooling must support both frontend and backend ecosystems.
- Boundary enforcement needs automated checks because filesystem structure alone is insufficient.

### Risks and mitigations

- **Risk:** Shared packages accumulate unrelated code. **Mitigation:** Require documented owners, consumers, and stable responsibilities.
- **Risk:** Worker code duplicates domain behavior. **Mitigation:** Keep business rules in their owning modules and invoke public application contracts.
- **Risk:** Framework conventions override module ownership. **Mitigation:** Define internal layouts through a later, stack-aware ADR and enforce dependency rules.
- **Risk:** Secrets or generated artifacts enter the monorepo. **Mitigation:** Apply ignore rules, secret scanning, generated-file markers, and CI policy checks.

## Validation

- Repository policy checks verify allowed top-level locations, ownership metadata, documentation, and absence of forbidden generated or secret material.
- Architecture tests enforce app/package dependency direction and module public entry points.
- CI demonstrates affected-scope builds while retaining required integrated contract and release gates.
- Reviews confirm migrations, tests, references, and runbooks reside in their designated locations.

## Rollout and rollback

The structure is introduced incrementally as owned artifacts are created; empty placeholders are unnecessary. Existing engineering and reference documents remain at their established paths.

Moving a top-level responsibility or splitting the repository requires a superseding ADR, updated ownership and documentation links, and verification that build, deployment, and audit history remain intact. File moves should be performed as traceable, reversible changes before dependent implementation expands.

## Compliance

The repository must not contain secrets, production personal data, or unapproved sensitive fixtures. Access and review rules must protect security, financial, infrastructure, and reference-document areas. Retained source history and immutable ADRs support auditability; they do not replace runtime financial audit records.
