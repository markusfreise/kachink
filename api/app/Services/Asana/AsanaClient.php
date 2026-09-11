<?php

namespace App\Services\Asana;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Thin reader for the Asana REST API (personal access token). */
class AsanaClient
{
    public const BASE = 'https://app.asana.com/api/1.0';

    public const TASK_FIELDS = 'gid,name,notes,completed,completed_at,due_on,created_at,modified_at,num_subtasks,parent.gid,'
        .'assignee.gid,assignee.name,assignee.email,tags.gid,tags.name,memberships.section.name,'
        .'custom_fields.gid,custom_fields.name,custom_fields.type,custom_fields.number_value,custom_fields.display_value';

    public function __construct(private readonly string $token) {}

    private function http(): PendingRequest
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->baseUrl(self::BASE)
            ->timeout(30)
            ->retry(3, 1500, fn ($e) => str_contains((string) $e->getMessage(), '429') || str_contains((string) $e->getMessage(), '5'), throw: false);
    }

    /** @return array<string, mixed> */
    public function me(): array
    {
        return $this->get('/users/me', ['opt_fields' => 'name,email,workspaces.gid,workspaces.name']);
    }

    /** @return array<int, array<string, mixed>> */
    public function projects(string $workspaceGid): array
    {
        return $this->all('/projects', [
            'workspace' => $workspaceGid,
            'archived' => 'false',
            'opt_fields' => 'gid,name,archived,num_tasks,num_incomplete_tasks,num_completed_tasks',
        ]);
    }

    /**
     * Tasks of a project. completed: null = all, false = open, true = done.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tasks(string $projectGid, ?bool $completed = null): array
    {
        $params = ['opt_fields' => self::TASK_FIELDS];
        if ($completed === false) {
            $params['completed_since'] = 'now';
        }
        $tasks = $this->all("/projects/{$projectGid}/tasks", $params);
        if ($completed === true) {
            $tasks = array_values(array_filter($tasks, fn ($t) => ! empty($t['completed'])));
        }

        return $tasks;
    }

    /** @return array<int, array<string, mixed>> */
    public function subtasks(string $taskGid): array
    {
        return $this->all("/tasks/{$taskGid}/subtasks", ['opt_fields' => self::TASK_FIELDS]);
    }

    /** Comment stories only. @return array<int, array<string, mixed>> */
    public function comments(string $taskGid): array
    {
        $stories = $this->all("/tasks/{$taskGid}/stories", [
            'opt_fields' => 'gid,type,resource_subtype,text,created_at,created_by.gid,created_by.name,created_by.email',
        ]);

        return array_values(array_filter($stories, fn ($s) => ($s['type'] ?? '') === 'comment' && ($s['resource_subtype'] ?? '') === 'comment_added'));
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function get(string $path, array $params = []): array
    {
        $response = $this->http()->get($path, $params);
        if ($response->failed()) {
            $message = $response->json('errors.0.message') ?? $response->body();
            throw new RuntimeException("Asana {$response->status()}: {$message}");
        }

        return $response->json('data') ?? [];
    }

    /** Follows next_page until the collection is complete. @return array<int, array<string, mixed>> */
    private function all(string $path, array $params = []): array
    {
        $items = [];
        $params['limit'] = 100;
        $offset = null;
        $guard = 0;
        do {
            if ($offset) {
                $params['offset'] = $offset;
            }
            $response = $this->http()->get($path, $params);
            if ($response->failed()) {
                $message = $response->json('errors.0.message') ?? $response->body();
                throw new RuntimeException("Asana {$response->status()}: {$message}");
            }
            $items = array_merge($items, $response->json('data') ?? []);
            $offset = $response->json('next_page.offset');
        } while ($offset && $guard++ < 200);

        return $items;
    }
}
