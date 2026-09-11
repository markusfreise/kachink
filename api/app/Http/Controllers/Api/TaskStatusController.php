<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskStatusResource;
use App\Models\TaskStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Task statuses are free-form per organization; any member may add, rename
 * or remove them. The fixed "Heute" status is locked.
 */
class TaskStatusController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        TaskStatus::ensureDefaults(app('current_organization'));

        return TaskStatusResource::collection(
            TaskStatus::withCount('tasks')->orderBy('position')->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', $this->uniqueName()],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);
        $data['position'] = $data['position'] ?? ((int) TaskStatus::max('position') + 1);

        $status = TaskStatus::create($data);

        return response()->json(['data' => new TaskStatusResource($status)], 201);
    }

    public function update(Request $request, TaskStatus $task_status): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80', $this->uniqueName($task_status)],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($task_status->is_locked && array_key_exists('name', $data) && $data['name'] !== $task_status->name) {
            abort(422, 'The default status cannot be renamed.');
        }

        $task_status->update($data);

        return response()->json(['data' => new TaskStatusResource($task_status->loadCount('tasks'))]);
    }

    /** Column order on the board. Statuses not listed keep their position. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ordered_ids' => ['required', 'array', 'max:200'],
            'ordered_ids.*' => ['uuid'],
        ]);

        $statuses = TaskStatus::whereIn('id', $data['ordered_ids'])->get()->keyBy('id');
        foreach (array_values($data['ordered_ids']) as $position => $id) {
            $statuses->get($id)?->update(['position' => $position + 1]);
        }

        return response()->json(['data' => ['updated' => $statuses->count()]]);
    }

    public function destroy(TaskStatus $task_status): JsonResponse
    {
        if ($task_status->is_locked) {
            abort(422, 'The default status cannot be deleted.');
        }

        $task_status->delete(); // tasks keep going, status_id becomes null

        return response()->json(null, 204);
    }

    private function uniqueName(?TaskStatus $ignore = null): \Illuminate\Validation\Rules\Unique
    {
        $rule = Rule::unique('task_statuses', 'name')->where('organization_id', app('current_organization')->id);

        return $ignore ? $rule->ignore($ignore->id) : $rule;
    }
}
