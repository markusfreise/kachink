<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $orgs = $request->user()->organizations()->get();

        return OrganizationResource::collection($orgs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:organizations,slug',
        ]);

        $org = Organization::create($validated);
        $org->users()->attach($request->user()->id, ['role' => 'owner']);

        return response()->json(['data' => new OrganizationResource($org)], 201);
    }

    public function show(Organization $organization, Request $request): JsonResponse
    {
        $this->assertMember($organization, $request->user());
        $organization->load('users');

        return response()->json(['data' => new OrganizationResource($organization)]);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $this->assertMember($organization, $request->user(), ['owner', 'admin']);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
        ]);

        $organization->update($validated);

        return response()->json(['data' => new OrganizationResource($organization)]);
    }

    public function destroy(Organization $organization, Request $request): JsonResponse
    {
        $this->assertMember($organization, $request->user(), ['owner']);
        $organization->delete();

        return response()->json(null, 204);
    }

    public function members(Organization $organization, Request $request): AnonymousResourceCollection
    {
        $this->assertMember($organization, $request->user());

        return UserResource::collection($organization->users()->get());
    }

    public function addMember(Request $request, Organization $organization): JsonResponse
    {
        $this->assertMember($organization, $request->user(), ['owner', 'admin']);

        $validated = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'role'    => 'sometimes|in:admin,member',
        ]);

        $organization->users()->syncWithoutDetaching([
            $validated['user_id'] => ['role' => $validated['role'] ?? 'member'],
        ]);

        return response()->json(['message' => 'Member added.']);
    }

    public function removeMember(Request $request, Organization $organization, User $user): JsonResponse
    {
        $this->assertMember($organization, $request->user(), ['owner', 'admin']);
        $organization->users()->detach($user->id);

        return response()->json(null, 204);
    }

    private function assertMember(Organization $org, User $user, array $roles = []): void
    {
        $pivot = $org->users()->where('users.id', $user->id)->first()?->pivot;

        abort_if(!$pivot, 403, 'Not a member of this organization.');
        abort_if(!empty($roles) && !in_array($pivot->role, $roles), 403, 'Insufficient permissions.');
    }
}
