<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Rules\InOrganization;
use App\Services\Asana\AsanaClient;
use App\Services\Asana\AsanaImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Import assistant: Asana projects are clients here, Asana tasks become
 * tasks in existing or new projects or projects of their own. Admins only.
 */
class AsanaImportController extends Controller
{
    public const COMPLETED_BATCH = 50;

    public function settings(Request $request): JsonResponse
    {
        $this->admin($request);
        $org = $this->org();

        return response()->json(['data' => [
            'configured' => ! empty($org->asana_token),
            'workspace_gid' => $org->asana_workspace_gid,
            'amount_field' => AsanaImporter::AMOUNT_FIELD,
            'done_project_name' => AsanaImporter::DONE_PROJECT_NAME,
        ]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate(['token' => ['required', 'string', 'min:20']]);

        try {
            $me = (new AsanaClient($data['token']))->me();
        } catch (RuntimeException $e) {
            return response()->json(['message' => 'Asana hat das Token abgelehnt: '.$e->getMessage()], 422);
        }
        $workspace = $me['workspaces'][0] ?? null;

        $this->org()->forceFill([
            'asana_token' => $data['token'],
            'asana_workspace_gid' => $workspace['gid'] ?? null,
        ])->save();

        return response()->json(['data' => [
            'configured' => true,
            'workspace_gid' => $workspace['gid'] ?? null,
            'workspace_name' => $workspace['name'] ?? null,
            'user' => ['name' => $me['name'] ?? null, 'email' => $me['email'] ?? null],
        ]]);
    }

    public function deleteSettings(Request $request): JsonResponse
    {
        $this->admin($request);
        $this->org()->forceFill(['asana_token' => null, 'asana_workspace_gid' => null])->save();

        return response()->json(null, 204);
    }

    /** Asana projects with the client they are mapped to. */
    public function projects(Request $request): JsonResponse
    {
        $this->admin($request);
        $asana = $this->asana();
        $projects = $asana->projects($this->org()->asana_workspace_gid);

        $clients = Client::whereNotNull('asana_project_gid')->get()->keyBy('asana_project_gid');
        $rows = array_map(fn ($p) => [
            'gid' => $p['gid'],
            'name' => $p['name'],
            'num_incomplete_tasks' => $p['num_incomplete_tasks'] ?? null,
            'num_completed_tasks' => $p['num_completed_tasks'] ?? null,
            'client' => ($c = $clients->get($p['gid'])) ? ['id' => $c->id, 'name' => $c->name, 'color' => $c->color] : null,
        ], $projects);
        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return response()->json(['data' => $rows]);
    }

    /** Map an Asana project to a client (existing or new); client_id null unmaps. */
    public function mapClient(Request $request, string $gid): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'client_id' => ['nullable', 'uuid', InOrganization::exists('clients')],
            'client_name' => ['nullable', 'string', 'max:255'],
        ]);

        Client::where('asana_project_gid', $gid)->update(['asana_project_gid' => null]);

        $client = null;
        if (! empty($data['client_id'])) {
            $client = Client::findOrFail($data['client_id']);
        } elseif (! empty($data['client_name'])) {
            $client = Client::create(['name' => trim($data['client_name'])]);
        }
        $client?->update(['asana_project_gid' => $gid]);

        return response()->json(['data' => $client ? ['id' => $client->id, 'name' => $client->name, 'color' => $client->color] : null]);
    }

    /** Open top-level tasks of an Asana project with their import state. */
    public function tasks(Request $request, string $gid): JsonResponse
    {
        $this->admin($request);
        $client = Client::where('asana_project_gid', $gid)->first();
        $tasks = array_values(array_filter($this->asana()->tasks($gid, false), fn ($t) => empty($t['parent'])));

        $gids = array_column($tasks, 'gid');
        $asTasks = ProjectTask::whereIn('asana_task_gid', $gids)->with('project')->get()->keyBy('asana_task_gid');
        $asProjects = Project::whereIn('asana_task_gid', $gids)->get()->keyBy('asana_task_gid');

        $rows = array_map(function ($t) use ($asTasks, $asProjects) {
            $amount = null;
            foreach ($t['custom_fields'] ?? [] as $f) {
                if (($f['name'] ?? '') === AsanaImporter::AMOUNT_FIELD && isset($f['number_value'])) {
                    $amount = (float) $f['number_value'];
                }
            }
            $imported = null;
            if ($p = $asProjects->get($t['gid'])) {
                $imported = ['type' => 'project', 'id' => $p->id, 'name' => $p->name];
            } elseif ($pt = $asTasks->get($t['gid'])) {
                $imported = ['type' => 'task', 'id' => $pt->id, 'project_id' => $pt->project_id, 'project_name' => $pt->project?->name];
            }

            return [
                'gid' => $t['gid'],
                'name' => $t['name'],
                'notes' => mb_substr((string) ($t['notes'] ?? ''), 0, 300),
                'due_on' => $t['due_on'] ?? null,
                'assignee' => $t['assignee']['name'] ?? null,
                'section' => $t['memberships'][0]['section']['name'] ?? null,
                'tags' => array_column($t['tags'] ?? [], 'name'),
                'num_subtasks' => $t['num_subtasks'] ?? 0,
                'amount' => $amount,
                'imported' => $imported,
            ];
        }, $tasks);

        $doneCount = Project::where('client_id', $client?->id)->where('name', AsanaImporter::DONE_PROJECT_NAME)
            ->first()?->timeEntries()->count();

        return response()->json(['data' => $rows, 'meta' => [
            'client' => $client ? ['id' => $client->id, 'name' => $client->name] : null,
            'projects' => $client ? Project::where('client_id', $client->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'color']) : [],
            'completed_imported' => $client ? ProjectTask::whereNotNull('asana_task_gid')->whereNotNull('completed_at')
                ->whereHas('project', fn ($q) => $q->where('client_id', $client->id)->where('name', AsanaImporter::DONE_PROJECT_NAME))->count() : 0,
        ]]);
    }

    /**
     * Runs the decisions for one Asana project. Completed tasks are imported
     * in batches of COMPLETED_BATCH; the response says how many remain.
     */
    public function import(Request $request, string $gid): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'decisions' => ['sometimes', 'array', 'max:500'],
            'decisions.*.gid' => ['required', 'string'],
            'decisions.*.mode' => ['required', Rule::in(['skip', 'task', 'task_new_project', 'project'])],
            'decisions.*.project_id' => ['nullable', 'uuid', InOrganization::exists('projects')],
            'decisions.*.new_project_name' => ['nullable', 'string', 'max:255'],
            'import_completed' => ['sometimes', 'boolean'],
            'with_comments' => ['sometimes', 'boolean'],
            'completed_with_comments' => ['sometimes', 'boolean'],
        ]);

        $client = Client::where('asana_project_gid', $gid)->first();
        if (! $client) {
            return response()->json(['message' => 'Dieses Asana-Projekt ist noch keinem Kunden zugeordnet.'], 422);
        }
        set_time_limit(0);
        $asana = $this->asana();
        $importer = new AsanaImporter($asana, $this->org(), $request->user(), (bool) ($data['with_comments'] ?? true));

        $decisions = collect($data['decisions'] ?? [])->where('mode', '!=', 'skip')->keyBy('gid');
        if ($decisions->isNotEmpty()) {
            $open = collect($asana->tasks($gid, false))->keyBy('gid');
            $newProjects = [];
            foreach ($decisions as $taskGid => $decision) {
                $t = $open->get($taskGid);
                if (! $t) {
                    $importer->summary['errors'][] = "Aufgabe {$taskGid} ist in Asana nicht mehr offen.";

                    continue;
                }
                try {
                    match ($decision['mode']) {
                        'task' => $importer->importTask($t, Project::where('client_id', $client->id)->findOrFail($decision['project_id'] ?? '')),
                        'task_new_project' => $importer->importTask($t, $newProjects[$decision['new_project_name']] ??= $this->newProject($client, $decision['new_project_name'] ?? '', $importer)),
                        'project' => $importer->importTaskAsProject($t, $client),
                    };
                } catch (\Throwable $e) {
                    $importer->summary['errors'][] = "'{$t['name']}': ".$e->getMessage();
                }
            }
        }

        $remaining = 0;
        if (! empty($data['import_completed'])) {
            $withComments = (bool) ($data['completed_with_comments'] ?? false);
            $done = new AsanaImporter($asana, $this->org(), $request->user(), $withComments);
            $project = $done->doneProjectFor($client);
            $completed = array_values(array_filter($asana->tasks($gid, true), fn ($t) => empty($t['parent'])));
            $known = ProjectTask::whereIn('asana_task_gid', array_column($completed, 'gid'))->pluck('asana_task_gid')->flip();
            $pending = array_values(array_filter($completed, fn ($t) => ! isset($known[$t['gid']])));
            foreach (array_slice($pending, 0, self::COMPLETED_BATCH) as $t) {
                try {
                    $done->importTask($t, $project);
                } catch (\Throwable $e) {
                    $done->summary['errors'][] = "'{$t['name']}': ".$e->getMessage();
                }
            }
            $remaining = max(0, count($pending) - self::COMPLETED_BATCH);
            foreach (['tasks_created', 'tasks_updated', 'projects_created', 'comments'] as $k) {
                $importer->summary[$k] += $done->summary[$k];
            }
            $importer->summary['errors'] = array_merge($importer->summary['errors'], $done->summary['errors']);
        }

        return response()->json(['data' => $importer->summary + ['completed_remaining' => $remaining]]);
    }

    // ------------------------------------------------------------------

    private function newProject(Client $client, string $name, AsanaImporter $importer): Project
    {
        $name = trim($name) ?: 'Aus Asana';
        $project = Project::firstOrCreate(
            ['client_id' => $client->id, 'name' => $name],
            ['organization_id' => $this->org()->id, 'color' => $client->color, 'is_billable' => true, 'is_active' => true],
        );
        if ($project->wasRecentlyCreated) {
            $importer->summary['projects_created']++;
        }

        return $project;
    }

    private function org(): Organization
    {
        return app('current_organization');
    }

    private function asana(): AsanaClient
    {
        $org = $this->org();
        abort_if(empty($org->asana_token), 422, 'Kein Asana-Token hinterlegt.');
        abort_if(empty($org->asana_workspace_gid), 422, 'Kein Asana-Workspace bekannt, Token neu speichern.');

        return new AsanaClient($org->asana_token);
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403, 'Nur Admins importieren aus Asana.');
    }
}
