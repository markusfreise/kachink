<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Browser-based sign-in for the menubar app.
 *
 * 1. App: POST /auth/device/start -> code; opens <web>/connect?code=...
 * 2. Browser (signed in): POST /auth/device/{code}/approve -> token stored under the code
 * 3. App polls GET /auth/device/{code} until it receives the token (one-time)
 */
class DeviceAuthController extends Controller
{
    private const TTL_SECONDS = 600;

    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $code = Str::random(40);
        Cache::put($this->key($code), [
            'status' => 'pending',
            'device_name' => $validated['device_name'],
        ], self::TTL_SECONDS);

        return response()->json([
            'data' => [
                'code' => $code,
                'expires_in' => self::TTL_SECONDS,
                'poll_interval' => 2,
            ],
        ]);
    }

    /** Called by the browser to show what is being approved. */
    public function show(string $code): JsonResponse
    {
        $entry = Cache::get($this->key($code));
        if (! $entry) {
            abort(404, 'This connection request has expired.');
        }

        return response()->json(['data' => [
            'status' => $entry['status'],
            'device_name' => $entry['device_name'],
        ]]);
    }

    /** Polled by the app. */
    public function poll(string $code): JsonResponse
    {
        $entry = Cache::get($this->key($code));
        if (! $entry) {
            abort(404, 'This connection request has expired.');
        }

        if ($entry['status'] === 'denied') {
            Cache::forget($this->key($code));

            return response()->json(['data' => ['status' => 'denied']], 403);
        }

        if ($entry['status'] !== 'approved') {
            return response()->json(['data' => ['status' => 'pending']], 202);
        }

        // One-time hand-over
        Cache::forget($this->key($code));

        return response()->json(['data' => [
            'status' => 'approved',
            'token' => $entry['token'],
            'organization_id' => $entry['organization_id'],
            'user' => $entry['user'],
        ]]);
    }

    public function approve(Request $request, string $code): JsonResponse
    {
        $entry = Cache::get($this->key($code));
        if (! $entry || $entry['status'] !== 'pending') {
            abort(404, 'This connection request has expired.');
        }

        $validated = $request->validate([
            'organization_id' => ['nullable', 'uuid'],
        ]);

        $user = $request->user();
        $organizationId = $validated['organization_id'] ?? null;
        if ($organizationId && ! $user->organizations()->where('organizations.id', $organizationId)->exists()) {
            abort(403, 'You are not a member of this organization.');
        }
        $organizationId ??= $user->organizations()->value('organizations.id');

        $token = $user->createToken($entry['device_name'])->plainTextToken;

        Cache::put($this->key($code), [
            'status' => 'approved',
            'device_name' => $entry['device_name'],
            'token' => $token,
            'organization_id' => $organizationId,
            'user' => (new UserResource($user))->resolve(),
        ], 120);

        return response()->json(['data' => ['status' => 'approved', 'device_name' => $entry['device_name']]]);
    }

    public function deny(string $code): JsonResponse
    {
        $entry = Cache::get($this->key($code));
        if ($entry) {
            Cache::put($this->key($code), ['status' => 'denied', 'device_name' => $entry['device_name']], 120);
        }

        return response()->json(['data' => ['status' => 'denied']]);
    }

    private function key(string $code): string
    {
        return 'device_auth:' . $code;
    }
}
