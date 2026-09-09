<?php

namespace Tests\Feature\TimeEntries;

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimerTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_stop_and_single_running_timer(): void
    {
        $org = $this->createOrganization();
        $this->bindOrg($org);
        $user = $this->memberOf($org);
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);

        $this->actingInOrg($user, $org)
            ->postJson('/api/time-entries/start', ['project_id' => $project->id, 'source' => 'menubar'])
            ->assertCreated()
            ->assertJsonPath('data.is_running', true);

        $this->actingInOrg($user, $org)
            ->postJson('/api/time-entries/start', ['project_id' => $project->id])
            ->assertCreated();

        $this->assertSame(1, TimeEntry::where('is_running', true)->count());

        $this->actingInOrg($user, $org)
            ->postJson('/api/time-entries/stop')
            ->assertOk()
            ->assertJsonPath('data.is_running', false);

        $this->actingInOrg($user, $org)
            ->postJson('/api/time-entries/stop')
            ->assertStatus(409);
    }

    public function test_cannot_attach_entry_to_project_of_another_organization(): void
    {
        $org = $this->createOrganization('A');
        $this->bindOrg($org);
        $user = $this->memberOf($org);

        $otherOrg = $this->createOrganization('B');
        $this->bindOrg($otherOrg);
        $foreignClient = Client::factory()->create();
        $foreignProject = Project::factory()->create(['client_id' => $foreignClient->id]);

        $this->actingInOrg($user, $org)
            ->postJson('/api/time-entries/start', ['project_id' => $foreignProject->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_id');
    }

    public function test_member_cannot_read_colleague_entry(): void
    {
        $org = $this->createOrganization();
        $this->bindOrg($org);
        $a = $this->memberOf($org);
        $b = $this->memberOf($org);
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $entry = TimeEntry::factory()->create(['user_id' => $a->id, 'project_id' => $project->id]);

        $this->actingInOrg($b, $org)->getJson("/api/time-entries/{$entry->id}")->assertForbidden();
        $this->actingInOrg($a, $org)->getJson("/api/time-entries/{$entry->id}")->assertOk();
    }

    public function test_idle_split_stop_at_time_and_restart(): void
    {
        $org = $this->createOrganization();
        $this->bindOrg($org);
        $user = $this->memberOf($org);
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);

        $entry = TimeEntry::factory()->running()->create([
            'user_id' => $user->id, 'project_id' => $project->id,
            'started_at' => now()->subMinutes(60),
        ]);

        // Stop at the moment idle time began (20 minutes ago)
        $stopAt = now()->subMinutes(20);
        $this->actingInOrg($user, $org)
            ->putJson("/api/time-entries/{$entry->id}", ['stopped_at' => $stopAt->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('data.is_running', false);

        $entry->refresh();
        $this->assertEqualsWithDelta(2400, $entry->duration_seconds, 2);
    }
}
