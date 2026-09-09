<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::create([
            'name' => 'freise design+digital',
            'slug' => 'freise',
            'is_active' => true,
        ]);

        // Scoped models pick up organization_id from the bound instance.
        app()->instance('current_organization', $organization);

        $admin = User::factory()->admin()->create([
            'name' => 'Markus Freise',
            'email' => 'markus@freise.design',
        ]);

        $members = User::factory(3)->create();
        $allUsers = collect([$admin])->merge($members);

        $organization->users()->attach($admin->id, ['role' => 'owner']);
        $members->each(fn (User $u) => $organization->users()->attach($u->id, ['role' => 'member']));

        $tags = collect([
            ['name' => 'Development', 'color' => '#3B82F6'],
            ['name' => 'Design', 'color' => '#8B5CF6'],
            ['name' => 'Meeting', 'color' => '#F59E0B'],
            ['name' => 'Support', 'color' => '#EF4444'],
            ['name' => 'Planning', 'color' => '#10B981'],
        ])->map(fn ($data) => Tag::create($data));

        // Tasks are global per organization (not per project).
        $tasks = collect(['Konzeption', 'Design', 'Entwicklung', 'Projektmanagement', 'Meeting', 'Support'])
            ->map(fn ($name) => Task::create(['name' => $name, 'is_active' => true]));

        $clients = Client::factory(4)->create();

        $clients->each(function (Client $client) use ($allUsers, $tags, $tasks) {
            $projects = Project::factory(rand(1, 3))->create([
                'client_id' => $client->id,
                'hourly_rate' => collect([85, 95, 110, 120])->random(),
            ]);

            $projects->each(function (Project $project) use ($allUsers, $tags, $tasks) {
                $allUsers->each(function (User $user) use ($project, $tasks, $tags) {
                    TimeEntry::factory(rand(5, 15))->create([
                        'user_id' => $user->id,
                        'project_id' => $project->id,
                        'task_id' => $tasks->random()->id,
                    ])->each(function (TimeEntry $entry) use ($tags) {
                        $entry->tags()->attach($tags->random(rand(1, 2))->pluck('id'));
                    });
                });
            });
        });
    }
}
