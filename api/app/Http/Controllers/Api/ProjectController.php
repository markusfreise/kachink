<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
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

        if ($request->has('filter.is_billable')) {
            $query->where('is_billable', $request->boolean('filter.is_billable'));
        }

        if ($request->filled('filter.name')) {
            $query->where('name', 'like', '%' . $request->input('filter.name') . '%');
        }

        if ($request->boolean('include_time_summary')) {
            $query->with('timeEntries');
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
        $project->load(['client', 'timeEntries']);

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

    public function destroy(Project $project): JsonResponse
    {
        $project->update([
            'is_active' => false,
            'archived_at' => now(),
        ]);

        return response()->json(null, 204);
    }
}
