<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Shared\Tenancy\TenantId;
use App\Http\Middleware\EnsureTenantResolved;

/**
 * The authenticated request's resolved Tenant (ADR-0008) — bound into
 * the container per-request by {@see EnsureTenantResolved},
 * so a controller can request it by type-hint instead of re-deriving it
 * from `Auth::user()` itself. Application instances remain stateless:
 * this binding lives only for the lifetime of the request that created
 * it (Master Context §12).
 */
final class CurrentTenant
{
    public function __construct(
        private readonly TenantId $tenantId,
    ) {}

    public function id(): TenantId
    {
        return $this->tenantId;
    }
}
