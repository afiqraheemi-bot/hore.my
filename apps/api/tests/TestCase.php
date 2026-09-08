<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Defense-in-depth against a test run destroying real data (P1-6,
     * 2026-09-08 audit remediation).
     *
     * Several integration tests call `Artisan::call('migrate:fresh', ...)`
     * or `DB::connection('pgsql')->table(...)->truncate()` directly
     * against the `pgsql` connection — and this class's own `tearDown()`
     * below performs no per-test rollback, so every test in this suite
     * commits real writes to whatever database that connection resolves
     * to. `phpunit.xml` and `docker-compose.yml` are configured so that
     * connection resolves to `hore_my_test`, never the application
     * database (`hore_my`) — but configuration can drift or be
     * misconfigured in an environment this codebase does not control, so
     * this check does not trust that configuration; it verifies the
     * *actual resolved* database name at the start of every single test
     * and refuses to run at all otherwise. This is not a substitute for
     * the correct configuration — it is what fails loudly the moment
     * that configuration is ever wrong, instead of silently dropping and
     * rebuilding whatever database happens to be connected. Confirmed
     * this was not a theoretical risk: two ordinary regression runs
     * during this same remediation wiped real admin/demo accounts before
     * this guard, and the dedicated test database, existed.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstRunningTestsOutsideTheTestDatabase();
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('pgsql_secondary');
            DB::disconnect('pgsql');
        } finally {
            parent::tearDown();
        }
    }

    private function guardAgainstRunningTestsOutsideTheTestDatabase(): void
    {
        if (! app()->environment('testing')) {
            throw new \RuntimeException(
                'Refusing to run: APP_ENV is "'.app()->environment().'", not "testing". '.
                'This test suite performs real, uncommitted writes and destructive schema '.
                'operations (migrate:fresh, truncate) against a real PostgreSQL connection.',
            );
        }

        $database = DB::connection('pgsql')->getDatabaseName();

        if (! str_ends_with($database, '_test')) {
            throw new \RuntimeException(sprintf(
                'Refusing to run: the "pgsql" connection resolves to database "%s", which does '.
                'not end in "_test". Several tests in this suite call migrate:fresh or '.
                'truncate() directly against this connection — running them against anything '.
                'other than a dedicated *_test database risks permanently destroying real '.
                'data. Check DB_DATABASE for the testing environment (see phpunit.xml).',
                $database,
            ));
        }
    }
}
