<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TimeEntry\StartTimerRequest;
use App\Http\Requests\TimeEntry\StoreTimeEntryRequest;
use App\Http\Requests\TimeEntry\UpdateTimeEntryRequest;
use App\Http\Resources\TimeEntryResource;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TimeEntryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TimeEntry::query()
            ->with(['project.client', 'task', 'tags', 'user']);

        // Scope to current user unless admin requesting all
        if (!$request->user()->isAdmin() || !$request->boolean('all_users')) {
            $query->where('user_id', $request->user()->id);
        }

        if ($request->has('filter.project_id')) {
            $query->where('project_id', $request->input('filter.project_id'));
        }

        if ($request->has('filter.task_id')) {
            $query->where('task_id', $request->input('filter.task_id'));
        }

        if ($request->has('filter.is_billable')) {
            $query->where('is_billable', $request->boolean('filter.is_billable'));
        }

        if ($request->has('filter.date_from')) {
            $query->where('started_at', '>=', $request->input('filter.date_from'));
        }

        if ($request->has('filter.date_to')) {
            $query->where('started_at', '<=', $request->input('filter.date_to') . ' 23:59:59');
        }

        if ($request->has('filter.is_running')) {
            $query->where('is_running', $request->boolean('filter.is_running'));
        }

        $sort = $request->input('sort', '-started_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return TimeEntryResource::collection($query->paginate($request->integer('per_page', 50)));
    }

    public function store(StoreTimeEntryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->id;
        $data['source'] = $data['source'] ?? 'manual';

        // Duration-only manual entry: compute started_at/stopped_at from date + duration
        if (isset($data['duration_seconds']) && !isset($data['started_at'])) {
            $date = $data['date'] ?? now()->toDateString();
            $data['started_at'] = \Carbon\Carbon::parse($date)->startOfDay();
            $data['stopped_at'] = $data['started_at']->copy()->addSeconds($data['duration_seconds']);
            $data['is_running'] = false;
        } elseif (isset($data['stopped_at']) && !isset($data['duration_seconds'])) {
            $started = \Carbon\Carbon::parse($data['started_at']);
            $stopped = \Carbon\Carbon::parse($data['stopped_at']);
            $data['duration_seconds'] = $started->diffInSeconds($stopped);
        }

        unset($data['date']);

        $tagIds = $data['tag_ids'] ?? [];
        unset($data['tag_ids']);

        $entry = TimeEntry::create($data);

        if ($tagIds) {
            $entry->tags()->sync($tagIds);
        }

        $entry->load(['project.client', 'task', 'tags', 'user']);

        return response()->json([
            'data' => new TimeEntryResource($entry),
        ], 201);
    }

    public function show(TimeEntry $timeEntry): JsonResponse
    {
        $timeEntry->load(['project.client', 'task', 'tags', 'user']);

        return response()->json([
            'data' => new TimeEntryResource($timeEntry),
        ]);
    }

    public function update(UpdateTimeEntryRequest $request, TimeEntry $timeEntry): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['stopped_at']) && !isset($data['duration_seconds'])) {
            $started = \Carbon\Carbon::parse($data['started_at'] ?? $timeEntry->started_at);
            $stopped = \Carbon\Carbon::parse($data['stopped_at']);
            $data['duration_seconds'] = $started->diffInSeconds($stopped);
            $data['is_running'] = false;
        }

        $tagIds = $data['tag_ids'] ?? null;
        unset($data['tag_ids']);

        $timeEntry->update($data);

        if ($tagIds !== null) {
            $timeEntry->tags()->sync($tagIds);
        }

        $timeEntry->load(['project.client', 'task', 'tags', 'user']);

        return response()->json([
            'data' => new TimeEntryResource($timeEntry),
        ]);
    }

    public function destroy(Request $request, TimeEntry $timeEntry): JsonResponse
    {
        if (!$request->user()->isAdmin() && $timeEntry->user_id !== $request->user()->id) {
            abort(403);
        }

        $timeEntry->delete();

        return response()->json(null, 204);
    }

    public function start(StartTimerRequest $request): JsonResponse
    {
        // Stop any currently running timer for this user
        $running = TimeEntry::where('user_id', $request->user()->id)
            ->where('is_running', true)
            ->first();

        if ($running) {
            $running->stop();
        }

        $data = $request->validated();
        $data['user_id'] = $request->user()->id;
        $data['started_at'] = now();
        $data['is_running'] = true;
        $data['source'] = $data['source'] ?? 'web';

        $project = \App\Models\Project::findOrFail($data['project_id']);
        $data['is_billable'] = $data['is_billable'] ?? $project->is_billable;

        $tagIds = $data['tag_ids'] ?? [];
        unset($data['tag_ids']);

        $entry = TimeEntry::create($data);

        if ($tagIds) {
            $entry->tags()->sync($tagIds);
        }

        $entry->load(['project.client', 'task', 'tags', 'user']);

        return response()->json([
            'data' => new TimeEntryResource($entry),
        ], 201);
    }

    public function stop(Request $request): JsonResponse
    {
        $entry = TimeEntry::where('user_id', $request->user()->id)
            ->where('is_running', true)
            ->firstOrFail();

        $entry->stop();
        $entry->load(['project.client', 'task', 'tags', 'user']);

        return response()->json([
            'data' => new TimeEntryResource($entry),
        ]);
    }

    public function running(Request $request): JsonResponse
    {
        $entry = TimeEntry::where('user_id', $request->user()->id)
            ->where('is_running', true)
            ->with(['project.client', 'task', 'tags'])
            ->first();

        return response()->json([
            'data' => $entry ? new TimeEntryResource($entry) : null,
        ]);
    }
}
