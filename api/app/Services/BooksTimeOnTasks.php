<?php

namespace App\Services;

use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Shared by timer start and manual entries: links the entry to a project
 * task, or creates that task from the entry's description on request
 * ("Add as task to project", optionally completed right away). The task is
 * assigned to the person who books the time.
 */
class BooksTimeOnTasks
{
    /** Mutates $data: resolves project_task_id, drops the create/complete flags. */
    public function apply(array &$data, User $user): void
    {
        $createTask = (bool) ($data['create_task'] ?? false);
        $completeTask = (bool) ($data['complete_task'] ?? false);
        unset($data['create_task'], $data['complete_task']);

        if ($createTask) {
            $title = trim((string) ($data['description'] ?? ''));
            if ($title === '') {
                throw ValidationException::withMessages(['description' => 'A description is needed to create a task from this entry.']);
            }
            $task = ProjectTask::create([
                'project_id' => $data['project_id'],
                'title' => mb_substr($title, 0, 255),
                'assignee_id' => $user->id,
                'created_by' => $user->id,
                'priority' => 'soon',
                'completed_at' => $completeTask ? now() : null,
            ]);
            $data['project_task_id'] = $task->id;

            return;
        }

        if (! empty($data['project_task_id'])) {
            $task = ProjectTask::findOrFail($data['project_task_id']);
            if (isset($data['project_id']) && $task->project_id !== $data['project_id']) {
                throw ValidationException::withMessages(['project_task_id' => 'The task belongs to a different project.']);
            }
        }
    }
}
