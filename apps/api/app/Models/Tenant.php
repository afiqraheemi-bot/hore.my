<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * ADR-0008: a Tenant owns a reference to its single owning User
 * (`owner_user_id`) — not the reverse. `id` is an opaque UUID string
 * minted alongside its owner at registration, matching the
 * `TenantId`/`AccountId`/`JournalId` convention.
 *
 * This Eloquent model is the entire Tenant "aggregate" at MVP —
 * deliberately: MVP has no invariant beyond "one owner," so no
 * hand-rolled Domain aggregate is introduced for it (ADR-0008,
 * "Identity module stays deliberately thin").
 *
 * @property string $id
 * @property string $owner_user_id
 * @property-read User $owner
 * @property-read BusinessProfile|null $businessProfile
 */
#[Fillable(['id', 'owner_user_id'])]
class Tenant extends Model
{
    /**
     * Always the real PostgreSQL connection — see {@see User}'s
     * own `$connection` property for the identical reasoning.
     */
    protected $connection = 'pgsql';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id', 'id');
    }

    /**
     * `null` until the owner completes SRS IAM-003's Business Profile
     * step of onboarding — registration alone does not create one.
     *
     * @return HasOne<BusinessProfile, $this>
     */
    public function businessProfile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class, 'tenant_id', 'id');
    }
}
