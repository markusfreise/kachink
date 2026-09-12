<?php

namespace App\Models;

use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'asana_task_gid',
        'harvest_id',
        'budget_hours',
        'budget_amount',
        'billed_amount',
        'billing_mode',
        'hourly_rate',
        'rate_mode',
        'is_billable',
        'is_active',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'budget_hours' => 'float',
            'budget_amount' => 'float',
            'billed_amount' => 'float',
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
            if (empty($project->billing_mode)) {
                $project->billing_mode = $project->is_billable === false ? 'none' : 'hourly';
            }
            // A project created with its own rate uses it; otherwise the organization default.
            if (empty($project->rate_mode)) {
                $project->rate_mode = $project->hourly_rate !== null ? 'project' : 'standard';
            }
        });

        // The billable flag follows the billing mode.
        static::saving(function (Project $project) {
            if ($project->billing_mode) {
                $project->is_billable = $project->billing_mode !== 'none';
            }
        });
    }

    public const BILLING_MODES = ['none', 'fixed', 'hourly'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function projectTasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(ProjectStatus::class)->orderBy('position');
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_watchers')->withPivot('created_at');
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
        if (! $this->budget_hours) {
            return null;
        }

        return round(($this->totalTrackedHours() / (float) $this->budget_hours) * 100, 1);
    }
}
