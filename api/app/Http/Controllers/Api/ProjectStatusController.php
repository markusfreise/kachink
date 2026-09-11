<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskStatusResource;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Rules\InOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Project statuses of one project: free-form, any member may manage them, clonable from another project. */
class ProjectStatusController extends Controller
{
    public function index(Project $project): AnonymousResourceCollection
    {
        return TaskStatusResource::collection($this->list($project));
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', $this->uniqueName($project)],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
        $status = ProjectStatus::create($data + [
            'project_id' => $project->id,
            'position' => (int) ProjectStatus::where('project_id', $project->id)->max('position') + 1,
        ]);

        return response()->json(['data' => new TaskStatusResource($status)], 201);
    }

    public function update(Request $request, Project $project, ProjectStatus $status): JsonResponse
    {
        abort_if($status->project_id !== $project->id, 404);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80', $this->uniqueName($project, $status)],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
        $status->update($data);

        return response()->json(['data' => new TaskStatusResource($status->loadCount('tasks'))]);
    }

    public function reorder(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['ordered_ids' => ['required', 'array', 'max:200'], 'ordered_ids.*' => ['uuid']]);
        $statuses = ProjectStatus::where('project_id', $project->id)->whereIn('id', $data['ordered_ids'])->get()->keyBy('id');
        foreach (array_values($data['ordered_ids']) as $position => $id) {
            $statuses->get($id)?->update(['position' => $position + 1]);
        }

        return response()->json(['data' => TaskStatusResource::collection($this->list($project))]);
    }

    /** Copies the status columns (not the tasks) of another project; existing names are kept. */
    public function clone(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['from_project_id' => ['required', 'uuid', InOrganization::exists('projects')]]);
        $source = Project::findOrFail($data['from_project_id']);
        abort_if($source->id === $project->id, 422, 'Source and target are the same project.');

        foreach (ProjectStatus::where('project_id', $source->id)->orderBy('position')->get() as $s) {
            ProjectStatus::forProject($project, $s->name, $s->color);
        }

        return response()->json(['data' => TaskStatusResource::collection($this->list($project))]);
    }

    public function destroy(Project $project, ProjectStatus $status): JsonResponse
    {
        abort_if($status->project_id !== $project->id, 404);
        $status->delete();

        return response()->json(null, 204);
    }

    /** Projects that have statuses, as clone sources. */
    public function sources(Project $project): JsonResponse
    {
        $rows = Project::whereHas('statuses')->where('id', '!=', $project->id)->orderBy('name')->get(['id', 'name', 'color']);

        return response()->json(['data' => $rows]);
    }

    private function list(Project $project)
    {
        return ProjectStatus::where('project_id', $project->id)->withCount('tasks')->orderBy('position')->orderBy('name')->get();
    }

    /** Name unique inside the project, case-insensitive. */
    private function uniqueName(Project $project, ?ProjectStatus $ignore = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($project, $ignore) {
            $query = ProjectStatus::where('project_id', $project->id)->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)]);
            if ($ignore) {
                $query->where('id', '!=', $ignore->id);
            }
            if ($query->exists()) {
                $fail('Diesen Projektstatus gibt es schon.');
            }
        };
    }
}
