<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_approval_hands_a_token_to_the_device(): void
    {
        $org = $this->createOrganization();
        $user = $this->memberOf($org);

        $start = $this->postJson('/api/auth/device/start', ['device_name' => 'MacBook'])->assertOk();
        $code = $start->json('data.code');

        $this->getJson("/api/auth/device/{$code}")->assertStatus(202)->assertJsonPath('data.status', 'pending');

        Sanctum::actingAs($user);
        $this->getJson("/api/auth/device/{$code}/info")->assertOk()->assertJsonPath('data.device_name', 'MacBook');
        $this->postJson("/api/auth/device/{$code}/approve", ['organization_id' => $org->id])->assertOk();

        // Poll without the browser session: the device receives the token exactly once
        $this->app['auth']->forgetGuards();
        $poll = $this->getJson("/api/auth/device/{$code}")->assertOk();
        $this->assertNotEmpty($poll->json('data.token'));
        $this->assertSame($org->id, $poll->json('data.organization_id'));
        $this->getJson("/api/auth/device/{$code}")->assertNotFound();

        $this->assertSame(1, $user->tokens()->where('name', 'MacBook')->count());
    }

    public function test_denied_request_returns_forbidden(): void
    {
        $org = $this->createOrganization();
        $user = $this->memberOf($org);
        $code = $this->postJson('/api/auth/device/start', ['device_name' => 'X'])->json('data.code');

        Sanctum::actingAs($user);
        $this->postJson("/api/auth/device/{$code}/deny")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/auth/device/{$code}")->assertForbidden();
    }

    public function test_cannot_approve_for_foreign_organization(): void
    {
        $org = $this->createOrganization('A');
        $user = $this->memberOf($org);
        $other = $this->createOrganization('B');
        $code = $this->postJson('/api/auth/device/start', ['device_name' => 'X'])->json('data.code');

        Sanctum::actingAs($user);
        $this->postJson("/api/auth/device/{$code}/approve", ['organization_id' => $other->id])->assertForbidden();
    }
}
