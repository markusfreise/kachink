<?php

namespace App\Http\Resources;

use App\Services\HourlyRates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();
        $calculated = $this->calculatedBudget();

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'parent_id' => $this->parent_id,
            'title' => $this->title,
            'description' => $this->description,
            'assignee_id' => $this->assignee_id,
            'created_by' => $this->created_by,
            'priority' => $this->priority,
            'status_id' => $this->status_id,
            'status' => new TaskStatusResource($this->whenLoaded('status')),
            'project_status_id' => $this->project_status_id,
            'project_status' => new TaskStatusResource($this->whenLoaded('projectStatus')),
            'estimate_minutes' => $this->estimate_minutes,
            'budget' => $this->budget !== null ? (float) $this->budget : null,
            'calculated_budget' => $calculated['amount'],
            'calculated_rate' => $calculated['rate'],
            'deadline' => $this->deadline?->format('Y-m-d'),
            'reminder_at' => $this->reminder_at?->format('Y-m-d'),
            'reminder_sent_at' => $this->reminder_sent_at,
            'completed_at' => $this->completed_at,
            'is_completed' => $this->completed_at !== null,
            'is_due_today_alert' => $this->resource->isDueTodayAlert(),
            'is_overdue' => $this->completed_at === null && $this->deadline !== null && $this->deadline->isPast() && ! $this->deadline->isToday(),
            'position' => $this->position,
            'asana_task_gid' => $this->asana_task_gid,
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'project' => new ProjectResource($this->whenLoaded('project')),
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'id' => $this->parent->id,
                'title' => $this->parent->title,
                'is_completed' => $this->parent->completed_at !== null,
            ] : null),
            'ancestors' => $this->when(isset($this->resource->ancestorChain), fn () => array_map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
            ], $this->resource->ancestorChain)),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'children' => ProjectTaskResource::collection($this->whenLoaded('children')),
            'comments' => ProjectTaskCommentResource::collection($this->whenLoaded('comments')),
            'attachments' => ProjectTaskAttachmentResource::collection($this->whenLoaded('attachments')),
            'history' => ProjectTaskHistoryResource::collection($this->whenLoaded('histories')),
            'children_count' => $this->when(array_key_exists('children_count', $attributes), fn () => (int) $this->children_count),
            'open_children_count' => $this->when(array_key_exists('open_children_count', $attributes), fn () => (int) $this->open_children_count),
            'comments_count' => $this->when(array_key_exists('comments_count', $attributes), fn () => (int) $this->comments_count),
            'attachments_count' => $this->when(array_key_exists('attachments_count', $attributes), fn () => (int) $this->attachments_count),
            'tracked_seconds' => $this->when(array_key_exists('tracked_seconds', $attributes), fn () => (int) ($this->tracked_seconds ?? 0)),
            'earliest_child_deadline' => $this->when(array_key_exists('earliest_child_deadline', $attributes), fn () => $this->earliest_child_deadline ? substr((string) $this->earliest_child_deadline, 0, 10) : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Budget derived from the estimate and the hourly rate that applies to the
     * assignee on this project, for tasks without a fixed budget.
     *
     * @return array{amount: float|null, rate: float|null}
     */
    private function calculatedBudget(): array
    {
        if ($this->budget !== null || ! $this->estimate_minutes) {
            return ['amount' => null, 'rate' => null];
        }
        $project = $this->resource->relationLoaded('project') ? $this->project : $this->resource->project()->first();
        if (! $project) {
            return ['amount' => null, 'rate' => null];
        }
        $rate = app(HourlyRates::class)->forProject($project, $this->assignee_id);
        if ($rate === null) {
            return ['amount' => null, 'rate' => null];
        }

        return ['amount' => round($this->estimate_minutes / 60 * $rate, 2), 'rate' => $rate];
    }
}
