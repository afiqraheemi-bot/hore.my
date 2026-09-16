<?php

declare(strict_types=1);

use App\Infrastructure\Evidence\EvidenceFileStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for Evidence (AETS-015 §4) — a Tenant-owned,
 * immutable record of one uploaded source file. Mirrors
 * `bank_accounts`' own reference-data shape: no FK onto `journals`,
 * since uploading Evidence produces no Journal by itself.
 *
 * **No `updated_at`; immutable once recorded** (`EVI-005`) — mirrors
 * every other append-only-by-design table in this schema (`matches`,
 * `audit_events`). There is no update or delete operation in
 * AETS-015's scope.
 *
 * **`storage_path` is opaque and never derived from
 * `original_filename`** (`EVI-003`) — collision/path-traversal safety;
 * see {@see EvidenceFileStore}.
 */
return new class extends Migration
{
    private const TABLE = 'evidence';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->string('id', 64);
            $table->string('tenant_id', 64);
            $table->string('original_filename', 255);
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('byte_size');
            $table->string('sha256_digest', 64);
            $table->string('storage_path', 255);
            $table->string('uploaded_by', 64);
            $table->timestamp('uploaded_at');

            $table->primary('id');
            $table->index('tenant_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('alter table '.self::TABLE.' add constraint evidence_byte_size_positive check (byte_size > 0)');
            DB::statement('alter table '.self::TABLE." add constraint evidence_sha256_digest_canonical check (sha256_digest ~ '^[0-9a-f]{64}$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
