<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-0008: registration, login, logout, and "who am I" — Sanctum SPA
 * cookie session authentication. Every identifier here (`User.id`,
 * `Tenant.id`) is minted as a UUID v4 at this exact call site, resolving
 * what `TenantId`/`UserId`'s own docblocks name as "deferred to a
 * future application/HTTP layer."
 */
final class AuthController extends Controller
{
    /**
     * Creates exactly one Tenant and one User, atomically (ADR-0008) —
     * MVP's single-owner-per-Tenant shape, not a multi-user invite flow.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $name = $request->string('name')->toString();
        $email = $request->string('email')->toString();
        $password = $request->string('password')->toString();

        $user = DB::transaction(function () use ($name, $email, $password): User {
            /** @var User $user */
            $user = User::query()->create([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);

            Tenant::query()->create([
                'id' => (string) Str::uuid(),
                'owner_user_id' => $user->id,
            ]);

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $this->currentUserResponse($user)->setStatusCode(201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $this->currentUserResponse($user);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(status: 204);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->currentUserResponse($user);
    }

    private function currentUserResponse(User $user): JsonResponse
    {
        $tenant = $user->tenant;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'tenant' => $tenant === null ? null : [
                'id' => $tenant->id,
            ],
        ]);
    }
}
