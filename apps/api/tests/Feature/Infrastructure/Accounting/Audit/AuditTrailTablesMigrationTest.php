<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Accounting\Audit;

use App\Infrastructure\Accounting\Audit\AuditEventRepository;
use App\Infrastructure\Accounting\Posting\JournalEvidenceLinkRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CleansSharedAccountingTables;
use Tests\Feature\Infrastructure\Accounting\Journal\JournalsAndJournalLinesTableMigrationTest;
use Tests\Feature\Infrastructure\Accounting\Posting\PostingIdempotencyKeysTableMigrationTest;
use Tests\TestCase;

/**
 * Integration-level proof for the production `audit_events` and
 * `journal_evidence_links` table migrations (M6,
 * `database/migrations/2026_09_06_200000_create_audit_events_table.php`,
 * `database/migrations/2026_09_06_210000_create_journal_evidence_links_table.php`),
 * exercised against a real PostgreSQL instance and the real Laravel
 * migrator — never a hand-copied re-implementation of the schema, and
 * never SQLite as evidence of PostgreSQL-specific constraint behavior
 * (composite foreign key, `CHECK` constraint), mirroring the precedent
 * already established for
 * {@see PostingIdempotencyKeysTableMigrationTest}.
 *
 * This is schema-only coverage — proving the database primitives
 * {@see AuditEventRepository} and
 * {@see JournalEvidenceLinkRepository}
 * rely on: the tenant-safe composite foreign key onto `journals`, the
 * canonical-value `CHECK` on `audit_events.action`, and the
 * `UNIQUE (tenant_id, journal_id, evidence_reference)` constraint on
 * `journal_evidence_links`.
 *
 * `journals` and `accounts` are real dependencies of both tables' own
 * composite foreign keys, so this class also ensures both existing
 * production migrations are applied first.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class AuditTrailTablesMigrationTest extends TestCase
{
    use CleansSharedAccountingTables;

    private const AUDIT_EVENT_TABLE = 'audit_events';

    private const EVIDENCE_LINK_TABLE = 'journal_evidence_links';

    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const AUDIT_EVENT_MIGRATION_PATH = 'database/migrations/2026_09_06_200000_create_audit_events_table.php';

    private const EVIDENCE_LINK_MIGRATION_PATH = 'database/migrations/2026_09_06_210000_create_journal_evidence_links_table.php';

    private const JOURNAL_MIGRATION_PATH = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';

    private const CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php';

    private const FINANCIAL_DATE_MIGRATION_PATH = 'database/migrations/2026_09_06_230000_add_financial_date_and_posted_at_to_journals_table.php';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        // Was a hand-rolled, class-local list of tables to clean before
        // deleting `journals` — went stale when M20/M21 added
        // `invoices`/`invoice_lines`/`payments`/`payment_allocations`,
        // each holding a foreign key onto `journals`, without ever
        // being added here (confirmed as a real, intermittent
        // `QueryException` during a 2026-09-11 audit remediation pass —
        // see `CleansSharedAccountingTables`'s own docblock). Replaced
        // with the shared, canonical cleanup this trait exists for.
        self::cleanSharedAccountingTables();

        $this->seedJournal('tenant-0001', 'journal-0001');
        $this->seedJournal('tenant-0002', 'journal-0002');
    }

    // --- audit_events -----------------------------------------------------

    public function test_audit_events_table_creates_successfully(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::AUDIT_EVENT_TABLE));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns(self::AUDIT_EVENT_TABLE, [
            'tenant_id', 'actor', 'source', 'action', 'journal_id', 'policy_version', 'occurred_at',
        ]));
    }

    public function test_valid_audit_event_inserts(): void
    {
        $this->insertAuditEvent('tenant-0001', 'journal-0001', 'JournalPosted');

        $this->assertSame(1, DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->count());
    }

    public function test_every_canonical_action_value_is_accepted(): void
    {
        foreach (['JournalPosted', 'JournalReversed', 'JournalReplaced'] as $action) {
            DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->insert([
                'tenant_id' => 'tenant-0001',
                'actor' => 'actor-0001',
                'source' => 'source-0001',
                'action' => $action,
                'journal_id' => 'journal-0001',
            ]);
        }

        $this->assertSame(3, DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->count());
    }

    public function test_a_non_canonical_action_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->insert([
            'tenant_id' => 'tenant-0001',
            'actor' => 'actor-0001',
            'source' => 'source-0001',
            'action' => 'NotACanonicalAction',
            'journal_id' => 'journal-0001',
        ]);
    }

    public function test_audit_event_cannot_reference_a_nonexistent_journal(): void
    {
        $this->expectException(QueryException::class);

        $this->insertAuditEvent('tenant-0001', 'journal-does-not-exist', 'JournalPosted');
    }

    public function test_audit_event_cannot_reference_another_tenants_journal(): void
    {
        $this->expectException(QueryException::class);

        // journal-0002 exists, but only under tenant-0002.
        $this->insertAuditEvent('tenant-0001', 'journal-0002', 'JournalPosted');
    }

    public function test_occurred_at_is_present_and_database_assigned(): void
    {
        $this->insertAuditEvent('tenant-0001', 'journal-0001', 'JournalPosted');

        $row = DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->where('journal_id', 'journal-0001')->first();

        $this->assertNotNull($row->occurred_at);
    }

    public function test_policy_version_is_nullable(): void
    {
        $this->insertAuditEvent('tenant-0001', 'journal-0001', 'JournalPosted');

        $row = DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->where('journal_id', 'journal-0001')->first();

        $this->assertNull($row->policy_version);
    }

    // --- journal_evidence_links ---------------------------------------------

    public function test_evidence_links_table_creates_successfully(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::EVIDENCE_LINK_TABLE));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns(self::EVIDENCE_LINK_TABLE, [
            'tenant_id', 'journal_id', 'evidence_reference', 'linked_at',
        ]));
    }

    public function test_valid_evidence_link_inserts(): void
    {
        $this->insertEvidenceLink('tenant-0001', 'journal-0001', 'evidence-0001');

        $this->assertSame(1, DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)->count());
    }

    public function test_the_same_evidence_reference_cannot_be_linked_to_the_same_journal_twice(): void
    {
        $this->insertEvidenceLink('tenant-0001', 'journal-0001', 'evidence-0001');

        $this->expectException(QueryException::class);

        $this->insertEvidenceLink('tenant-0001', 'journal-0001', 'evidence-0001');
    }

    public function test_the_same_evidence_reference_may_link_to_a_different_journal(): void
    {
        $this->insertEvidenceLink('tenant-0001', 'journal-0001', 'evidence-shared');
        $this->insertEvidenceLink('tenant-0002', 'journal-0002', 'evidence-shared');

        $this->assertSame(2, DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)->count());
    }

    public function test_evidence_link_cannot_reference_a_nonexistent_journal(): void
    {
        $this->expectException(QueryException::class);

        $this->insertEvidenceLink('tenant-0001', 'journal-does-not-exist', 'evidence-0001');
    }

    public function test_evidence_link_cannot_reference_another_tenants_journal(): void
    {
        $this->expectException(QueryException::class);

        $this->insertEvidenceLink('tenant-0001', 'journal-0002', 'evidence-0001');
    }

    public function test_linked_at_is_present_and_database_assigned(): void
    {
        $this->insertEvidenceLink('tenant-0001', 'journal-0001', 'evidence-0001');

        $row = DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)->where('journal_id', 'journal-0001')->first();

        $this->assertNotNull($row->linked_at);
    }

    // --- Rollback -----------------------------------------------------------

    public function test_audit_events_migration_rollback_succeeds_cleanly(): void
    {
        // Guarantee this migration is the newest batch first —
        // `journal_evidence_links` (applied after it in
        // `ensureMigrated()`) would otherwise be the newest batch, and
        // `migrate:rollback --path=X`'s batch-oriented semantics would
        // silently roll back nothing.
        self::forceCleanMigration(self::AUDIT_EVENT_MIGRATION_PATH, [self::AUDIT_EVENT_TABLE]);

        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::AUDIT_EVENT_TABLE));

        Artisan::call('migrate:rollback', [
            '--database' => 'pgsql',
            '--path' => self::AUDIT_EVENT_MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertFalse(Schema::connection('pgsql')->hasTable(self::AUDIT_EVENT_TABLE));

        Artisan::call('migrate', [
            '--database' => 'pgsql',
            '--path' => self::AUDIT_EVENT_MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::AUDIT_EVENT_TABLE));
    }

    public function test_evidence_links_migration_rollback_succeeds_cleanly(): void
    {
        // See the identical guarantee in the `audit_events` rollback
        // test above for why this is required.
        self::forceCleanMigration(self::EVIDENCE_LINK_MIGRATION_PATH, [self::EVIDENCE_LINK_TABLE]);

        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::EVIDENCE_LINK_TABLE));

        Artisan::call('migrate:rollback', [
            '--database' => 'pgsql',
            '--path' => self::EVIDENCE_LINK_MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertFalse(Schema::connection('pgsql')->hasTable(self::EVIDENCE_LINK_TABLE));

        Artisan::call('migrate', [
            '--database' => 'pgsql',
            '--path' => self::EVIDENCE_LINK_MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::EVIDENCE_LINK_TABLE));
    }

    private function insertAuditEvent(string $tenantId, string $journalId, string $action): void
    {
        DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->insert([
            'tenant_id' => $tenantId,
            'actor' => 'actor-0001',
            'source' => 'source-0001',
            'action' => $action,
            'journal_id' => $journalId,
        ]);
    }

    private function insertEvidenceLink(string $tenantId, string $journalId, string $evidenceReference): void
    {
        DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)->insert([
            'tenant_id' => $tenantId,
            'journal_id' => $journalId,
            'evidence_reference' => $evidenceReference,
        ]);
    }

    private function seedJournal(string $tenantId, string $journalId): void
    {
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->insert([
            'tenant_id' => $tenantId,
            'journal_id' => $journalId,
            'state' => 'Draft',
            'financial_date' => '2026-08-15',
        ]);
    }

    private function ensureMigrated(): void
    {
        if (self::$skipReason !== null || self::$migrated) {
            return;
        }

        try {
            DB::connection('pgsql')->select('select 1');
        } catch (\Throwable $e) {
            self::$skipReason = sprintf(
                'A real PostgreSQL instance is not reachable via the "pgsql" connection (%s). '
                .'Run `docker compose up -d postgres` (see docker-compose.yml) to enable this integration test.',
                $e->getMessage(),
            );

            return;
        }

        if (! Schema::connection('pgsql')->hasTable(self::ACCOUNT_TABLE)) {
            self::forceCleanMigration(self::ACCOUNTS_MIGRATION_PATH, [self::ACCOUNT_TABLE]);
        }

        if (! Schema::connection('pgsql')->hasTable(self::JOURNAL_TABLE)) {
            self::forceCleanMigration(self::JOURNAL_MIGRATION_PATH, [self::LINE_TABLE, self::JOURNAL_TABLE]);
            self::forceCleanMigration(self::CORRECTION_MIGRATION_PATH, []);
            self::forceCleanMigration(self::FINANCIAL_DATE_MIGRATION_PATH, []);
        }

        if (! Schema::connection('pgsql')->hasColumn(self::JOURNAL_TABLE, 'financial_date')) {
            self::forceCleanMigration(self::FINANCIAL_DATE_MIGRATION_PATH, []);
        }

        // `migrate:rollback --path=X` only rolls back the most recent
        // *batch* — dropping the tables directly and clearing their
        // migration tracking rows is unambiguous regardless of batch
        // history, exactly as already established for
        // {@see JournalsAndJournalLinesTableMigrationTest}.
        self::forceCleanMigration(self::AUDIT_EVENT_MIGRATION_PATH, [self::AUDIT_EVENT_TABLE]);
        self::forceCleanMigration(self::EVIDENCE_LINK_MIGRATION_PATH, [self::EVIDENCE_LINK_TABLE]);

        self::$migrated = true;
    }

    /**
     * @param  list<string>  $tables
     */
    private static function forceCleanMigration(string $migrationPath, array $tables): void
    {
        foreach ($tables as $table) {
            Schema::connection('pgsql')->dropIfExists($table);
        }

        if (Schema::connection('pgsql')->hasTable('migrations')) {
            DB::connection('pgsql')->table('migrations')
                ->where('migration', pathinfo($migrationPath, PATHINFO_FILENAME))
                ->delete();
        }

        Artisan::call('migrate', [
            '--database' => 'pgsql',
            '--path' => $migrationPath,
            '--realpath' => false,
            '--force' => true,
        ]);
    }
}
