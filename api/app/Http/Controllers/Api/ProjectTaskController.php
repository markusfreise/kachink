<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectTask\StoreProjectTaskRequest;
use App\Http\Requests\ProjectTask\UpdateProjectTaskRequest;
use App\Http\Resources\ProjectTaskResource;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAttachment;
use App\Rules\InOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProjectTaskController extends Controller
{
    private const PRIORITY_ORDER = "CASE priority WHEN 'immediate' THEN 0 WHEN 'urgent' THEN 1 WHEN 'soon' THEN 2 ELSE 3 END";

    private const SORTABLE = ['priority', 'deadline', 'created_at', 'updated_at', 'title', 'position'];

    public function index(Request $request): AnonymousResourceCollection
    {
        ProjectTask::moveDueTodayIntoHeute(app('current_organization'));

        $query = ProjectTask::query()
            ->with(['assignee', 'tags', 'status', 'project.client'])
            ->withCount([
                'children',
                'children as open_children_count' => fn ($q) => $q->whereNull('completed_at'),
                'comments',
                'attachments',
            ])
            ->withMin(['children as earliest_child_deadline' => fn ($q) => $q->whereNull('completed_at')], 'deadline');

        if ($request->filled('filter.project_id')) {
            $query->where('project_id', $request->input('filter.project_id'));
        }

        // parent_id=root lists top-level tasks only; a uuid lists the children of that task.
        if ($request->has('filter.parent_id')) {
            $parent = $request->input('filter.parent_id');
            if ($parent === 'root' || $parent === '' || $parent === null) {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $parent);
            }
        }

        if ($request->filled('filter.assignee_id')) {
            $assignee = $request->input('filter.assignee_id');
            $assignee === 'none' ? $query->whereNull('assignee_id') : $query->where('assignee_id', $assignee);
        }

        if ($request->filled('filter.status_id')) {
            $statusId = $request->input('filter.status_id');
            $statusId === 'none' ? $query->whereNull('status_id') : $query->where('status_id', $statusId);
        }

        // Every task whose deadline is on or before the given day.
        if ($request->filled('filter.deadline_until')) {
            $query->whereNotNull('deadline')->whereDate('deadline', '<=', $request->input('filter.deadline_until'));
        }

        if ($request->filled('filter.priority')) {
            $query->whereIn('priority', explode(',', $request->input('filter.priority')));
        }

        match ($request->input('filter.status', 'all')) {
            'open' => $query->whereNull('completed_at'),
            'done' => $query->whereNotNull('completed_at'),
            default => null,
        };

        if ($request->filled('filter.tag_id')) {
            $tagId = $request->input('filter.tag_id');
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $tagId));
        }

        if ($request->filled('filter.q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->input('filter.q')).'%';
            $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('description', 'like', $term));
        }

        $this->applySort($query, $request->input('sort', 'priority'));

        $perPage = min(max($request->integer('per_page', 50), 1), 500);

        return ProjectTaskResource::collection($query->paginate($perPage));
    }

    public function store(StoreProjectTaskRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tagIds = $data['tag_ids'] ?? null;
        unset($data['tag_ids']);

        if (! empty($data['parent_id'])) {
            $parent = ProjectTask::findOrFail($data['parent_id']);
            if ($parent->project_id !== $data['project_id']) {
                throw ValidationException::withMessages(['parent_id' => 'The parent task belongs to a different project.']);
            }
        }

        $data['priority'] = $data['priority'] ?? 'soon';
        $data['created_by'] = $request->user()->id;

        $task = DB::transaction(function () use ($data, $tagIds) {
            $task = ProjectTask::create($data);
            if ($tagIds !== null) {
                $task->tags()->sync($tagIds);
            }

            return $task;
        });

        return response()->json(['data' => new ProjectTaskResource($this->loadDetail($task))], 201);
    }

    public function show(ProjectTask $project_task): JsonResponse
    {
        ProjectTask::moveDueTodayIntoHeute(app('current_organization'));
        $project_task->refresh();

        return response()->json(['data' => new ProjectTaskResource($this->loadDetail($project_task))]);
    }

    public function update(UpdateProjectTaskRequest $request, ProjectTask $project_task): JsonResponse
    {
        $data = $request->validated();
        $tagIds = $data['tag_ids'] ?? null;
        unset($data['tag_ids']);

        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
            $parent = ProjectTask::findOrFail($data['parent_id']);
            if ($parent->project_id !== $project_task->project_id) {
                throw ValidationException::withMessages(['parent_id' => 'The parent task belongs to a different project.']);
            }
            if ($project_task->isSelfOrDescendant($parent->id)) {
                throw ValidationException::withMessages(['parent_id' => 'A task cannot be moved below itself.']);
            }
        }

        if (array_key_exists('completed', $data)) {
            $completed = (bool) $data['completed'];
            unset($data['completed']);
            if ($completed && ! $project_task->completed_at) {
                $data['completed_at'] = now();
            } elseif (! $completed && $project_task->completed_at) {
                $data['completed_at'] = null;
            }
        }

        // Keeping a task in Heute confirms today's marker; a new deadline resets it.
        if (! empty($data['acknowledge_today'])) {
            $data['today_acknowledged_on'] = ProjectTask::today();
        }
        unset($data['acknowledge_today']);
        if (array_key_exists('deadline', $data) && $data['deadline'] !== $project_task->deadline?->format('Y-m-d')) {
            $data['today_acknowledged_on'] = null;
            $data['today_moved_on'] = null;
        }

        // A new reminder date arms the reminder again.
        if (array_key_exists('reminder_at', $data) && $data['reminder_at'] !== $project_task->reminder_at?->format('Y-m-d')) {
            $data['reminder_sent_at'] = null;
        }

        DB::transaction(function () use ($project_task, $data, $tagIds) {
            $project_task->update($data);

            if ($tagIds !== null) {
                $before = $project_task->tags()->pluck('tags.id')->sort()->values()->all();
                $project_task->tags()->sync($tagIds);
                $after = $project_task->tags()->pluck('tags.id')->sort()->values()->all();
                if ($before !== $after) {
                    $names = fn (array $ids) => \App\Models\Tag::whereIn('id', $ids)->orderBy('name')->pluck('name')->all();
                    $project_task->recordHistory('updated', ['tags' => ['from' => $names($before), 'to' => $names($after)]]);
                }
            }
        });

        return response()->json(['data' => new ProjectTaskResource($this->loadDetail($project_task->fresh()))]);
    }

    /**
     * Board drop: puts the listed tasks into a status column in the given order.
     * Tasks not listed keep their status and position.
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status_id' => ['nullable', 'uuid', InOrganization::exists('task_statuses')],
            'ordered_ids' => ['required', 'array', 'max:500'],
            'ordered_ids.*' => ['uuid'],
        ]);

        $tasks = ProjectTask::whereIn('id', $data['ordered_ids'])->get()->keyBy('id');
        DB::transaction(function () use ($data, $tasks) {
            foreach (array_values($data['ordered_ids']) as $position => $id) {
                $task = $tasks->get($id);
                if (! $task) {
                    continue;
                }
                $task->fill(['status_id' => $data['status_id'] ?? null, 'position' => $position]);
                if ($task->isDirty()) {
                    $task->save();
                }
            }
        });

        return response()->json(['data' => ['updated' => $tasks->count()]]);
    }

    public function destroy(Request $request, ProjectTask $project_task): JsonResponse
    {
        if (! $request->user()->isAdmin() && $project_task->created_by !== $request->user()->id) {
            abort(403, 'Only the creator or an admin can delete a task.');
        }

        // The DB cascades subtasks and their rows; the files on disk are ours to clean up.
        $ids = [$project_task->id];
        $frontier = [$project_task->id];
        while ($frontier) {
            $frontier = ProjectTask::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }
        ProjectTaskAttachment::whereIn('project_task_id', $ids)->pluck('path')
            ->each(fn (string $path) => Storage::disk(ProjectTaskAttachment::DISK)->delete($path));

        $project_task->delete();

        return response()->json(null, 204);
    }

    private function applySort(Builder $query, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (! in_array($column, self::SORTABLE, true)) {
            $column = 'priority';
        }

        if ($column === 'priority') {
            $query->orderByRaw(self::PRIORITY_ORDER.' '.$direction);
            $query->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')->orderBy('deadline');
        } elseif ($column === 'deadline') {
            $query->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')->orderBy('deadline', $direction);
        } else {
            $query->orderBy($column, $direction);
        }

        $query->orderBy('position')->orderBy('created_at');
    }

    private function loadDetail(ProjectTask $task): ProjectTask
    {
        $task->load([
            'assignee', 'creator', 'tags', 'status', 'project.client', 'parent',
            'children' => fn ($q) => $q->with(['assignee', 'tags', 'status', 'project'])->withCount([
                'children',
                'children as open_children_count' => fn ($c) => $c->whereNull('completed_at'),
                'comments',
                'attachments',
            ])->withMin(['children as earliest_child_deadline' => fn ($c) => $c->whereNull('completed_at')], 'deadline'),
            'comments.user', 'attachments.user', 'histories.user',
        ]);
        $task->loadSum(['timeEntries as tracked_seconds' => fn ($q) => $q->where('is_running', false)], 'duration_seconds');
        $task->loadCount([
            'children',
            'children as open_children_count' => fn ($q) => $q->whereNull('completed_at'),
            'comments',
            'attachments',
        ]);
        $task->ancestorChain = $task->ancestors();

        return $task;
    }
}
