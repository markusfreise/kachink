<?php

namespace App\Services;

use App\Models\ProjectTask;
use App\Models\User;
use App\Notifications\ProjectTaskActivity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Decides who hears about task activity: project watchers, the assignee and
 * members mentioned as "@Vorname Nachname" in the text. The actor never
 * notifies themselves; mentioned members get the "mentioned" wording.
 */
class TaskNotifier
{
    public function notify(ProjectTask $task, string $event, User $actor, ?string $text = null): void
    {
        $task->loadMissing('project');
        $members = $task->project?->organization?->users()->where('users.is_active', true)->get() ?? collect();

        $mentioned = $text ? $this->mentioned($text, $members)->keyBy('id') : collect();

        $recipients = collect();
        foreach ($task->project?->watchers ?? [] as $watcher) {
            $recipients->put($watcher->id, $watcher);
        }
        if ($task->assignee_id && ($assignee = $members->firstWhere('id', $task->assignee_id))) {
            $recipients->put($assignee->id, $assignee);
        }
        $recipients->forget($actor->id);
        $mentioned->forget($actor->id);
        if ($event === 'updated') {
            // Description edits only reach members who were mentioned in them.
            $recipients = collect();
        }

        // Mentioned members get the mention wording, everyone else the event.
        foreach ($mentioned as $user) {
            $recipients->forget($user->id);
            Notification::send($user, new ProjectTaskActivity($task, 'mentioned', $actor, $text));
        }
        foreach ($recipients as $user) {
            if (! $user->is_active) {
                continue;
            }
            Notification::send($user, new ProjectTaskActivity($task, $event, $actor, $text));
        }
    }

    /** Members whose full name appears as @Name in the text (longest names first). */
    public function mentioned(string $text, Collection $members): Collection
    {
        $hits = collect();
        foreach ($members->sortByDesc(fn (User $u) => mb_strlen($u->name)) as $user) {
            $name = trim($user->name);
            if ($name === '') {
                continue;
            }
            if (preg_match('/(^|[^\p{L}\p{N}])@'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $text)) {
                $hits->push($user);
            }
        }

        return $hits;
    }
}
