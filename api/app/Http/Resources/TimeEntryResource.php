<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimeEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'project_id' => $this->project_id,
            'task_id' => $this->task_id,
            'description' => $this->description,
            'started_at' => $this->started_at,
            'stopped_at' => $this->stopped_at,
            'duration_seconds' => $this->duration_seconds,
            'duration_human' => $this->duration_for_humans,
            'is_billable' => $this->is_billable,
            'is_running' => $this->is_running,
            'source' => $this->source,
            'user' => new UserResource($this->whenLoaded('user')),
            'project' => new ProjectResource($this->whenLoaded('project')),
            'task' => new TaskResource($this->whenLoaded('task')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
