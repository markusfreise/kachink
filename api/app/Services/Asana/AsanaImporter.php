<?php

namespace App\Services\Asana;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectTask;
use App\Models\ProjectTaskComment;
use App\Models\Tag;
use App\Models\TaskStatus;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Turns Asana tasks into project tasks or projects. Idempotent: everything
 * is matched on its Asana GID, so a second run updates instead of duplicating.
 * Attachments are not imported; comments, tags, assignee and the custom field
 * "Betrag" are.
 */
class AsanaImporter
{
    public const DONE_PROJECT_NAME = 'Aus Asana als erledigt';

    public const AMOUNT_FIELD = 'Betrag';

    public array $summary = ['tasks_created' => 0, 'tasks_updated' => 0, 'projects_created' => 0, 'comments' => 0, 'errors' => []];

    /** @var array<string, User> lower-case email -> member */
    private array $members = [];

    /** @var array<string, Tag> lower-case name -> tag */
    private array $tags = [];

    /** @var array<string, TaskStatus> lower-case name -> status */
    private array $statuses = [];

    /** Asana project whose sections become statuses; null keeps statuses untouched. */
    public ?string $sectionProjectGid = null;

    /** 'status' (Arbeitsstatus) or 'project_status' (Projektstatus). */
    public string $sectionTarget = 'status';

    public function __construct(
        private readonly AsanaClient $asana,
        private readonly Organization $organization,
        private readonly User $importer,
        private readonly bool $withComments = true,
    ) {
        foreach ($organization->users()->get() as $user) {
            $this->members[strtolower($user->email)] = $user;
        }
    }

    /** Imports one Asana task (and its subtasks) as a task of $project. */
    public function importTask(array $t, Project $project, ?ProjectTask $parent = null): ProjectTask
    {
        $existing = ProjectTask::where('asana_task_gid', $t['gid'])->first();
        $attributes = [
            'project_id' => $project->id,
            'parent_id' => $parent?->id,
            'title' => mb_substr(trim($t['name'] ?? '') ?: '(ohne Titel)', 0, 255),
            'description' => trim((string) ($t['notes'] ?? '')) ?: null,
            'deadline' => $t['due_on'] ?? null,
            'assignee_id' => $this->memberFor($t['assignee'] ?? null)?->id,
            'completed_at' => ! empty($t['completed']) ? ($t['completed_at'] ?? now()) : null,
        ];
        $amount = $this->amount($t);
        if ($amount !== null) {
            $attributes['budget'] = $amount;
        }
        if ($this->sectionProjectGid && ($section = self::section($t, $this->sectionProjectGid)) !== null) {
            if ($this->sectionTarget === 'project_status') {
                $attributes['project_status_id'] = ProjectStatus::forProject($project, $section)->id;
            } else {
                $attributes['status_id'] = $this->statusFor($section)?->id;
            }
        }

        if ($existing) {
            $existing->fill($attributes)->save();
            $task = $existing;
            $this->summary['tasks_updated']++;
        } else {
            $task = ProjectTask::create($attributes + [
                'organization_id' => $this->organization->id,
                'created_by' => $this->importer->id,
                'priority' => 'soon',
                'asana_task_gid' => $t['gid'],
            ]);
            $this->summary['tasks_created']++;
        }

        $this->syncTags($task, $t['tags'] ?? []);
        if ($this->withComments) {
            $this->syncComments($task);
        }
        if (! empty($t['num_subtasks'])) {
            foreach ($this->asana->subtasks($t['gid']) as $sub) {
                $this->importTask($sub, $project, $task);
            }
        }

        return $task;
    }

    /**
     * Imports an Asana task as a project of $client. The task itself becomes
     * a task inside that project (keeps notes, comments and amount), its
     * subtasks become top-level tasks of the project.
     */
    public function importTaskAsProject(array $t, Client $client): Project
    {
        $name = mb_substr(trim($t['name'] ?? '') ?: '(ohne Titel)', 0, 255);
        $project = Project::where('asana_task_gid', $t['gid'])->first();
        if ($project) {
            $project->update(['name' => $name, 'client_id' => $client->id]);
        } else {
            $project = Project::create([
                'organization_id' => $this->organization->id,
                'client_id' => $client->id,
                'name' => $name,
                'color' => $client->color,
                'is_billable' => true,
                'is_active' => empty($t['completed']),
                'asana_task_gid' => $t['gid'],
            ]);
            $this->summary['projects_created']++;
        }

        $subtasks = ! empty($t['num_subtasks']) ? $this->asana->subtasks($t['gid']) : [];
        $t['num_subtasks'] = 0; // the root task itself is imported without children
        $this->importTask($t, $project);
        foreach ($subtasks as $sub) {
            $this->importTask($sub, $project);
        }

        return $project;
    }

    /** Project "Aus Asana als erledigt" of the client, created on demand. */
    public function doneProjectFor(Client $client): Project
    {
        return Project::firstOrCreate(
            ['client_id' => $client->id, 'name' => self::DONE_PROJECT_NAME],
            ['organization_id' => $this->organization->id, 'color' => '#9CA3AF', 'is_billable' => false, 'is_active' => true],
        );
    }

    // ------------------------------------------------------------------

    /** Section name of the task inside the given Asana project, if any. */
    public static function section(array $t, string $projectGid): ?string
    {
        foreach ($t['memberships'] ?? [] as $m) {
            if (($m['project']['gid'] ?? null) === $projectGid && ! empty($m['section']['name'])) {
                $name = trim($m['section']['name']);

                return $name === '' || strcasecmp($name, 'Untitled section') === 0 || strcasecmp($name, 'Unbenannter Bereich') === 0 ? null : $name;
            }
        }

        return null;
    }

    /** Status named like the section, created on demand ("Heute" maps to the fixed one). */
    private function statusFor(string $section): ?TaskStatus
    {
        $key = strtolower($section);
        if (! isset($this->statuses[$key])) {
            $status = TaskStatus::whereRaw('lower(name) = ?', [$key])->first();
            if (! $status) {
                $status = TaskStatus::create([
                    'organization_id' => $this->organization->id,
                    'name' => mb_substr($section, 0, 80),
                    'color' => '#6B7280',
                    'position' => (int) TaskStatus::max('position') + 1,
                ]);
            }
            $this->statuses[$key] = $status;
        }

        return $this->statuses[$key];
    }

    private function memberFor(?array $assignee): ?User
    {
        $email = strtolower((string) ($assignee['email'] ?? ''));

        return $email !== '' ? ($this->members[$email] ?? null) : null;
    }

    private function amount(array $t): ?float
    {
        foreach ($t['custom_fields'] ?? [] as $field) {
            if (($field['name'] ?? '') === self::AMOUNT_FIELD && isset($field['number_value'])) {
                return (float) $field['number_value'];
            }
        }

        return null;
    }

    private function syncTags(ProjectTask $task, array $asanaTags): void
    {
        $ids = [];
        foreach ($asanaTags as $asanaTag) {
            $name = trim((string) ($asanaTag['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = strtolower($name);
            if (! isset($this->tags[$key])) {
                $this->tags[$key] = Tag::firstOrCreate(
                    ['organization_id' => $this->organization->id, 'name' => $name],
                    ['color' => '#6B7280'],
                );
            }
            $ids[] = $this->tags[$key]->id;
        }
        if ($ids || $task->tags()->exists()) {
            $task->tags()->sync($ids);
        }
    }

    private function syncComments(ProjectTask $task): void
    {
        try {
            $stories = $this->asana->comments($task->asana_task_gid);
        } catch (\Throwable $e) {
            $this->summary['errors'][] = "Kommentare zu '{$task->title}': ".$e->getMessage();

            return;
        }
        foreach ($stories as $story) {
            $text = trim((string) ($story['text'] ?? ''));
            if ($text === '' || ProjectTaskComment::where('asana_story_gid', $story['gid'])->exists()) {
                continue;
            }
            $author = $this->memberFor($story['created_by'] ?? null);
            $body = $author ? $text : trim(($story['created_by']['name'] ?? 'Asana').' (Asana): '.$text);
            $comment = new ProjectTaskComment([
                'organization_id' => $this->organization->id,
                'project_task_id' => $task->id,
                'user_id' => $author?->id ?? $this->importer->id,
                'body' => $body,
                'asana_story_gid' => $story['gid'],
            ]);
            $created = isset($story['created_at']) ? CarbonImmutable::parse($story['created_at']) : now();
            $comment->created_at = $created;
            $comment->updated_at = $created;
            $comment->save();
            $this->summary['comments']++;
        }
    }
}
