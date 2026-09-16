<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Evidence;

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Evidence\EvidenceId;
use App\Domain\Evidence\EvidenceUploadService;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Evidence\EvidenceFileStore;
use App\Infrastructure\Evidence\EvidenceRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Domain\Banking\BankStatementImportServiceIntegrationTest;
use Tests\TestCase;

/**
 * Integration-level proof for {@see EvidenceUploadService} (AETS-015
 * §5) — exercised against a real PostgreSQL instance and a real
 * (fake, per-test-isolated) filesystem disk, never a mock of either.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class EvidenceUploadServiceIntegrationTest extends TestCase
{
    private const TABLE = 'evidence';

    private const MIGRATION_PATH = 'database/migrations/2026_09_16_030000_create_evidence_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private EvidenceUploadService $uploadService;

    private EvidenceRepository $evidenceRepository;

    private EvidenceFileStore $fileStore;

    private TenantId $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        if (! Schema::connection('pgsql')->hasTable(self::TABLE)) {
            self::forceCleanMigration();
        }

        DB::connection('pgsql')->table(self::TABLE)->delete();

        Storage::fake('local');

        $connection = DB::connection('pgsql');
        $this->tenant = TenantId::of('tenant-0001');
        $this->fileStore = new EvidenceFileStore(Storage::disk('local'));
        $this->evidenceRepository = new EvidenceRepository($connection);
        $this->uploadService = new EvidenceUploadService($connection, $this->fileStore, $this->evidenceRepository);
    }

    public function test_a_valid_upload_persists_a_record_and_the_file(): void
    {
        $contents = 'this is a test receipt fixture, not a real accounting oracle';

        $evidence = $this->uploadService->upload(
            $this->tenant,
            'receipt.jpg',
            'image/jpeg',
            $contents,
            ActorReference::of('actor-0001'),
        );

        $this->assertSame('receipt.jpg', $evidence->originalFilename());
        $this->assertSame(hash('sha256', $contents), $evidence->sha256Digest());
        $this->assertSame(strlen($contents), $evidence->byteSize());

        Storage::disk('local')->assertExists($evidence->storagePath());
        $this->assertSame($contents, Storage::disk('local')->get($evidence->storagePath()));

        $reloaded = $this->evidenceRepository->findById($this->tenant, $evidence->id());
        $this->assertNotNull($reloaded);
        $this->assertTrue($reloaded->equals($evidence));
        $this->assertSame($contents, Storage::disk('local')->get($reloaded->storagePath()));
    }

    public function test_the_digest_is_computed_server_side_from_actual_content(): void
    {
        $evidence = $this->uploadService->upload(
            $this->tenant,
            'receipt.jpg',
            'image/jpeg',
            'exact content the server actually receives',
            ActorReference::of('actor-0001'),
        );

        // EVI-002: proven by construction — the service never accepts a
        // caller-supplied digest parameter at all; the digest returned
        // can only be the server's own hash of the content it stored.
        $this->assertSame(hash('sha256', 'exact content the server actually receives'), $evidence->sha256Digest());
    }

    public function test_the_storage_path_is_not_derived_from_the_original_filename(): void
    {
        $evidence = $this->uploadService->upload(
            $this->tenant,
            '../../etc/passwd',
            'image/jpeg',
            'content',
            ActorReference::of('actor-0001'),
        );

        $this->assertStringNotContainsString('etc/passwd', $evidence->storagePath());
        $this->assertStringNotContainsString('..', $evidence->storagePath());
        $this->assertStringContainsString($this->tenant->toString(), $evidence->storagePath());
        $this->assertStringContainsString($evidence->id()->toString(), $evidence->storagePath());
    }

    public function test_two_different_tenants_uploading_do_not_collide(): void
    {
        $tenantB = TenantId::of('tenant-0002');

        $evidenceA = $this->uploadService->upload($this->tenant, 'a.jpg', 'image/jpeg', 'content A', ActorReference::of('actor-0001'));
        $evidenceB = $this->uploadService->upload($tenantB, 'b.jpg', 'image/jpeg', 'content B', ActorReference::of('actor-0002'));

        $this->assertNull($this->evidenceRepository->findById($tenantB, $evidenceA->id()));
        $this->assertNull($this->evidenceRepository->findById($this->tenant, $evidenceB->id()));
        $this->assertNotNull($this->evidenceRepository->findById($this->tenant, $evidenceA->id()));
        $this->assertNotNull($this->evidenceRepository->findById($tenantB, $evidenceB->id()));
    }

    /**
     * EVI-001: a persistence failure leaves neither the record nor the
     * file — proven with the same forced-constraint fault-injection
     * technique already established throughout this codebase (e.g.
     * {@see BankStatementImportServiceIntegrationTest}).
     */
    public function test_a_persistence_failure_leaves_no_orphaned_file(): void
    {
        DB::connection('pgsql')->statement('alter table '.self::TABLE.' add constraint evidence_test_forced_failure check (1 = 0) not valid');

        try {
            try {
                $this->uploadService->upload($this->tenant, 'receipt.jpg', 'image/jpeg', 'content', ActorReference::of('actor-0001'));
                $this->fail('Expected the forced constraint to reject this insert.');
            } catch (QueryException) {
                // expected
            }

            $files = Storage::disk('local')->allFiles('evidence');
            $this->assertSame([], $files, 'The written file must be deleted when persistence fails.');
            $this->assertSame(0, DB::connection('pgsql')->table(self::TABLE)->count());
        } finally {
            DB::connection('pgsql')->statement('alter table '.self::TABLE.' drop constraint evidence_test_forced_failure');
        }
    }

    public function test_downloading_a_stored_file_returns_the_exact_original_bytes(): void
    {
        $contents = random_bytes(256);

        $evidence = $this->uploadService->upload($this->tenant, 'receipt.pdf', 'application/pdf', $contents, ActorReference::of('actor-0001'));

        $this->assertSame($contents, $this->fileStore->read($evidence->storagePath()));
    }

    public function test_find_by_id_returns_null_for_a_nonexistent_id(): void
    {
        $this->assertNull($this->evidenceRepository->findById($this->tenant, EvidenceId::of('evidence-does-not-exist')));
    }

    public function test_reconstitution_preserves_every_field_exactly(): void
    {
        $contents = 'exact-content-fixture';
        $evidence = $this->uploadService->upload($this->tenant, 'invoice.png', 'image/png', $contents, ActorReference::of('actor-0007'));

        $reloaded = $this->evidenceRepository->findById($this->tenant, $evidence->id());

        $this->assertNotNull($reloaded);
        $this->assertTrue($reloaded->id()->equals($evidence->id()));
        $this->assertTrue($reloaded->tenantId()->equals($evidence->tenantId()));
        $this->assertSame($evidence->originalFilename(), $reloaded->originalFilename());
        $this->assertSame($evidence->mimeType(), $reloaded->mimeType());
        $this->assertSame($evidence->byteSize(), $reloaded->byteSize());
        $this->assertSame($evidence->sha256Digest(), $reloaded->sha256Digest());
        $this->assertSame($evidence->storagePath(), $reloaded->storagePath());
        $this->assertTrue($reloaded->uploadedBy()->equals($evidence->uploadedBy()));
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

        if (! Schema::connection('pgsql')->hasTable(self::TABLE)) {
            self::forceCleanMigration();
        }

        self::$migrated = true;
    }

    private static function forceCleanMigration(): void
    {
        Schema::connection('pgsql')->dropIfExists(self::TABLE);

        if (Schema::connection('pgsql')->hasTable('migrations')) {
            DB::connection('pgsql')->table('migrations')
                ->where('migration', pathinfo(self::MIGRATION_PATH, PATHINFO_FILENAME))
                ->delete();
        }

        Artisan::call('migrate', ['--database' => 'pgsql', '--path' => self::MIGRATION_PATH, '--realpath' => false, '--force' => true]);
    }
}
