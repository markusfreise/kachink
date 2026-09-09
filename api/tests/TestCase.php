<?php

namespace Tests;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function createOrganization(string $name = 'Test Org'): Organization
    {
        return Organization::create(['name' => $name, 'is_active' => true]);
    }

    protected function memberOf(Organization $organization, array $attributes = [], string $pivotRole = 'member'): User
    {
        $user = User::factory()->create($attributes);
        $organization->users()->attach($user->id, ['role' => $pivotRole]);

        return $user;
    }

    protected function adminOf(Organization $organization, array $attributes = []): User
    {
        return $this->memberOf($organization, array_merge(['role' => 'admin'], $attributes), 'owner');
    }

    /** Authenticate and set the organization header for subsequent requests. */
    protected function actingInOrg(User $user, Organization $organization): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Organization-Id', $organization->id);
    }

    /** Bind the organization so factories fill organization_id, like the middleware does. */
    protected function bindOrg(Organization $organization): void
    {
        app()->instance('current_organization', $organization);
    }
}
