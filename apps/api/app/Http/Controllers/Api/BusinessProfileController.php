<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\StoreBusinessProfileRequest;
use App\Http\Support\ChartOfAccountsPresetProvisioner;
use App\Http\Support\CurrentTenant;
use App\Models\BusinessProfile;
use Illuminate\Http\JsonResponse;

/**
 * SRS IAM-003 (Business Profile) over HTTP — an upsert-style single
 * resource per Tenant, mirroring `tenant_id` being
 * {@see BusinessProfile}'s own primary key: there is no "list" or
 * "create another" operation, only "show the one you have" and
 * "save it".
 */
final class BusinessProfileController extends Controller
{
    public function __construct(
        private readonly ChartOfAccountsPresetProvisioner $presetProvisioner,
    ) {}

    public function show(CurrentTenant $currentTenant): JsonResponse
    {
        $profile = BusinessProfile::query()->find($currentTenant->id()->toString());

        if ($profile === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->toArray($profile)]);
    }

    /**
     * Creates the Business Profile on first save, or updates it in
     * place thereafter — {@see ChartOfAccountsPresetProvisioner} (SRS
     * IAM-004) runs only on that first save, never on a later edit, so
     * changing an already-onboarded Tenant's business type never
     * re-seeds or duplicates preset Accounts.
     */
    public function store(StoreBusinessProfileRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $tenantId = $currentTenant->id();
        $isFirstSave = BusinessProfile::query()->find($tenantId->toString()) === null;

        $profile = BusinessProfile::query()->updateOrCreate(
            ['tenant_id' => $tenantId->toString()],
            [
                'legal_name' => $request->string('legal_name')->toString(),
                'registration_number' => $request->has('registration_number') ? $request->string('registration_number')->toString() : null,
                'tin' => $request->has('tin') ? $request->string('tin')->toString() : null,
                'address_line1' => $request->string('address_line1')->toString(),
                'address_line2' => $request->has('address_line2') ? $request->string('address_line2')->toString() : null,
                'city' => $request->string('city')->toString(),
                'state' => $request->string('state')->toString(),
                'postcode' => $request->string('postcode')->toString(),
                'business_type' => $request->string('business_type')->toString(),
                'financial_year_start_month' => $request->integer('financial_year_start_month'),
            ],
        );

        // On first creation, `timezone` is populated only by the
        // schema's own `DEFAULT` — Eloquent's `create()` never refreshes
        // a DB-computed default into the in-memory model, so without
        // this the response below would report `timezone: null` even
        // though the row itself is correct.
        $profile->refresh();

        if ($isFirstSave) {
            $this->presetProvisioner->provisionFor($tenantId);
        }

        return response()->json(['data' => $this->toArray($profile)], $isFirstSave ? 201 : 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(BusinessProfile $profile): array
    {
        return [
            'legal_name' => $profile->legal_name,
            'registration_number' => $profile->registration_number,
            'tin' => $profile->tin,
            'address_line1' => $profile->address_line1,
            'address_line2' => $profile->address_line2,
            'city' => $profile->city,
            'state' => $profile->state,
            'postcode' => $profile->postcode,
            'business_type' => $profile->business_type,
            'financial_year_start_month' => $profile->financial_year_start_month,
            'timezone' => $profile->timezone,
            'is_complete' => $profile->isComplete(),
        ];
    }
}
