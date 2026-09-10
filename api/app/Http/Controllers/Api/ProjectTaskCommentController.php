<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectTask\StoreProjectTaskCommentRequest;
use App\Http\Resources\ProjectTaskCommentResource;
use App\Models\ProjectTask;
use App\Models\ProjectTaskComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectTaskCommentController extends Controller
{
    public function store(StoreProjectTaskCommentRequest $request, ProjectTask $project_task): JsonResponse
    {
        $comment = $project_task->comments()->create([
            'organization_id' => $project_task->organization_id,
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);
        $project_task->recordHistory('comment_added', ['comment_id' => $comment->id]);
        $project_task->touch();

        return response()->json(['data' => new ProjectTaskCommentResource($comment->load('user'))], 201);
    }

    public function update(StoreProjectTaskCommentRequest $request, ProjectTask $project_task, ProjectTaskComment $comment): JsonResponse
    {
        $this->assertOwnership($request, $project_task, $comment);

        $comment->update(['body' => $request->validated('body')]);

        return response()->json(['data' => new ProjectTaskCommentResource($comment->load('user'))]);
    }

    public function destroy(Request $request, ProjectTask $project_task, ProjectTaskComment $comment): JsonResponse
    {
        $this->assertOwnership($request, $project_task, $comment);

        $comment->delete();
        $project_task->recordHistory('comment_deleted', ['comment_id' => $comment->id]);

        return response()->json(null, 204);
    }

    private function assertOwnership(Request $request, ProjectTask $task, ProjectTaskComment $comment): void
    {
        if ($comment->project_task_id !== $task->id) {
            abort(404);
        }
        if (! $request->user()->isAdmin() && $comment->user_id !== $request->user()->id) {
            abort(403, 'Only the author or an admin can change this comment.');
        }
    }
}
