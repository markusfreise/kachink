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
        if (! $request->user()->isAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role' => ['sometimes', 'in:admin,member'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'] ?? 'member',
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
        if (! $request->user()->isAdmin()) {
            abort(403);
        }

        $this->assertOrgMember($user);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'in:admin,member'],
            'is_active' => ['sometimes', 'boolean'],
            'hourly_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        // The rate belongs to the membership, not the user account.
        if (array_key_exists('hourly_rate', $validated)) {
            app('current_organization')->users()->updateExistingPivot($user->id, ['hourly_rate' => $validated['hourly_rate']]);
            unset($validated['hourly_rate']);
        }

        $user->update($validated);
        $user = app('current_organization')->users()->where('users.id', $user->id)->first();

        if (array_key_exists('is_active', $validated) && ! $validated['is_active']) {
            $user->tokens()->delete();
        }

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Avatar image, stored in the public docroot so nginx serves it without
     * a storage symlink. Members change their own picture, admins anyone's.
     */
    public function uploadAvatar(Request $request, User $user): JsonResponse
    {
        $this->assertOrgMember($user);
        if (! $request->user()->isAdmin() && $request->user()->id !== $user->id) {
            abort(403);
        }

        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $this->removeAvatarFile($user);

        $file = $request->file('avatar');
        $name = $user->id.'-'.Str::random(8).'.'.strtolower($file->getClientOriginalExtension());
        $file->move(public_path('avatars'), $name);
        $user->update(['avatar_url' => '/avatars/'.$name]);

        return response()->json(['data' => new UserResource($user)]);
    }

    public function deleteAvatar(Request $request, User $user): JsonResponse
    {
        $this->assertOrgMember($user);
        if (! $request->user()->isAdmin() && $request->user()->id !== $user->id) {
            abort(403);
        }

        $this->removeAvatarFile($user);
        $user->update(['avatar_url' => null]);

        return response()->json(['data' => new UserResource($user)]);
    }

    private function removeAvatarFile(User $user): void
    {
        if ($user->avatar_url && str_starts_with($user->avatar_url, '/avatars/')) {
            $path = public_path(ltrim($user->avatar_url, '/'));
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function assertOrgMember(User $user): void
    {
        $isMember = app('current_organization')->users()->where('users.id', $user->id)->exists();

        if (! $isMember) {
            abort(404);
        }
    }
}
