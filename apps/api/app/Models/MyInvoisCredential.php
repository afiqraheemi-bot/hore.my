<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\MyInvois\MyInvoisEnvironment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Tenant's own LHDN MyInvois client ID/secret for one Environment
 * (AETS-013 v0.1.0 §5) — at most one row per (Tenant, Environment)
 * pair (MYI-001), enforced by the owning migration's unique
 * constraint, not by this class.
 *
 * **Deliberately a plain Eloquent model, not a hand-rolled Domain
 * aggregate** — mirrors `BusinessProfile`'s own documented reasoning
 * (ADR-0008): this carries no invariant beyond field presence and
 * per-Environment uniqueness, both already expressed by the schema
 * and this class's `casts()`/validation layer; a full
 * Domain/Infrastructure split would add ceremony for a settings-shaped
 * record.
 *
 * **`client_secret` is encrypted at rest via Eloquent's own `encrypted`
 * cast** — this codebase's first use of that mechanism (AETS-013
 * §5/§9, MYI-002). Every read boundary in `MyInvoisCredentialController`
 * masks this value; `$model->client_secret` inside the application
 * yields the decrypted plaintext, which is why no controller action in
 * this version ever serializes the model directly.
 *
 * @property string $id
 * @property string $tenant_id
 * @property MyInvoisEnvironment $environment
 * @property string $client_id
 * @property string $client_secret
 * @property-read Tenant $tenant
 */
#[Fillable(['id', 'tenant_id', 'environment', 'client_id', 'client_secret'])]
final class MyInvoisCredential extends Model
{
    protected $table = 'myinvois_credentials';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'environment' => MyInvoisEnvironment::class,
            'client_secret' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
