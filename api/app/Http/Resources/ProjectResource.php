<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'color' => $this->color,
            'asana_project_gid' => $this->asana_project_gid,
            'budget_hours' => $this->budget_hours,
            'hourly_rate' => $this->hourly_rate,
            'is_billable' => $this->is_billable,
            'is_active' => $this->is_active,
            'archived_at' => $this->archived_at,
            'client' => new ClientResource($this->whenLoaded('client')),
            'total_tracked_hours' => $this->when(
                $this->relationLoaded('timeEntries'),
                fn () => round($this->timeEntries->sum('duration_seconds') / 3600, 2)
            ),
            'budget_used_percentage' => $this->when(
                $this->budget_hours && $this->relationLoaded('timeEntries'),
                fn () => round(($this->timeEntries->sum('duration_seconds') / 3600) / (float) $this->budget_hours * 100, 1)
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
