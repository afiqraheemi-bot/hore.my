<?php

declare(strict_types=1);

namespace App\Models;

use App\Http\Support\ChartOfAccountsPresetProvisioner;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The per-Tenant Business Profile (M16, SRS IAM-003) — legal name,
 * registration number, TIN, address, business type, and financial year
 * start month a Malaysian micro-business/SME provides during
 * onboarding.
 *
 * **`tenant_id` is the primary key** — see the owning migration's own
 * docblock for why no separate surrogate identity is invented.
 *
 * **Deliberately a plain Eloquent model, not a hand-rolled Domain
 * aggregate** — mirrors `Tenant`'s own documented reasoning (ADR-0008:
 * "Identity module stays deliberately thin"). Business Profile carries
 * no invariant beyond field-presence completeness, which
 * {@see isComplete()} expresses directly; introducing a full
 * Domain/Infrastructure split here (opaque identifier Value Object,
 * separate repository) would add ceremony this module's own governing
 * ADR explicitly rejects.
 *
 * @property string $tenant_id
 * @property string $legal_name
 * @property string|null $registration_number
 * @property string|null $tin
 * @property string $address_line1
 * @property string|null $address_line2
 * @property string $city
 * @property string $state
 * @property string $postcode
 * @property string $business_type
 * @property int $financial_year_start_month
 * @property string $timezone
 * @property string|null $msic_code
 * @property string|null $sst_registration_number
 * @property-read Tenant $tenant
 */
#[Fillable([
    'tenant_id', 'legal_name', 'registration_number', 'tin',
    'address_line1', 'address_line2', 'city', 'state', 'postcode',
    'business_type', 'financial_year_start_month', 'timezone',
    'msic_code', 'sst_registration_number',
])]
class BusinessProfile extends Model
{
    /**
     * The MVP business types SRS IAM-004's Chart-of-Accounts preset
     * suggestion keys off of — a deliberately small, extensible set,
     * not an exhaustive industry taxonomy (see
     * {@see ChartOfAccountsPresetProvisioner}'s own
     * docblock for the tracked single-preset limitation).
     *
     * @var list<string>
     */
    public const BUSINESS_TYPES = ['Retail', 'FoodAndBeverage', 'Services', 'Trading', 'Other'];

    /**
     * @var list<string>
     */
    public const STATES = [
        'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang',
        'Perak', 'Perlis', 'Pulau Pinang', 'Sabah', 'Sarawak', 'Selangor', 'Terengganu',
        'WP Kuala Lumpur', 'WP Labuan', 'WP Putrajaya',
    ];

    /** @see User::$connection for the identical reasoning. */
    protected $connection = 'pgsql';

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'financial_year_start_month' => 'integer',
        ];
    }

    /**
     * SRS IAM-003's "kelengkapan profil dikuatkuasa" (profile
     * completeness enforced) — every field SRS IAM-003 names is
     * present. A profile with no registration number or TIN yet (a
     * newly-registered sole proprietor may not have either) is still a
     * valid, saveable row — it is simply not yet "complete".
     */
    public function isComplete(): bool
    {
        return $this->legal_name !== ''
            && $this->registration_number !== null && $this->registration_number !== ''
            && $this->tin !== null && $this->tin !== ''
            && $this->address_line1 !== ''
            && $this->city !== ''
            && $this->state !== ''
            && $this->postcode !== ''
            && $this->business_type !== ''
            && $this->financial_year_start_month >= 1 && $this->financial_year_start_month <= 12;
    }
}
