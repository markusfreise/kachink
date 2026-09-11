<?php

namespace Tests\Feature\Asana;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\Asana\AsanaImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AsanaImportTest extends TestCase
{
    use RefreshDatabase;

    private function task(string $gid, string $name, array $extra = []): array
    {
        return array_merge([
            'gid' => $gid, 'name' => $name, 'notes' => '', 'completed' => false, 'due_on' => null,
            'num_subtasks' => 0, 'parent' => null, 'assignee' => null, 'tags' => [], 'custom_fields' => [], 'memberships' => [],
        ], $extra);
    }

    private function fakeAsana(array $open, array $completed = [], array $subtasks = [], array $stories = []): void
    {
        Http::fake([
            'app.asana.com/api/1.0/users/me*' => Http::response(['data' => ['name' => 'Markus', 'email' => 'mf@example.com', 'workspaces' => [['gid' => 'ws1', 'name' => 'freise']]]]),
            'app.asana.com/api/1.0/projects?*' => Http::response(['data' => [['gid' => 'p1', 'name' => 'Akquise', 'num_incomplete_tasks' => 2, 'num_completed_tasks' => 1]]]),
            'app.asana.com/api/1.0/projects/p1/tasks?*' => function ($request) use ($open, $completed) {
                $onlyOpen = str_contains($request->url(), 'completed_since');

                return Http::response(['data' => $onlyOpen ? $open : array_merge($open, $completed)]);
            },
            'app.asana.com/api/1.0/tasks/*/subtasks?*' => function ($request) use ($subtasks) {
                preg_match('#/tasks/([^/]+)/subtasks#', $request->url(), $m);

                return Http::response(['data' => $subtasks[$m[1]] ?? []]);
            },
            'app.asana.com/api/1.0/tasks/*/stories?*' => function ($request) use ($stories) {
                preg_match('#/tasks/([^/]+)/stories#', $request->url(), $m);

                return Http::response(['data' => $stories[$m[1]] ?? []]);
            },
        ]);
    }

    public function test_full_round_trip_mapping_tasks_projects_and_completed(): void
    {
        $org = $this->createOrganization();
        $this->bindOrg($org);
        $admin = $this->adminOf($org, ['email' => 'mf@example.com']);
        $member = $this->memberOf($org, ['email' => 'anna@example.com']);
        $existingProject = null;

        $open = [
            $this->task('t1', 'Toffifee Website', [
                'notes' => 'Kickoff im August', 'due_on' => '2026-10-01', 'num_subtasks' => 1,
                'assignee' => ['gid' => 'u2', 'name' => 'Anna', 'email' => 'anna@example.com'],
                'tags' => [['gid' => 'g1', 'name' => 'Web']],
                'custom_fields' => [['gid' => 'cf', 'name' => 'Betrag', 'type' => 'number', 'number_value' => 4080]],
            ]),
            $this->task('t2', 'Immo Kunde'),
            $this->task('t3', 'Milkids Refactoring', ['custom_fields' => [['gid' => 'cf', 'name' => 'Betrag', 'type' => 'number', 'number_value' => 3200]]]),
            $this->task('t1a', 'Subtask (not top-level)', ['parent' => ['gid' => 't1']]),
        ];
        $completed = [
            $this->task('d1', 'Alter Auftrag', ['completed' => true, 'completed_at' => '2026-05-01T10:00:00.000Z']),
        ];
        $subtasks = ['t1' => [$this->task('t1a', 'Screendesign', ['completed' => false])]];
        $stories = ['t1' => [
            ['gid' => 's1', 'type' => 'comment', 'resource_subtype' => 'comment_added', 'text' => 'Bitte mobile first', 'created_at' => '2026-08-15T09:00:00.000Z', 'created_by' => ['gid' => 'u2', 'name' => 'Anna', 'email' => 'anna@example.com']],
            ['gid' => 's2', 'type' => 'comment', 'resource_subtype' => 'comment_added', 'text' => 'Kunde ok', 'created_at' => '2026-08-16T09:00:00.000Z', 'created_by' => ['gid' => 'u9', 'name' => 'Mareike', 'email' => 'mareike@kunde.de']],
            ['gid' => 's3', 'type' => 'system', 'resource_subtype' => 'assigned', 'text' => 'assigned', 'created_at' => '2026-08-16T09:00:00.000Z'],
        ]];
        $this->fakeAsana($open, $completed, $subtasks, $stories);

        // Token
        $this->actingInOrg($admin, $org)->putJson('/api/asana/settings', ['token' => str_repeat('x', 40)])
            ->assertOk()->assertJsonPath('data.workspace_gid', 'ws1');
        $this->actingInOrg($member, $org)->getJson('/api/asana/projects')->assertForbidden();

        // Projects list and mapping to a new client
        $this->actingInOrg($admin, $org)->getJson('/api/asana/projects')->assertOk()->assertJsonPath('data.0.client', null);
        $clientId = $this->actingInOrg($admin, $org)->putJson('/api/asana/projects/p1/client', ['client_name' => 'Storck'])
            ->assertOk()->json('data.id');
        $existingProject = Project::create(['client_id' => $clientId, 'name' => 'Bestand', 'color' => '#000000']);

        // Open top-level tasks
        $rows = $this->actingInOrg($admin, $org)->getJson('/api/asana/projects/p1/tasks')->assertOk()->json('data');
        $this->assertCount(3, $rows, 'subtask with parent is not listed');
        $this->assertEquals(4080, collect($rows)->firstWhere('gid', 't1')['amount']);

        // Decisions
        $summary = $this->actingInOrg($admin, $org)->postJson('/api/asana/projects/p1/import', [
            'decisions' => [
                ['gid' => 't1', 'mode' => 'project'],
                ['gid' => 't2', 'mode' => 'task', 'project_id' => $existingProject->id],
                ['gid' => 't3', 'mode' => 'task_new_project', 'new_project_name' => 'Milkids'],
            ],
            'import_completed' => true,
            'with_comments' => true,
        ])->assertOk()->json('data');

        $this->assertSame(2, $summary['projects_created']);
        $this->assertSame([], $summary['errors']);
        $this->assertSame(0, $summary['completed_remaining']);

        $asProject = Project::where('asana_task_gid', 't1')->first();
        $this->assertSame('Toffifee Website', $asProject->name);
        $root = ProjectTask::where('asana_task_gid', 't1')->first();
        $this->assertSame($asProject->id, $root->project_id);
        $this->assertNull($root->parent_id);
        $this->assertSame($member->id, $root->assignee_id);
        $this->assertSame(4080.0, (float) $root->budget);
        $this->assertSame('2026-10-01', $root->deadline->toDateString());
        $this->assertSame(['Web'], $root->tags->pluck('name')->all());
        $this->assertSame(2, $root->comments()->count());
        $this->assertStringStartsWith('Mareike (Asana): ', $root->comments()->where('asana_story_gid', 's2')->first()->body);
        $sub = ProjectTask::where('asana_task_gid', 't1a')->first();
        $this->assertSame($asProject->id, $sub->project_id, 'subtasks of a task-as-project become top-level tasks');
        $this->assertNull($sub->parent_id);

        $this->assertSame($existingProject->id, ProjectTask::where('asana_task_gid', 't2')->first()->project_id);
        $this->assertSame('Milkids', ProjectTask::where('asana_task_gid', 't3')->first()->project->name);

        $done = Project::where('client_id', $clientId)->where('name', AsanaImporter::DONE_PROJECT_NAME)->first();
        $this->assertNotNull($done);
        $doneTask = ProjectTask::where('asana_task_gid', 'd1')->first();
        $this->assertSame($done->id, $doneTask->project_id);
        $this->assertNotNull($doneTask->completed_at);

        // Second run updates instead of duplicating and reports import state
        $rows = $this->actingInOrg($admin, $org)->getJson('/api/asana/projects/p1/tasks')->assertOk()->json('data');
        $this->assertSame('project', collect($rows)->firstWhere('gid', 't1')['imported']['type']);
        $this->assertSame('task', collect($rows)->firstWhere('gid', 't2')['imported']['type']);
        $again = $this->actingInOrg($admin, $org)->postJson('/api/asana/projects/p1/import', [
            'decisions' => [['gid' => 't2', 'mode' => 'task', 'project_id' => $existingProject->id]],
            'import_completed' => true,
        ])->assertOk()->json('data');
        $this->assertSame(0, $again['tasks_created']);
        $this->assertSame(1, $again['tasks_updated']);
        $this->assertSame(1, ProjectTask::where('asana_task_gid', 't2')->count());
        $this->assertSame(1, ProjectTask::where('asana_task_gid', 'd1')->count());
    }
}
