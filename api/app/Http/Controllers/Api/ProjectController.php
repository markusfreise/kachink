<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\HourlyRates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Project::query()
            ->with('client');

        if ($request->has('filter.client_id')) {
            $query->where('client_id', $request->input('filter.client_id'));
        }

        if ($request->has('filter.is_active')) {
            $query->where('is_active', $request->boolean('filter.is_active'));
        }

        // billing: none | fixed | fixed_open | fixed_billed | hourly
        if ($request->filled('filter.billing')) {
            match ($request->input('filter.billing')) {
                'fixed_open' => $query->where('billing_mode', 'fixed')->whereRaw('COALESCE(billed_amount, 0) < COALESCE(budget_amount, 0)'),
                'fixed_billed' => $query->where('billing_mode', 'fixed')->whereRaw('COALESCE(billed_amount, 0) >= COALESCE(budget_amount, 0)'),
                default => $query->where('billing_mode', $request->input('filter.billing')),
            };
        }

        if ($request->has('filter.is_billable')) {
            $query->where('is_billable', $request->boolean('filter.is_billable'));
        }

        if ($request->filled('filter.name')) {
            $query->where('name', \App\Support\Sql::like(), '%'.$request->input('filter.name').'%');
        }

        if ($request->boolean('include_time_summary')) {
            $this->withTimeSummary($query);
        }

        $sort = $request->input('sort', 'name');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return ProjectResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = Project::create($request->validated());
        $project->load('client');

        return response()->json([
            'data' => new ProjectResource($project),
        ], 201);
    }

    public function show(Project $project): JsonResponse
    {
        $project->load(['client', 'watchers']);
        $project->loadSum(['timeEntries as tracked_seconds' => fn ($q) => $q->where('is_running', false)], 'duration_seconds');
        $project->loadSum(['timeEntries as billable_seconds' => fn ($q) => $q->where('is_running', false)->where('is_billable', true)], 'duration_seconds');
        $project->billable_amount = app(HourlyRates::class)->billableAmount($project);

        return response()->json([
            'data' => new ProjectResource($project),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $project->update($request->validated());
        $project->load('client');

        return response()->json([
            'data' => new ProjectResource($project),
        ]);
    }

    /**
     * Aggregate tracked/billable seconds in SQL instead of loading every time entry.
     */
    private function withTimeSummary(Builder $query): void
    {
        $query
            ->withSum(['timeEntries as tracked_seconds' => fn ($q) => $q->where('is_running', false)], 'duration_seconds')
            ->withSum(['timeEntries as billable_seconds' => fn ($q) => $q->where('is_running', false)->where('is_billable', true)], 'duration_seconds');
    }

    public function destroy(Project $project): JsonResponse
    {
        $project->update([
            'is_active' => false,
            'archived_at' => now(),
        ]);

        return response()->json(null, 204);
    }
}
