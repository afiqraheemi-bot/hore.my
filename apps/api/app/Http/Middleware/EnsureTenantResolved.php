<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\Tenancy\TenantId;
use App\Http\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the authenticated User's Tenant and binds it as
 * {@see CurrentTenant} for this request (ADR-0008) — defense-in-depth
 * enforced at the HTTP boundary, in addition to (never instead of)
 * Accounting Core's own domain-level tenant checks (e.g.
 * `RejectedAccountReferenceException`). Runs after `auth:sanctum`, so
 * `$request->user()` is always present here; it aborts only if that
 * User has no Tenant, which every fully-registered User has exactly
 * one of (ADR-0008) — a defensive check, not an expected path.
 */
final class EnsureTenantResolved
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->user()?->tenant;

        abort_if($tenant === null, 403, 'No Tenant is associated with the authenticated User.');

        app()->instance(CurrentTenant::class, new CurrentTenant(TenantId::of($tenant->id)));

        return $next($request);
    }
}
