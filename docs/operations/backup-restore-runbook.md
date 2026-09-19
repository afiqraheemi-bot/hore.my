# Backup and Restore Runbook

- Status: First drill completed and documented
- Owner: Accounting Core (see [`CODEOWNERS`](../../CODEOWNERS))
- Related: [ADR-0004](../adr/0004-financial-integrity-principles.md) ("an unproved backup/restore remains a release blocker"), `infrastructure/postgres/backup.sh`, `infrastructure/postgres/restore.sh`

## 1. Purpose

ADR-0004 names an unproved backup/restore as an explicit release blocker. This document records the scripts that perform it and a real, executed drill proving they work — not a simulated or theoretical description.

## 2. Scripts

- `infrastructure/postgres/backup.sh <database-name> <output-path>` — runs `pg_dump -Fc` (PostgreSQL's custom, compressed, dependency-order-independent format) against the running Postgres container, writing the archive to `<output-path>`.
- `infrastructure/postgres/restore.sh <database-name> <input-path>` — drops and recreates `<database-name>` inside the running container, then `pg_restore`s it from `<input-path>`. **Destructive to `<database-name>` only** — every other database on the same server is untouched.

Both accept `POSTGRES_CONTAINER` (default `horemy-postgres-1`) and `POSTGRES_USER` (default `postgres`) env overrides.

**Safety guard:** `restore.sh` refuses to run against a database name that does not end in `_test` unless `FORCE=1` is set — mirroring the same guard `Tests\TestCase` already applies (`infrastructure/postgres/init-test-database.sql`) against a test run silently wiping real data. This drill therefore ran against `hore_my_test` (the dedicated, disposable test database this repo already provisions), never `hore_my` (the real application database) — proving the mechanism works without risking live dev data or the shared `horemy-postgres-1` volume other running work depends on.

## 3. Drill executed 2026-09-19

1. **Seed real data.** `hore_my_test` was migrated fresh, then AETS-012's Golden Dataset v1 scenario (`GoldenDatasetScenarioRunner`, the same class `proof-of-accuracy:certify` uses — see AETS-012) was run directly, producing real Accounts, Journals, Expenses, Incomes, Transfers, a Bank Statement import, confirmed Matches, two completed Reconciliations, an Invoice, a Payment, and an Allocation across two Tenants.
2. **Verify baseline correctness before backup.** Row counts captured across 16 tables; `journal_lines` summed by Tenant and Direction: Tenant A Debit = Credit = `77000` minor units (RM770.00), Tenant B Debit = Credit = `5000` minor units (RM50.00) — exactly matching the dataset's own independently hand-computed oracle (`expected/accounting-results.json`), confirming the seeded data was genuinely correct before the drill touched it.
3. **Backup.** `./infrastructure/postgres/backup.sh hore_my_test infrastructure/postgres/backups/drill-hore_my_test-20260919153606.dump` — produced a 120 KB archive.
4. **Destroy and restore.** `./infrastructure/postgres/restore.sh hore_my_test infrastructure/postgres/backups/drill-hore_my_test-20260919153606.dump` — dropped `hore_my_test`, recreated it empty, and restored it from the archive.
5. **Verify after restore.**
   - The same 16-table row-count query, diffed byte-for-byte against the pre-backup capture: **identical**.
   - The same `journal_lines` sum-by-Tenant-and-Direction query: **identical** (`77000`/`77000` and `5000`/`5000`).
   - A real application-level check, not just raw SQL: `TrialBalanceQuery::asOf()` (the same reporting class the product's own Trial Balance page uses) run against the restored database via `php artisan tinker` returned `isBalanced()===true` for both Tenants, with `totalDebit()`/`totalCredit()` exactly `770.00`/`770.00` and `50.00`/`50.00` — a genuine post-restore reconciliation through real domain logic, not a raw-table comparison alone.
6. **Cleanup.** `hore_my_test` was migrated fresh again afterward so the shared test database is left in the same pristine state the ordinary test suite expects. The full backend regression suite (1897 tests) was re-run and passed clean, confirming the drill left no residue affecting other work.
7. **Confirmed untouched:** `hore_my` (the real application database, `horemy-postgres-1`'s primary volume) was never dropped, restored into, or otherwise modified by this drill — verified directly (its `users` table row count was unchanged before and after).

## 4. Outcome

Backup and restore are proven to work against this stack's real PostgreSQL instance: a `pg_dump -Fc` archive of a real, multi-table, cross-Tenant accounting dataset restores byte-for-byte-equivalent row counts and, more importantly, restores data that a real reporting service still finds exactly balanced and correct. This resolves ADR-0004's "unproved backup/restore remains a release blocker" — the mechanism itself is proven; nothing here implies a production backup *schedule*, retention policy, or off-site storage strategy, none of which exist yet and are out of this drill's scope.

## 5. What this drill does not cover (future work, not blockers)

- Automated/scheduled backups (cron, managed-service snapshots) — this drill is a manual, on-demand proof of mechanism only.
- Off-site/durable backup storage — `infrastructure/postgres/backups/` is local and gitignored; nothing here addresses disaster recovery beyond a single host.
- Point-in-time recovery (WAL archiving) — only a full logical dump/restore is proven.
- A production-sized dataset's backup/restore timing — this drill's dataset is small (16 tables, dozens of rows); a real production volume's backup/restore duration is unmeasured.
