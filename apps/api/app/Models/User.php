<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * ADR-0008: `id` is an opaque UUID string minted by the registration
 * endpoint, never an auto-increment integer — matching the
 * `TenantId`/`AccountId`/`JournalId` convention used throughout
 * Accounting Core.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property-read Tenant|null $tenant
 */
#[Fillable(['id', 'name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Always the real PostgreSQL connection (Master Context §12,
     * ADR-0007) — never whatever `DB_CONNECTION` happens to default to
     * in a given environment (e.g. `sqlite` during a PHPUnit run),
     * matching every other Accounting Core repository's own "real
     * PostgreSQL always" convention.
     */
    protected $connection = 'pgsql';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The Tenant this User owns (ADR-0008: a Tenant references its
     * owner, never the reverse) — `null` only in a transient state
     * between User creation and Tenant creation inside the same
     * database transaction; every fully-registered User has exactly
     * one.
     *
     * @return HasOne<Tenant, $this>
     */
    public function tenant(): HasOne
    {
        return $this->hasOne(Tenant::class, 'owner_user_id', 'id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
