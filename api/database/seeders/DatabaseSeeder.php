<?php

namespace Database\Seeders;

use App\Models\Client;
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
        // Admin user
        $admin = User::factory()->admin()->create([
            'name' => 'Markus Freise',
            'email' => 'markus@freise.design',
        ]);

        // Team members
        $members = User::factory(3)->create();
        $allUsers = collect([$admin])->merge($members);

        // Tags
        $tags = collect([
            ['name' => 'Development', 'color' => '#3B82F6'],
            ['name' => 'Design', 'color' => '#8B5CF6'],
            ['name' => 'Meeting', 'color' => '#F59E0B'],
            ['name' => 'Support', 'color' => '#EF4444'],
            ['name' => 'Planning', 'color' => '#10B981'],
        ])->map(fn ($data) => Tag::create($data));

        // Clients with projects and tasks
        $clients = Client::factory(4)->create();

        $clients->each(function (Client $client) use ($allUsers, $tags) {
            $projects = Project::factory(rand(1, 3))->create([
                'client_id' => $client->id,
            ]);

            $projects->each(function (Project $project) use ($allUsers, $tags) {
                $tasks = Task::factory(rand(3, 6))->create([
                    'project_id' => $project->id,
                ]);

                // Create time entries for each user
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
