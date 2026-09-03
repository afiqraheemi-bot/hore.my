# hore.my

hore.my is an AI-native accounting workspace for Malaysian solopreneurs and microbusiness owners operating as enterprises or sole proprietorships. It makes routine accounting action-first: provide an instruction or evidence, review a structured proposal, and confirm a controlled outcome.

hore.my is not an open-ended chatbot. AI may interpret, extract, classify, propose, and explain, but it never writes directly to the ledger. The deterministic Accounting Core exclusively controls balanced, atomic, idempotent, append-only posting.

## Project status

The repository is at the engineering-foundation stage. Application code has not been bootstrapped.

## Approved architecture

- Nuxt 3, Vue 3, TypeScript, Tailwind CSS, and an installable PWA
- Laravel modular monolith with explicit module boundaries
- PostgreSQL as the authoritative transactional database
- Redis for queues, cache, and coordination
- Encrypted, versioned object storage for documents and evidence
- Transactional outbox for external and asynchronous work

The approved decisions and their constraints are indexed in [`docs/adr`](docs/adr/README.md).

## Financial and security invariants

- Money uses integer minor units or controlled exact `NUMERIC`/`DECIMAL`; binary floating point is prohibited.
- Every posted journal balances exactly and commits atomically.
- Material commands are idempotent and duplicate-resistant.
- Posted journals are append-only; corrections use reversal and replacement.
- AI produces proposals only and has no ledger-write permission.
- Tenant isolation, least privilege, encryption, and auditability are mandatory.
- Every reported figure must be traceable to its journal and evidence.

## Repository layout

The approved monorepo layout is defined in the [Engineering Blueprint](ENGINEERING_BLUEPRINT.md) and [ADR-0003](docs/adr/0003-repository-structure.md). Directories are created only when they contain an owned artifact.

```text
apps/            Deployable web, API, and worker applications
packages/        Reusable packages and shared contracts
database/        Migrations, seeds, and data documentation
infrastructure/  Environment and infrastructure definitions
tests/           Cross-boundary, end-to-end, and performance tests
docs/            Architecture, product, security, and operations documentation
scripts/         Repository automation without application business logic
```

## Sources of truth

Engineering work must follow, in precedence order:

1. Latest Founder instruction
2. [`HORE_MY_PROJECT_INSTRUCTIONS.txt`](docs/product/reference/HORE_MY_PROJECT_INSTRUCTIONS.txt)
3. [`HORE_MY_MASTER_CONTEXT.md`](docs/product/reference/HORE_MY_MASTER_CONTEXT.md)
4. [System Requirements Specification](docs/product/reference/hore.my_Spesifikasi_Keperluan_Sistem_v1.0_BM.pdf)
5. Detailed Project Proposal

See the [reference policy](docs/product/reference/README.md) for conflict handling.

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md) before making changes. The official workflow is:

```text
Milestone -> Sprint -> Task -> Implement -> Test -> Review -> Commit -> Demo -> Release Gate
```

Material architecture changes require an ADR. Financial integrity, tenant isolation, and security failures are release blockers.

## License

hore.my is proprietary software. See [LICENSE](LICENSE).
