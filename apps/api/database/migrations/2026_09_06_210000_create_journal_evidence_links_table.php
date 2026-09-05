<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production schema for a Journal's Evidence Linkage (AETS-010 §9) — a
 * record associating one Journal with one Evidence Reference
 * (`App\Domain\Accounting\Posting\EvidenceReference`), committed
 * atomically with the Journal it links (AETS-002 invariant 2).
 *
 * **Not the Evidence record itself.** This table stores only the
 * opaque reference and which Journal it is linked to — it carries
 * nothing about the Evidence's own content (file, hash, uploader),
 * since that schema remains deferred to a future Document Processing
 * specification (AETS-010 §2.2). `evidence_reference` is `string(64)`,
 * matching `EvidenceReference`'s own defensive opaque-identifier bound
 * exactly.
 *
 * **Same-Tenant Journal integrity, by composite foreign key** —
 * `(tenant_id, journal_id) REFERENCES journals (tenant_id, journal_id)`,
 * the identical composite-FK convention already established by
 * `journal_lines`, `posting_idempotency_keys`,
 * `posting_source_fingerprints`, `audit_events` (this milestone), and
 * the correction-chain columns on `journals` itself (M5).
 *
 * **`UNIQUE (tenant_id, journal_id, evidence_reference)`** — the same
 * Evidence Reference cannot be linked to the same Journal twice; a
 * Posting Command that happened to carry a duplicate reference in its
 * own list produces one linkage row, not two.
 *
 * **`linked_at` is database-assigned**, mirroring
 * `posting_idempotency_keys.created_at` (M4-T15) and
 * `audit_events.occurred_at` (this milestone) exactly.
 *
 * **No linkage row is fabricated where no Evidence Reference is
 * supplied** (AETS-010 §9) — this table's own emptiness for a given
 * Journal is the expected, valid state for the overwhelming majority
 * of Journals today, since no Evidence-producing module exists yet
 * (AETS-010 §2.2).
 */
return new class extends Migration
{
    private const TABLE = 'journal_evidence_links';

    private const JOURNAL_TABLE = 'journals';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 64);
            $table->string('journal_id', 64);
            $table->string('evidence_reference', 64);
            $table->timestamp('linked_at')->useCurrent();

            $table->unique(['tenant_id', 'journal_id', 'evidence_reference']);

            $table->foreign(['tenant_id', 'journal_id'])
                ->references(['tenant_id', 'journal_id'])
                ->on(self::JOURNAL_TABLE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
