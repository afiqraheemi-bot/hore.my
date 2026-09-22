<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\MyInvois\MyInvoisEnvironment;
use App\Http\Controllers\Controller;
use App\Http\Requests\MyInvois\StoreMyInvoisCredentialRequest;
use App\Http\Support\CurrentTenant;
use App\Models\MyInvoisCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * `MyInvoisCredential` management over HTTP (AETS-013 v0.1.0 §5) — a
 * Tenant's own Sandbox/Production client ID/secret. Mirrors
 * `BusinessProfileController`'s own upsert-per-key shape, except the
 * key here is (Tenant, Environment) rather than Tenant alone, so there
 * are two possible rows instead of one.
 *
 * **The secret is never serialized.** {@see index()} reports only
 * whether a credential exists and its Client ID; there is no `show`
 * action that returns a single credential's secret, and {@see store()}
 * echoes back the same masked shape it just saved — never the
 * plaintext the request carried.
 */
final class MyInvoisCredentialController extends Controller
{
    public function index(CurrentTenant $currentTenant): JsonResponse
    {
        $rows = MyInvoisCredential::query()
            ->where('tenant_id', $currentTenant->id()->toString())
            ->get(['environment', 'client_id']);

        $byEnvironment = $rows->keyBy(static fn (MyInvoisCredential $row): string => $row->environment->value);

        return response()->json([
            'data' => array_map(
                static fn (MyInvoisEnvironment $environment): array => self::toArray($environment, $byEnvironment->get($environment->value)),
                MyInvoisEnvironment::cases(),
            ),
        ]);
    }

    public function store(StoreMyInvoisCredentialRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $environment = MyInvoisEnvironment::from($request->string('environment')->toString());
        $tenantId = $currentTenant->id()->toString();

        $existing = MyInvoisCredential::query()
            ->where('tenant_id', $tenantId)
            ->where('environment', $environment->value)
            ->first();

        $id = $existing !== null ? $existing->id : (string) Str::uuid();

        $credential = MyInvoisCredential::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'environment' => $environment->value],
            [
                'id' => $id,
                'client_id' => $request->string('client_id')->toString(),
                'client_secret' => $request->string('client_secret')->toString(),
            ],
        );

        return response()->json(
            ['data' => self::toArray($environment, $credential)],
            $existing === null ? 201 : 200,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(MyInvoisEnvironment $environment, ?MyInvoisCredential $credential): array
    {
        return [
            'environment' => $environment->value,
            'configured' => $credential !== null,
            'client_id' => $credential?->client_id,
        ];
    }
}
