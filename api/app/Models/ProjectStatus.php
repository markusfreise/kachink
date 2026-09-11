<?php

namespace App\Models;

use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One column of a project's own pipeline (Projektstatus). */
class ProjectStatus extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['organization_id', 'project_id', 'name', 'color', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'project_status_id');
    }

    /** Finds or creates the status by name inside a project (case-insensitive). */
    public static function forProject(Project $project, string $name, string $color = '#6B7280'): self
    {
        $existing = static::where('project_id', $project->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        return $existing ?? static::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'name' => mb_substr($name, 0, 80),
            'color' => $color,
            'position' => (int) static::where('project_id', $project->id)->max('position') + 1,
        ]);
    }
}
