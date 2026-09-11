<?php

namespace Tests\Feature\Rates;

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\HourlyRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HourlyRateTest extends TestCase
{
    use RefreshDatabase;

    private function entry(Project $project, string $userId, int $seconds, bool $billable = true): TimeEntry
    {
        return TimeEntry::factory()->create([
            'project_id' => $project->id,
            'user_id' => $userId,
            'started_at' => now()->subDay(),
            'stopped_at' => now()->subDay()->addSeconds($seconds),
            'duration_seconds' => $seconds,
            'is_billable' => $billable,
            'is_running' => false,
        ]);
    }

    public function test_rate_modes_resolve_with_standard_fallback(): void
    {
        $org = $this->createOrganization();
        $org->update(['hourly_rate' => 80]);
        $this->bindOrg($org);
        $alice = $this->memberOf($org);
        $bob = $this->memberOf($org);
        $org->users()->updateExistingPivot($alice->id, ['hourly_rate' => 120]);

        $client = Client::factory()->create(['hourly_rate' => 95]);
        $rates = app(HourlyRates::class);

        $standard = Project::factory()->create(['client_id' => $client->id, 'hourly_rate' => null, 'rate_mode' => 'standard']);
        $this->assertSame(80.0, $rates->forProject($standard, $alice->id));
        $this->assertSame('standard', $rates->source($standard));

        $byProject = Project::factory()->create(['client_id' => $client->id, 'hourly_rate' => 150]);
        $this->assertSame('project', $byProject->rate_mode);
        $this->assertSame(150.0, $rates->forProject($byProject, $alice->id));

        $byClient = Project::factory()->create(['client_id' => $client->id, 'hourly_rate' => 150, 'rate_mode' => 'client']);
        $this->assertSame(95.0, $rates->forProject($byClient, $alice->id));

        $byUser = Project::factory()->create(['client_id' => $client->id, 'hourly_rate' => null, 'rate_mode' => 'user']);
        $this->assertSame(120.0, $rates->forProject($byUser, $alice->id));
        $this->assertSame(80.0, $rates->forProject($byUser, $bob->id), 'member without rate falls back to Standard');
        $this->assertNull($rates->displayRate($byUser));

        $clientWithoutRate = Client::factory()->create(['hourly_rate' => null]);
        $fallback = Project::factory()->create(['client_id' => $clientWithoutRate->id, 'rate_mode' => 'client']);
        $this->assertSame(80.0, $rates->forProject($fallback));
        $this->assertSame('standard', $rates->source($fallback));

        $org->update(['hourly_rate' => null]);
        $this->assertNull(app(HourlyRates::class)->forProject($fallback));
        $this->assertSame('none', app(HourlyRates::class)->source($fallback));
    }

    public function test_reports_and_project_show_use_member_rates(): void
    {
        $org = $this->createOrganization();
        $org->update(['hourly_rate' => 80]);
        $this->bindOrg($org);
        $admin = $this->adminOf($org);
        $alice = $this->memberOf($org);
        $org->users()->updateExistingPivot($alice->id, ['hourly_rate' => 120]);

        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id, 'rate_mode' => 'user', 'hourly_rate' => null, 'budget_hours' => 10]);
        $this->entry($project, $alice->id, 3600);          // 1 h at 120
        $this->entry($project, $admin->id, 7200);          // 2 h at Standard 80
        $this->entry($project, $admin->id, 3600, false);   // not billable

        $from = now()->subDays(3)->toDateString();
        $to = now()->toDateString();

        $this->actingInOrg($admin, $org)
            ->getJson("/api/reports/projects/{$project->id}?date_from={$from}&date_to={$to}")
            ->assertOk()
            ->assertJsonPath('data.totals.amount', 280);

        $this->actingInOrg($admin, $org)
            ->getJson("/api/reports/summary?date_from={$from}&date_to={$to}&group_by=project")
            ->assertOk()
            ->assertJsonPath('data.0.amount', 280)
            ->assertJsonPath('meta.totals.amount', 280);

        $this->actingInOrg($admin, $org)
            ->getJson('/api/reports/budget')
            ->assertOk()
            ->assertJsonPath('data.0.revenue', 280)
            ->assertJsonPath('data.0.rate_source', 'user');

        $this->actingInOrg($admin, $org)
            ->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.rate_mode', 'user')
            ->assertJsonPath('data.billable_amount', 280)
            ->assertJsonPath('data.effective_hourly_rate', null);
    }

    public function test_rates_are_editable_through_the_api(): void
    {
        $org = $this->createOrganization();
        $this->bindOrg($org);
        $admin = $this->adminOf($org);
        $member = $this->memberOf($org);
        $client = Client::factory()->create();

        $this->actingInOrg($admin, $org)
            ->putJson("/api/organizations/{$org->id}", ['hourly_rate' => 90])
            ->assertOk()
            ->assertJsonPath('data.hourly_rate', 90);

        $this->actingInOrg($admin, $org)
            ->putJson("/api/users/{$member->id}", ['hourly_rate' => 110])
            ->assertOk()
            ->assertJsonPath('data.hourly_rate', 110);
        $this->assertEquals(110, (float) $org->users()->where('users.id', $member->id)->first()->pivot->hourly_rate);

        $this->actingInOrg($admin, $org)
            ->putJson("/api/clients/{$client->id}", ['hourly_rate' => 100])
            ->assertOk()
            ->assertJsonPath('data.hourly_rate', 100);

        $projectId = $this->actingInOrg($admin, $org)
            ->postJson('/api/projects', ['client_id' => $client->id, 'name' => 'Relaunch', 'rate_mode' => 'client'])
            ->assertCreated()
            ->assertJsonPath('data.rate_mode', 'client')
            ->assertJsonPath('data.effective_hourly_rate', 100)
            ->assertJsonPath('data.rate_source', 'client')
            ->json('data.id');

        $this->actingInOrg($admin, $org)
            ->putJson("/api/projects/{$projectId}", ['rate_mode' => 'bogus'])
            ->assertUnprocessable();

        $this->actingInOrg($admin, $org)
            ->putJson("/api/projects/{$projectId}", ['rate_mode' => 'standard'])
            ->assertOk()
            ->assertJsonPath('data.effective_hourly_rate', 90)
            ->assertJsonPath('data.rate_source', 'standard');
    }

    public function test_billing_mode_replaces_billable_flag_and_filters(): void
    {
        $org = $this->createOrganization();
        $this->bindOrg($org);
        $admin = $this->adminOf($org);
        $client = Client::factory()->create();

        $fixed = $this->actingInOrg($admin, $org)
            ->postJson('/api/projects', ['client_id' => $client->id, 'name' => 'Relaunch', 'billing_mode' => 'fixed', 'budget_amount' => 5000, 'billed_amount' => 2000])
            ->assertCreated()
            ->assertJsonPath('data.billing_mode', 'fixed')
            ->assertJsonPath('data.is_billable', true)
            ->assertJsonPath('data.fixed_remaining', 3000)
            ->json('data.id');
        $none = $this->actingInOrg($admin, $org)
            ->postJson('/api/projects', ['client_id' => $client->id, 'name' => 'Intern', 'billing_mode' => 'none'])
            ->assertCreated()->assertJsonPath('data.is_billable', false)->json('data.id');
        $this->actingInOrg($admin, $org)
            ->postJson('/api/projects', ['client_id' => $client->id, 'name' => 'Support', 'billing_mode' => 'hourly', 'budget_hours' => 20])
            ->assertCreated()->assertJsonPath('data.is_billable', true);

        $this->actingInOrg($admin, $org)->getJson('/api/projects?filter[billing]=fixed_open')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $fixed);
        $this->actingInOrg($admin, $org)->getJson('/api/projects?filter[billing]=fixed_billed')->assertOk()->assertJsonCount(0, 'data');
        $this->actingInOrg($admin, $org)->getJson('/api/projects?filter[billing]=none')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $none);

        $this->actingInOrg($admin, $org)->putJson("/api/projects/{$fixed}", ['billed_amount' => 5000])->assertOk()->assertJsonPath('data.fixed_remaining', 0);
        $this->actingInOrg($admin, $org)->getJson('/api/projects?filter[billing]=fixed_billed')->assertOk()->assertJsonCount(1, 'data');
        $this->actingInOrg($admin, $org)->putJson("/api/projects/{$fixed}", ['billing_mode' => 'bogus'])->assertUnprocessable();
    }
}
