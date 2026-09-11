<?php

namespace Tests\Feature\ProjectTasks;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Tag;
use App\Notifications\ProjectTaskReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectTaskTest extends TestCase
{
    use RefreshDatabase;

    private function projectIn($org): Project
    {
        $this->bindOrg($org);
        $client = Client::factory()->create();

        return Project::factory()->create(['client_id' => $client->id]);
    }

    public function test_create_task_with_subtask_tags_and_history(): void
    {
        $org = $this->createOrganization();
        $project = $this->projectIn($org);
        $user = $this->memberOf($org);
        $assignee = $this->memberOf($org);
        $tag = Tag::create(['name' => 'Design', 'color' => '#000000']);

        $response = $this->actingInOrg($user, $org)
            ->postJson('/api/project-tasks', [
                'project_id' => $project->id,
                'title' => 'Landing page',
                'description' => 'Hero, features, footer',
                'assignee_id' => $assignee->id,
                'priority' => 'urgent',
                'estimate_minutes' => 150,
                'budget' => 1200.5,
                'deadline' => '2026-10-01',
                'reminder_at' => '2026-09-25',
                'tag_ids' => [$tag->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.priority', 'urgent')
            ->assertJsonPath('data.assignee.id', $assignee->id)
            ->assertJsonPath('data.budget', 1200.5)
            ->assertJsonPath('data.deadline', '2026-10-01')
            ->assertJsonPath('data.tags.0.name', 'Design')
            ->assertJsonPath('data.is_completed', false)
            ->assertJsonPath('data.history.0.action', 'created');

        $parentId = $response->json('data.id');

        $this->actingInOrg($user, $org)
            ->postJson('/api/project-tasks', [
                'project_id' => $project->id,
                'parent_id' => $parentId,
                'title' => 'Hero section',
            ])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $parentId)
            ->assertJsonPath('data.ancestors.0.id', $parentId)
            ->assertJsonPath('data.priority', 'soon');

        $this->actingInOrg($user, $org)
            ->getJson("/api/project-tasks/{$parentId}")
            ->assertOk()
            ->assertJsonPath('data.children_count', 1)
            ->assertJsonPath('data.open_children_count', 1)
            ->assertJsonPath('data.children.0.title', 'Hero section');

        $this->actingInOrg($user, $org)
            ->getJson('/api/project-tasks?filter[project_id]='.$project->id.'&filter[parent_id]=root')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_task_cannot_reference_project_or_parent_of_another_organization(): void
    {
        $orgA = $this->createOrganization('A');
        $projectA = $this->projectIn($orgA);
        $user = $this->memberOf($orgA);

        $orgB = $this->createOrganization('B');
        $projectB = $this->projectIn($orgB);
        $foreignTask = ProjectTask::factory()->create(['project_id' => $projectB->id]);

        $this->actingInOrg($user, $orgA)
            ->postJson('/api/project-tasks', ['project_id' => $projectB->id, 'title' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_id');

        $this->actingInOrg($user, $orgA)
            ->postJson('/api/project-tasks', ['project_id' => $projectA->id, 'parent_id' => $foreignTask->id, 'title' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->actingInOrg($user, $orgA)
            ->getJson("/api/project-tasks/{$foreignTask->id}")
            ->assertNotFound();
    }

    public function test_parent_must_share_project_and_cannot_form_a_cycle(): void
    {
        $org = $this->createOrganization();
        $project = $this->projectIn($org);
        $otherProject = Project::factory()->create(['client_id' => $project->client_id]);
        $user = $this->memberOf($org);

        $root = ProjectTask::factory()->create(['project_id' => $project->id]);
        $child = ProjectTask::factory()->create(['project_id' => $project->id, 'parent_id' => $root->id]);
        $grandchild = ProjectTask::factory()->create(['project_id' => $project->id, 'parent_id' => $child->id]);
        $elsewhere = ProjectTask::factory()->create(['project_id' => $otherProject->id]);

        $this->actingInOrg($user, $org)
            ->putJson("/api/project-tasks/{$root->id}", ['parent_id' => $grandchild->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->actingInOrg($user, $org)
            ->putJson("/api/project-tasks/{$root->id}", ['parent_id' => $elsewhere->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->actingInOrg($user, $org)
            ->putJson("/api/project-tasks/{$grandchild->id}", ['parent_id' => null])
            ->assertOk()
            ->assertJsonPath('data.parent_id', null);
    }

    public function test_update_writes_history_and_completion_toggles(): void
    {
        $org = $this->createOrganization();
        $project = $this->projectIn($org);
        $user = $this->memberOf($org);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'title' => 'Old', 'priority' => 'soon']);

        $this->actingInOrg($user, $org)
            ->putJson("/api/project-tasks/{$task->id}", ['title' => 'New', 'priority' => 'immediate', 'completed' => true])
            ->assertOk()
            ->assertJsonPath('data.is_completed', true)
            ->assertJsonPath('data.title', 'New');

        $actions = $task->fresh()->histories()->pluck('action')->all();
        $this->assertContains('completed', $actions);
        $this->assertContains('updated', $actions);

        $updated = $task->histories()->where('action', 'updated')->first();
        $this->assertSame(['from' => 'Old', 'to' => 'New'], $updated->changes['title']);
        $this->assertSame(['from' => 'soon', 'to' => 'immediate'], $updated->changes['priority']);
        $this->assertSame($user->id, $updated->user_id);

        $this->actingInOrg($user, $org)
            ->putJson("/api/project-tasks/{$task->id}", ['completed' => false])
            ->assertOk()
            ->assertJsonPath('data.is_completed', false);
        $this->assertContains('reopened', $task->histories()->pluck('action')->all());
    }

    public function test_list_filters_and_priority_sort(): void
    {
        $org = $this->createOrganization();
        $project = $this->projectIn($org);
        $user = $this->memberOf($org);

        ProjectTask::factory()->create(['project_id' => $project->id, 'title' => 'easy one', 'priority' => 'easy']);
        ProjectTask::factory()->create(['project_id' => $project->id, 'title' => 'now', 'priority' => 'immediate']);
        ProjectTask::factory()->create(['project_id' => $project->id, 'title' => 'done', 'priority' => 'urgent', 'completed_at' => now()]);

        $this->actingInOrg($user, $org)
            ->getJson('/api/project-tasks?filter[status]=open')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'now')
            ->assertJsonPath('data.1.title', 'easy one');

        $this->actingInOrg($user, $org)
            ->getJson('/api/project-tasks?filter[status]=done')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingInOrg($user, $org)
            ->getJson('/api/project-tasks?filter[q]=easy')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_comments_can_be_added_and_only_author_or_admin_deletes(): void
    {
        $org = $this->createOrganization();
        $project = $this->projectIn($org);
        $author = $this->memberOf($org);
        $other = $this->memberOf($org);
        $admin = $this->adminOf($org);
        $task = ProjectTask::factory()->create(['project_id' => $project->id]);

        $commentId = $this->actingInOrg($author, $org)
            ->postJson("/api/project-tasks/{$task->id}/comments", ['body' => 'Looks good'])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $author->id)
            ->json('data.id');

        $this->actingInOrg($other, $org)
            ->deleteJson("/api/project-tasks/{$task->id}/comments/{$commentId}")
            ->assertForbidden();

        $this->actingInOrg($admin, $org)
            ->deleteJson("/api/project-tasks/{$task->id}/comments/{$commentId}")
            ->assertNoContent();

        $actions = $task->histories()->pluck('action')->all();
        $this->assertContains('comment_added', $actions);
        $this->assertContains('comment_deleted', $actions);
    }

    public function test_attachments_upload_download_and_delete(): void
    {
        Storage::fake('local');
        $org = $this->createOrganization();
        $project = $this->projectIn($org);
        $user = $this->memberOf($org);
        $task = ProjectTask::factory()->create(['project_id' => $project->id]);

        $attachmentId = $this->actingInOrg($user, $org)
            ->post("/api/project-tasks/{$task->id}/attachments", [
                'file' => UploadedFile::fake()->create('brief.pdf', 120, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.original_name', 'brief.pdf')
            ->json('data.id');

        $path = task_attachment_path($task, $attachmentId);
        Storage::disk('local')->assertExists($path);

        $this->actingInOrg($user, $org)
            ->get("/api/project-tasks/{$task->id}/attachments/{$attachmentId}/download")
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=brief.pdf');

        $this->actingInOrg($user, $org)
            ->deleteJson("/api/project-tasks/{$task->id}/attachments/{$attachmentId}")
            ->assertNoContent();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_deleting_a_task_removes_subtasks_and_their_files(): void
    {
        Storage::fake('local');
        $org = $this->createOrganization();
        $project = $this->projectIn($org);
        $creator = $this->memberOf($org);
        $other = $this->memberOf($org);

        $root = ProjectTask::factory()->create(['project_id' => $project->id, 'created_by' => $creator->id]);
        $child = ProjectTask::factory()->create(['project_id' => $project->id, 'parent_id' => $root->id]);
        Storage::disk('local')->put('attachments/x/child.txt', 'x');
        $child->attachments()->create([
            'organization_id' => $org->id, 'user_id' => $other->id,
            'original_name' => 'child.txt', 'path' => 'attachments/x/child.txt', 'size' => 1,
        ]);

        $this->actingInOrg($other, $org)
            ->deleteJson("/api/project-tasks/{$root->id}")
            ->assertForbidden();

        $this->actingInOrg($creator, $org)
            ->deleteJson("/api/project-tasks/{$root->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('project_tasks', ['id' => $child->id]);
        Storage::disk('local')->assertMissing('attachments/x/child.txt');
    }

    public function test_statuses_are_free_form_with_a_locked_default_and_filterable(): void
    {
        $org = $this->createOrganization();
        $project = $this->projectIn($org);
        $user = $this->memberOf($org);

        $list = $this->actingInOrg($user, $org)->getJson('/api/task-statuses')->assertOk();
        $heute = collect($list->json('data'))->firstWhere('name', 'Heute');
        $this->assertTrue($heute['is_locked']);

        $waiting = $this->actingInOrg($user, $org)
            ->postJson('/api/task-statuses', ['name' => 'Warten auf Kunde', 'color' => '#2563EB'])
            ->assertCreated()
            ->json('data');

        $this->actingInOrg($user, $org)
            ->postJson('/api/task-statuses', ['name' => 'Heute'])
            ->assertUnprocessable();

        $this->actingInOrg($user, $org)
            ->deleteJson("/api/task-statuses/{$heute['id']}")
            ->assertStatus(422);

        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'status_id' => $heute['id']]);
        ProjectTask::factory()->create(['project_id' => $project->id, 'status_id' => $waiting['id']]);
        ProjectTask::factory()->create(['project_id' => $project->id]);

        $this->actingInOrg($user, $org)
            ->getJson('/api/project-tasks?filter[status_id]='.$heute['id'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status.name', 'Heute');

        $this->actingInOrg($user, $org)
            ->getJson('/api/project-tasks?filter[status_id]=none')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingInOrg($user, $org)
            ->putJson("/api/project-tasks/{$task->id}", ['status_id' => $waiting['id']])
            ->assertOk()
            ->assertJsonPath('data.status.name', 'Warten auf Kunde');
        $this->assertSame([$heute['id'], $waiting['id']], array_values($task->fresh()->histories()->where('action', 'updated')->first()->changes['status_id']));

        // Deleting a status detaches it from tasks instead of deleting them.
        $this->actingInOrg($user, $org)
            ->deleteJson("/api/task-statuses/{$waiting['id']}")
            ->assertNoContent();
        $this->assertNull($task->fresh()->status_id);
    }

    public function test_deadline_filter_and_board_reorder(): void
    {
        $org = $this->createOrganization();
        $project = $this->projectIn($org);
        $user = $this->memberOf($org);
        $heute = collect($this->actingInOrg($user, $org)->getJson('/api/task-statuses')->json('data'))->firstWhere('name', 'Heute');

        $soon = ProjectTask::factory()->create(['project_id' => $project->id, 'deadline' => now()->addDays(3)->toDateString()]);
        $later = ProjectTask::factory()->create(['project_id' => $project->id, 'deadline' => now()->addDays(20)->toDateString()]);
        $none = ProjectTask::factory()->create(['project_id' => $project->id, 'deadline' => null]);

        $this->actingInOrg($user, $org)
            ->getJson('/api/project-tasks?filter[deadline_until]='.now()->addDays(7)->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $soon->id);

        $this->actingInOrg($user, $org)
            ->postJson('/api/project-tasks/reorder', ['status_id' => $heute['id'], 'ordered_ids' => [$later->id, $soon->id]])
            ->assertOk();
        $this->assertSame($heute['id'], $soon->fresh()->status_id);
        $this->assertSame(1, $soon->fresh()->position);
        $this->assertSame(0, $later->fresh()->position);
        $this->assertNull($none->fresh()->status_id);

        $this->actingInOrg($user, $org)
            ->postJson('/api/project-tasks/reorder', ['status_id' => null, 'ordered_ids' => [$soon->id]])
            ->assertOk();
        $this->assertNull($soon->fresh()->status_id);
    }

    public function test_avatar_upload_for_self_and_admin_only(): void
    {
        $org = $this->createOrganization();
        $this->bindOrg($org);
        $member = $this->memberOf($org);
        $other = $this->memberOf($org);
        $dir = public_path('avatars');

        $response = $this->actingInOrg($member, $org)
            ->post("/api/users/{$member->id}/avatar", ['avatar' => \Illuminate\Http\UploadedFile::fake()->image('me.png', 64, 64)], ['Accept' => 'application/json'])
            ->assertOk();
        $url = $response->json('data.avatar_url');
        $this->assertStringStartsWith('/avatars/', $url);
        $this->assertFileExists($dir.'/'.basename($url));

        $this->actingInOrg($other, $org)
            ->post("/api/users/{$member->id}/avatar", ['avatar' => \Illuminate\Http\UploadedFile::fake()->image('x.png')], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->actingInOrg($member, $org)
            ->deleteJson("/api/users/{$member->id}/avatar")
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);
        $this->assertFileDoesNotExist($dir.'/'.basename($url));
    }

    public function test_tasks_due_today_move_into_heute_and_blink_until_confirmed(): void
    {
        $org = $this->createOrganization();
        $project = $this->projectIn($org);
        $user = $this->memberOf($org);
        $today = ProjectTask::today();

        $due = ProjectTask::factory()->create(['project_id' => $project->id, 'deadline' => $today]);
        $other = ProjectTask::factory()->create(['project_id' => $project->id, 'deadline' => now()->addDays(2)->toDateString()]);

        $list = $this->actingInOrg($user, $org)->getJson('/api/project-tasks')->assertOk()->json('data');
        $row = collect($list)->firstWhere('id', $due->id);
        $this->assertSame('Heute', $row['status']['name']);
        $this->assertTrue($row['is_due_today_alert']);
        $this->assertNull(collect($list)->firstWhere('id', $other->id)['status_id']);

        // Moving it out of Heute by hand is respected for the rest of the day.
        $this->actingInOrg($user, $org)->putJson("/api/project-tasks/{$due->id}", ['status_id' => null])->assertOk();
        $this->actingInOrg($user, $org)->getJson('/api/project-tasks')->assertOk();
        $this->assertNull($due->fresh()->status_id);

        $this->actingInOrg($user, $org)
            ->putJson("/api/project-tasks/{$due->id}", ['acknowledge_today' => true])
            ->assertOk()
            ->assertJsonPath('data.is_due_today_alert', false);

        $this->actingInOrg($user, $org)
            ->putJson("/api/project-tasks/{$due->id}", ['deadline' => $today])
            ->assertOk()
            ->assertJsonPath('data.is_due_today_alert', false, 'same deadline keeps the confirmation');

        $this->actingInOrg($user, $org)
            ->putJson("/api/project-tasks/{$due->id}", ['deadline' => now()->addDay()->toDateString()])
            ->assertOk()
            ->assertJsonPath('data.is_due_today_alert', false);
        $this->assertNull($due->fresh()->today_acknowledged_on);
    }

    public function test_reminder_command_notifies_assignee_once(): void
    {
        Notification::fake();
        $org = $this->createOrganization();
        $project = $this->projectIn($org);
        $assignee = $this->memberOf($org);

        $due = ProjectTask::factory()->create([
            'project_id' => $project->id, 'assignee_id' => $assignee->id,
            'reminder_at' => now()->subDay()->toDateString(),
        ]);
        ProjectTask::factory()->create([
            'project_id' => $project->id, 'assignee_id' => $assignee->id,
            'reminder_at' => now()->addDays(3)->toDateString(),
        ]);
        ProjectTask::factory()->create([
            'project_id' => $project->id, 'assignee_id' => $assignee->id,
            'reminder_at' => now()->subDay()->toDateString(), 'completed_at' => now(),
        ]);

        $this->artisan('project-tasks:send-reminders')->assertSuccessful();
        Notification::assertSentTo($assignee, ProjectTaskReminder::class, fn ($n) => $n->task->id === $due->id);
        Notification::assertCount(1);

        $this->artisan('project-tasks:send-reminders')->assertSuccessful();
        Notification::assertCount(1);
        $this->assertNotNull($due->fresh()->reminder_sent_at);
    }
}

function task_attachment_path(ProjectTask $task, string $attachmentId): string
{
    return \App\Models\ProjectTaskAttachment::withoutGlobalScopes()->findOrFail($attachmentId)->path;
}
