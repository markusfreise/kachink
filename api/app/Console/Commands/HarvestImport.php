<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class HarvestImport extends Command
{
    protected $signature = 'harvest:import
        {--token= : Harvest Personal Access Token (or set HARVEST_TOKEN in .env)}
        {--account= : Harvest Account ID (or set HARVEST_ACCOUNT_ID in .env)}
        {--org= : Organization ID or slug to import into (required)}
        {--fresh : Wipe all existing org data before importing}';

    protected $description = 'Import users, clients, projects, tasks, and time entries from Harvest';

    private ?string $token;
    private ?string $accountId;
    private Organization $organization;
    private array $userMap = [];    // harvest_id -> local User
    private array $clientMap = [];  // harvest_id -> local Client
    private array $projectMap = []; // harvest_id -> local Project
    private array $taskMap = [];    // harvest_id -> local Task

    public function handle(): int
    {
        $this->token = $this->option('token') ?: config('services.harvest.token');
        $this->accountId = $this->option('account') ?: config('services.harvest.account_id');

        if (!$this->token || !$this->accountId) {
            $this->error('Set HARVEST_TOKEN and HARVEST_ACCOUNT_ID in .env or pass --token and --account.');
            return 1;
        }

        $orgOption = $this->option('org');
        if (!$orgOption) {
            $this->error('Specify the target organization with --org=<id-or-slug>.');
            return 1;
        }

        $this->organization = Organization::where('id', $orgOption)
            ->orWhere('slug', $orgOption)
            ->first();

        if (!$this->organization) {
            $this->error("Organization '{$orgOption}' not found.");
            return 1;
        }

        app()->instance('current_organization', $this->organization);

        if ($this->option('fresh')) {
            if (!$this->confirm("This will DELETE all existing data for org '{$this->organization->name}'. Continue?")) {
                return 0;
            }
            $this->wipe();
        }

        $this->importUsers();
        $this->importClients();
        $this->importProjects();
        $this->importTasks();
        $this->importTimeEntries();

        $this->info('');
        $this->info('Import complete.');
        return 0;
    }

    private function wipe(): void
    {
        $this->warn('Wiping existing org data...');
        $orgId = $this->organization->id;
        DB::table('time_entry_tag')
            ->whereIn('time_entry_id', DB::table('time_entries')->where('organization_id', $orgId)->pluck('id'))
            ->delete();
        TimeEntry::withoutGlobalScope('organization')->where('organization_id', $orgId)->delete();
        Task::withoutGlobalScope('organization')->where('organization_id', $orgId)->delete();
        Project::withoutGlobalScope('organization')->where('organization_id', $orgId)->delete();
        Client::withoutGlobalScope('organization')->where('organization_id', $orgId)->delete();
        $this->organization->users()->wherePivot('role', '!=', 'owner')->detach();
        $this->line('Done.');
    }

    private function importUsers(): void
    {
        $this->info('Importing users...');
        $users = $this->fetchAll('/users', 'users');

        foreach ($users as $hu) {
            if (!$hu['is_active']) {
                continue;
            }

            $orgRole = in_array('administrator', $hu['access_roles'] ?? []) ? 'admin' : 'member';
            $user = User::updateOrCreate(
                ['harvest_id' => (string) $hu['id']],
                [
                    'name' => $hu['first_name'] . ' ' . $hu['last_name'],
                    'email' => $hu['email'],
                    'password' => Hash::make(Str::random(32)),
                    'role' => 'member',
                    'is_active' => true,
                ]
            );
            $this->organization->users()->syncWithoutDetaching([
                $user->id => ['role' => $orgRole],
            ]);
            $this->userMap[(string) $hu['id']] = $user;
        }

        $this->line("  → {$this->pluralize(count($this->userMap), 'user')} imported.");
    }

    private function importClients(): void
    {
        $this->info('Importing clients...');
        $clients = $this->fetchAll('/clients', 'clients');

        foreach ($clients as $hc) {
            $slug = Str::slug($hc['name']);
            $client = Client::where('harvest_id', (string) $hc['id'])->first()
                ?? Client::where('slug', $slug)->first()
                ?? new Client();
            $client->fill([
                'harvest_id' => (string) $hc['id'],
                'name' => $hc['name'],
                'slug' => $slug,
                'is_active' => $hc['is_active'],
            ])->save();
            $this->clientMap[(string) $hc['id']] = $client;
        }

        $this->line("  → {$this->pluralize(count($this->clientMap), 'client')} imported.");
    }

    private function importProjects(): void
    {
        $this->info('Importing projects...');
        $projects = $this->fetchAll('/projects', 'projects');

        foreach ($projects as $hp) {
            $client = $hp['client'] ? ($this->clientMap[(string) $hp['client']['id']] ?? null) : null;
            if (!$client) {
                continue;
            }

            $slug = Str::slug($hp['name']);
            $project = Project::where('harvest_id', (string) $hp['id'])->first()
                ?? Project::where('client_id', $client->id)->where('slug', $slug)->first()
                ?? new Project();
            $project->fill([
                'harvest_id' => (string) $hp['id'],
                'client_id' => $client->id,
                'name' => $hp['name'],
                'slug' => $slug,
                'is_billable' => $hp['is_billable'],
                'is_active' => $hp['is_active'],
                'budget_hours' => $hp['budget'] ?? null,
                'hourly_rate' => $hp['hourly_rate'] ?? null,
                'color' => $project->color ?? $this->randomColor(),
            ])->save();
            $this->projectMap[(string) $hp['id']] = $project;
        }

        $this->line("  → {$this->pluralize(count($this->projectMap), 'project')} imported.");
    }

    private function importTasks(): void
    {
        $this->info('Importing tasks...');
        $tasks = $this->fetchAll('/tasks', 'tasks');

        foreach ($tasks as $ht) {
            $task = Task::updateOrCreate(
                ['harvest_id' => (string) $ht['id']],
                [
                    'name' => $ht['name'],
                    'is_active' => $ht['is_active'],
                ]
            );
            $this->taskMap[(string) $ht['id']] = $task;
        }

        $this->line("  → {$this->pluralize(count($this->taskMap), 'task')} imported.");
    }

    private function importTimeEntries(): void
    {
        $this->info('Importing time entries...');
        $entries = $this->fetchAll('/time_entries', 'time_entries');
        $imported = 0;
        $skipped = 0;

        foreach ($entries as $he) {
            $project = $he['project'] ? ($this->projectMap[(string) $he['project']['id']] ?? null) : null;
            if (!$project) {
                $skipped++;
                continue;
            }

            $user = $he['user'] ? ($this->userMap[(string) $he['user']['id']] ?? null) : null;
            if (!$user) {
                $skipped++;
                continue;
            }

            $task = $he['task'] ? ($this->taskMap[(string) $he['task']['id']] ?? null) : null;

            $spentDate = $he['spent_date']; // "YYYY-MM-DD"
            $hours = (float) $he['hours'];
            $durationSeconds = (int) round($hours * 3600);

            TimeEntry::updateOrCreate(
                ['harvest_id' => (string) $he['id']],
                [
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'task_id' => $task?->id,
                    'description' => $he['notes'] ?: null,
                    'started_at' => $spentDate . ' 00:00:00',
                    'stopped_at' => date('Y-m-d H:i:s', strtotime($spentDate . ' 00:00:00') + $durationSeconds),
                    'duration_seconds' => $durationSeconds,
                    'is_billable' => $he['billable'],
                    'is_running' => false,
                    'source' => 'harvest',
                ]
            );
            $imported++;
        }

        $this->line("  → {$this->pluralize($imported, 'time entry')} imported" . ($skipped ? ", {$skipped} skipped (missing project/user)." : '.'));
    }

    private function fetchAll(string $endpoint, string $key): array
    {
        $results = [];
        $page = 1;

        do {
            $response = Http::withToken($this->token)
                ->withHeaders(['Harvest-Account-Id' => $this->accountId])
                ->get("https://api.harvestapp.com/v2{$endpoint}", [
                    'page' => $page,
                    'per_page' => 100,
                ]);

            if ($response->failed()) {
                $this->error("  Harvest API error on {$endpoint}: " . $response->status());
                break;
            }

            $body = $response->json();
            $items = $body[$key] ?? [];
            $results = array_merge($results, $items);

            $totalPages = $body['total_pages'] ?? 1;
            $page++;
        } while ($page <= $totalPages);

        return $results;
    }

    private function randomColor(): string
    {
        $colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#06B6D4', '#84CC16', '#F97316'];
        return $colors[array_rand($colors)];
    }

    private function pluralize(int $n, string $noun): string
    {
        return "{$n} {$noun}" . ($n !== 1 ? 's' : '');
    }
}
