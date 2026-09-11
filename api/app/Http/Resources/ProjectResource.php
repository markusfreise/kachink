<?php

namespace App\Http\Resources;

use App\Services\HourlyRates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Populated by withSum()/loadSum() in ProjectController when a time summary is requested.
        $hasSummary = array_key_exists('tracked_seconds', $this->resource->getAttributes());
        $trackedSeconds = (int) ($this->tracked_seconds ?? 0);
        $billableSeconds = (int) ($this->billable_seconds ?? 0);
        $rates = app(HourlyRates::class);

        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'color' => $this->color,
            'asana_project_gid' => $this->asana_project_gid,
            'budget_hours' => $this->budget_hours,
            'hourly_rate' => $this->hourly_rate,
            'rate_mode' => $this->rate_mode ?? 'standard',
            'effective_hourly_rate' => $rates->displayRate($this->resource),
            'rate_source' => $rates->source($this->resource),
            'billable_amount' => $this->when(array_key_exists('billable_amount', $this->resource->getAttributes()), fn () => $this->billable_amount),
            'is_billable' => $this->is_billable,
            'is_active' => $this->is_active,
            'archived_at' => $this->archived_at,
            'client' => new ClientResource($this->whenLoaded('client')),
            'watchers' => UserResource::collection($this->whenLoaded('watchers')),
            'is_watching' => $this->when($this->relationLoaded('watchers'), fn () => $this->watchers->contains('id', $request->user()?->id)),
            'tracked_seconds' => $this->when($hasSummary, $trackedSeconds),
            'billable_seconds' => $this->when($hasSummary, $billableSeconds),
            'total_tracked_hours' => $this->when($hasSummary, fn () => round($trackedSeconds / 3600, 2)),
            'billable_hours' => $this->when($hasSummary, fn () => round($billableSeconds / 3600, 2)),
            'budget_used_percentage' => $this->when(
                $hasSummary && $this->budget_hours,
                fn () => round(($trackedSeconds / 3600) / (float) $this->budget_hours * 100, 1)
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
