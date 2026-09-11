<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Watchers of a project: any member may watch, unwatch or set the list. */
class ProjectWatcherController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        return response()->json(['data' => UserResource::collection($project->watchers()->orderBy('name')->get())]);
    }

    public function sync(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['uuid'],
        ]);
        $memberIds = app('current_organization')->users()->pluck('users.id')->all();
        $ids = array_values(array_intersect($data['user_ids'], $memberIds));
        $project->watchers()->sync(array_fill_keys($ids, ['created_at' => now()]));

        return response()->json(['data' => UserResource::collection($project->watchers()->orderBy('name')->get())]);
    }

    public function watch(Request $request, Project $project): JsonResponse
    {
        $project->watchers()->syncWithoutDetaching([$request->user()->id => ['created_at' => now()]]);

        return response()->json(['data' => UserResource::collection($project->watchers()->orderBy('name')->get())]);
    }

    public function unwatch(Request $request, Project $project): JsonResponse
    {
        $project->watchers()->detach($request->user()->id);

        return response()->json(['data' => UserResource::collection($project->watchers()->orderBy('name')->get())]);
    }
}
