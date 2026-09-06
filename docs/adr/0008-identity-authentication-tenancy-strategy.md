# ADR-0008: Identity, Authentication, and Tenancy Strategy

- Status: Accepted
- Date: 2026-09-07
- Deciders: Founder / Product Owner; CTO / Technical Partner
- Owners: Identity and Tenant; Accounting Core; Reporting
- Related: [`ENGINEERING_BLUEPRINT.md`](../../ENGINEERING_BLUEPRINT.md), [`HORE_MY_MASTER_CONTEXT.md`](../product/reference/HORE_MY_MASTER_CONTEXT.md) §8 ("Identity dan Tenant" is Module 1 of MVP) and §12 (locked stack), [ADR-0001](0001-modular-monolith-architecture.md), [ADR-0002](0002-technology-stack-selection.md) (explicitly defers "authentication provider" and "API style"), [ADR-0004](0004-financial-integrity-principles.md) §"tenant isolation"

## Context

Every AETS document and every Accounting Core module built so far (M4–M10) already threads a `TenantId` value object through every command, query, and persisted row — but no module owning that identifier's real-world source exists yet. There is no `tenants` table, no `User` capable of authenticating, no HTTP entry point, and no session/token mechanism. `ADR-0002` explicitly named "authentication provider" and "API style" as deferred decisions requiring their own ADR before implementation. This ADR resolves both, plus the tenancy shape they must produce, so an Interfaces layer can finally be built on top of the already-tested Accounting Core.

Identity, Authentication, and Tenant are explicitly **out of scope for the AETS series** ([AETS-000](../specifications/accounting/AETS-000.md) §2.2: "non-accounting modules (Identity, Onboarding, Workspace/Task, general Document Processing) — these belong to their own specifications"). This ADR is that module's foundational decision record; it does not amend or extend any AETS document.

MVP scope is a single owner per Tenant — [`HORE_MY_MASTER_CONTEXT.md`](../product/reference/HORE_MY_MASTER_CONTEXT.md) §9 explicitly excludes multi-user approval, and §1 states the first user is "an enterprise or sole proprietorship, one owner." This ADR designs for exactly that shape; it does not design a multi-user authorization model.

This ADR does not decide: the Business Profile/Onboarding data model (Module 2), password-reset/email-verification enforcement policy, rate limiting or abuse protection (production-hardening phase), or any UI/UX flow. Those remain separate work.

## Decision drivers

- Every existing Accounting Core query and command already assumes a `TenantId` exists and is trustworthy — Identity's job is to produce that trust, not redesign Accounting Core's contract with it.
- `HORE_MY_MASTER_CONTEXT.md` §12 locks Laravel (backend), Nuxt/Vue/TypeScript PWA (frontend), PostgreSQL, and Redis — the auth mechanism must fit this stack without introducing an unapproved technology.
- §12 also requires "stateless application instances" — session state must live in Redis, never in-process.
- Engineering Blueprint §5.2 requires authorization enforced server-side at the protected operation, not only in the UI, and secure defaults for authentication.
- Engineering Blueprint §5.3 requires identifiers opaque to external consumers — this must extend to `UserId`, matching the established `TenantId`/`AccountId`/`JournalId` convention rather than Laravel's scaffolded auto-increment integer.
- The MVP frontend is a first-party PWA, not a third-party or native mobile client (native mobile is explicitly out of MVP scope, §9) — the simplest mechanism for a trusted first-party SPA should be preferred over a general-purpose bearer-token scheme, for a better default XSS posture.
- Avoid overengineering: MVP has no roles, no permissions, and no multi-user tenancy — the Identity domain model must stay proportionate to that reality rather than anticipating scope that is explicitly excluded.

## Considered options

**Authentication mechanism:**

1. Laravel Sanctum, SPA cookie-based session authentication (stateful, first-party).
2. Laravel Sanctum, API personal-access tokens (bearer token, stored client-side).
3. A third-party identity provider (e.g. Auth0, Clerk) via OAuth/OIDC.
4. Hand-rolled JWT issuance and verification.

**API style:**

1. REST/JSON, versioned under `/api/v1`.
2. GraphQL.

**Tenancy shape:**

1. `tenants` owns a reference to its single owning `users` row (`tenants.owner_user_id`); no `tenant_id` column on `users`.
2. `users` owns a `tenant_id` column pointing at `tenants`.
3. A full multi-user membership/pivot table between `users` and `tenants`.

**User identifier:**

1. Opaque string (UUID), matching `TenantId`/`AccountId`/`JournalId`.
2. Laravel's default auto-increment integer.

## Decision

**Authentication: Laravel Sanctum, SPA cookie-based session authentication (option 1).** Sanctum is already part of the Laravel ecosystem (no new vendor, no new technology beyond what ADR-0002 already locked), and its SPA mode is designed exactly for a first-party, same-origin-or-configured-domain frontend — which is what the Nuxt PWA is. The session cookie is `HttpOnly` and `Secure`, so a successful XSS in the frontend cannot exfiltrate a bearer token the way it could with option 2. A general-purpose token scheme, third-party IdP, or hand-rolled JWT (options 2–4) would add capability MVP does not need (no native mobile client, no third-party API consumers, no SSO requirement) at a real security and maintenance cost, so they are rejected for MVP. Session state is stored in Redis (`SESSION_DRIVER=redis`), consistent with the stateless-application-instance requirement (§12) and the already-locked Redis capability (ADR-0002) — no in-process session state is introduced.

**API style: REST/JSON, versioned under `/api/v1` (option 1).** Matches Engineering Blueprint §5.3's requirement for documented schemas and consistent error representation, requires no new client-side dependency for the Nuxt frontend, and is what `bootstrap/app.php`'s existing `shouldRenderJsonWhen(fn ($request) => $request->is('api/*') ...)` already anticipates. GraphQL (option 2) is rejected: it would be the first GraphQL surface in the stack, adding a schema-definition and resolver layer with no driver requiring it at MVP scale.

**Tenancy shape: `tenants.owner_user_id` (option 1).** A Tenant owns a reference to its single owning User, not the reverse. This keeps `users` free of a `tenant_id` column that would need to become a membership list the moment multi-user support is ever added — the schema does not have to change shape when that (currently out-of-scope) capability is eventually built; only a new membership table needs to be added alongside the existing `owner_user_id`. Option 2 (`tenant_id` on `users`) is rejected because MVP's own product principle — one owner per Tenant — is more naturally expressed as the Tenant referencing its owner, and because it would need to be un-done, not merely extended, if multi-user tenancy is added later. Option 3 (full membership model) is rejected now as speculative generality: Master Context §9 explicitly excludes multi-user approval from MVP, and Engineering Blueprint's own principle is to build the smallest reversible approach the current, locked scope requires.

**User identifier: opaque UUID string (option 1).** `UserId` is introduced as a Domain Value Object mirroring `TenantId`/`AccountId`/`JournalId` exactly (same validation: non-empty, no control character, defensive length bound, caller-supplied rather than self-generating). The Interfaces layer (specifically, the registration endpoint) is where a fresh identifier is finally minted — via `Str::uuid()->toString()` — resolving what `IncomeId`'s own docblock and every sibling identifier left as "deferred... to a future application/HTTP layer." `TenantId` is minted the same way, at the same call site, when a new Tenant is created alongside its owner.

**Identity module stays deliberately thin.** Unlike Accounting Core, Identity has no invariant beyond "exactly one Tenant per owning User" at MVP. It is built directly on Laravel's own `Authenticatable` contract and Eloquent, not as a hand-rolled Domain aggregate with its own repository — introducing that ceremony for a module with no comparable business rule would be overengineering. `UserId` and `TenantId` remain the only Domain-owned identifiers; the `User` and `Tenant` Eloquent models are the module's actual implementation.

**Tenant isolation is enforced twice.** Accounting Core's own domain-level checks (e.g. `RejectedAccountReferenceException`) remain unchanged and are not weakened by this ADR. A new HTTP-layer middleware additionally resolves the authenticated User's Tenant and rejects any request that does not carry it — defense-in-depth per Engineering Blueprint §5.2, not a replacement for the domain-level check.

## Consequences

### Positive

- Every existing, already-tested Accounting Core service (M7 Expense, M9 Income, M10 Reporting) becomes reachable through a real HTTP boundary without any change to its own contract.
- No new infrastructure vendor or technology is introduced beyond what ADR-0002 already locked.
- The tenancy shape does not need to be un-done if multi-user support is added after MVP.
- `UserId` slots into the existing opaque-identifier convention without special-casing.

### Negative

- Sanctum's SPA mode requires the API and the SPA to share a registrable domain (or Sanctum's `stateful` domain configuration) — this constrains, but does not block, eventual deployment topology and must be revisited if a materially different frontend hosting arrangement is ever chosen.
- No token-based access exists yet for any future non-browser client (a CLI, a mobile app, an integration partner) — deliberately, since none is in MVP scope; adding one later is an additive change (Sanctum personal access tokens), not a breaking one.
- Password reset and email verification enforcement are not decided by this ADR (deferred to the Identity module's own implementation notes) — this is a real pre-launch gap, not a design gap this ADR leaves ambiguous.

### Risks and mitigations

- **Risk:** A future frontend hosting change breaks Sanctum's same-site cookie assumption. **Mitigation:** Sanctum's `stateful` domain list is configuration, not code; revisit at deployment-architecture time, not now.
- **Risk:** The HTTP-layer tenant-isolation middleware is bypassed by a route that forgets to apply it. **Mitigation:** Domain-level tenant checks in Accounting Core remain the authoritative backstop; the Feature test suite for the Interfaces layer must include an explicit cross-tenant-leakage proof for every protected route, mirroring the tenant-isolation tests already established for M7/M9/M10.
- **Risk:** `UserId` generation (UUID v4) collides. **Mitigation:** Standard UUID v4 collision probability is accepted at this scale, consistent with how `JournalId`/`ExpenseId`/`IncomeId` generation is already deferred to, and will be resolved identically by, their own future call sites.

## Validation

- A registration request creates exactly one `tenants` row and one `users` row, atomically; a duplicate email is rejected.
- A login request with correct credentials establishes a session; incorrect credentials are rejected; logout invalidates the session.
- Every protected route rejects an unauthenticated request (401) and a request from an authenticated User of a different Tenant attempting to reach another Tenant's data (403/404, never silent success) — proven by an automated Feature test per route, not by manual inspection.
- Full regression (PHPStan, Pint, PHPUnit, `composer validate --strict`, `git diff --check`) remains green after this module is added.

## Rollout and rollback

This ADR introduces a new module with no prior production data — there is no migration-of-existing-data concern. Implementation proceeds directly: `composer require laravel/sanctum`, the `users`/`tenants` schema described above, Sanctum's stateful-domain and session configuration, the Identity module's controllers and middleware, and Feature tests. A later change to the authentication mechanism (e.g. adding token-based access for a future non-browser client) is additive and does not require superseding this ADR; a change that *replaces* the SPA cookie mechanism for the existing frontend would require a superseding ADR and a coordinated frontend/backend rollout, since active sessions cannot be silently invalidated without a signed-out user experience being designed for it.

## Compliance

Session cookies are `HttpOnly`, `Secure`, and `SameSite`-scoped per Sanctum's own defaults, consistent with Master Context §15's encryption-in-transit and least-privilege requirements. Passwords are hashed with Laravel's default hasher (bcrypt/argon2id per `config/hashing.php`), never stored or logged in plain text. No personal data beyond name and email is collected by this module. This ADR does not introduce MyInvois, tax, or any other regulatory surface.
