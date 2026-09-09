<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // Only members of the current organization
        $query = app('current_organization')->users()->orderBy('users.name');

        if ($request->has('filter.is_active')) {
            $query->where('users.is_active', $request->boolean('filter.is_active'));
        }

        return UserResource::collection($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role'  => ['sometimes', 'in:admin,member'],
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'role'     => $validated['role'] ?? 'member',
            'password' => Hash::make(Str::random(32)),
        ]);

        app('current_organization')->users()->attach($user->id, ['role' => 'member']);

        Password::sendResetLink(['email' => $user->email]);

        return response()->json(['data' => new UserResource($user)], 201);
    }

    public function show(User $user): JsonResponse
    {
        $this->assertOrgMember($user);

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            abort(403);
        }

        $this->assertOrgMember($user);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'in:admin,member'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user->update($validated);

        if (array_key_exists('is_active', $validated) && !$validated['is_active']) {
            $user->tokens()->delete();
        }

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    private function assertOrgMember(User $user): void
    {
        $isMember = app('current_organization')->users()->where('users.id', $user->id)->exists();

        if (!$isMember) {
            abort(404);
        }
    }
}
