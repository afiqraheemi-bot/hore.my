<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A User's acceptance of a versioned policy document (M16, SRS IAM-001)
 * — append-only, mirroring `AuditEvent`'s own immutability convention:
 * there is no `update()` path anywhere in this codebase for this model,
 * and none should ever be added.
 *
 * @property string $id
 * @property string $user_id
 * @property string $consent_type
 * @property string $version
 * @property Carbon $accepted_at
 * @property-read User $user
 */
#[Fillable(['id', 'user_id', 'consent_type', 'version', 'accepted_at'])]
class Consent extends Model
{
    public const TERMS_OF_SERVICE = 'terms_of_service';

    public const CURRENT_TERMS_VERSION = '1.0';

    /** @see User::$connection for the identical reasoning. */
    protected $connection = 'pgsql';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }
}
