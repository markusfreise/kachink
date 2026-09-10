<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectTaskAttachmentResource;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectTaskAttachmentController extends Controller
{
    // nginx on the server caps request bodies at 10m (docs/beispiele/nginx.conf).
    public const MAX_KILOBYTES = 10240;

    public function store(Request $request, ProjectTask $project_task): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES],
        ]);

        $file = $request->file('file');
        $directory = "attachments/{$project_task->organization_id}/{$project_task->id}";
        $path = $file->store($directory, ProjectTaskAttachment::DISK);

        $attachment = $project_task->attachments()->create([
            'organization_id' => $project_task->organization_id,
            'user_id' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
        ]);
        $project_task->recordHistory('attachment_added', ['name' => $attachment->original_name]);
        $project_task->touch();

        return response()->json(['data' => new ProjectTaskAttachmentResource($attachment->load('user'))], 201);
    }

    public function download(ProjectTask $project_task, ProjectTaskAttachment $attachment): StreamedResponse
    {
        if ($attachment->project_task_id !== $project_task->id) {
            abort(404);
        }

        $disk = Storage::disk(ProjectTaskAttachment::DISK);
        if (! $disk->exists($attachment->path)) {
            abort(404, 'The file is no longer on disk.');
        }

        return $disk->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
        ]);
    }

    public function destroy(Request $request, ProjectTask $project_task, ProjectTaskAttachment $attachment): JsonResponse
    {
        if ($attachment->project_task_id !== $project_task->id) {
            abort(404);
        }
        if (! $request->user()->isAdmin() && $attachment->user_id !== $request->user()->id) {
            abort(403, 'Only the uploader or an admin can remove this file.');
        }

        Storage::disk(ProjectTaskAttachment::DISK)->delete($attachment->path);
        $attachment->delete();
        $project_task->recordHistory('attachment_removed', ['name' => $attachment->original_name]);

        return response()->json(null, 204);
    }
}
