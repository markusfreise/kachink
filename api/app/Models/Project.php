<?php

namespace App\Models;

use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Project extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'client_id',
        'name',
        'slug',
        'color',
        'asana_project_gid',
        'harvest_id',
        'budget_hours',
        'hourly_rate',
        'is_billable',
        'is_active',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'budget_hours' => 'float',
            'hourly_rate' => 'float',
            'is_billable' => 'boolean',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            if (empty($project->slug)) {
                $project->slug = Str::slug($project->name);
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function totalTrackedHours(): float
    {
        return round($this->timeEntries()->sum('duration_seconds') / 3600, 2);
    }

    public function budgetUsedPercentage(): ?float
    {
        if (!$this->budget_hours) {
            return null;
        }

        return round(($this->totalTrackedHours() / (float) $this->budget_hours) * 100, 1);
    }
}
