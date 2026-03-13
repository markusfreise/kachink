<?php

namespace App\Models;

use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TimeEntry extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'project_id',
        'task_id',
        'description',
        'started_at',
        'stopped_at',
        'duration_seconds',
        'is_billable',
        'is_running',
        'source',
        'harvest_id',
        'asana_task_gid',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'stopped_at' => 'datetime',
            'duration_seconds' => 'integer',
            'is_billable' => 'boolean',
            'is_running' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'time_entry_tag');
    }

    public function getDurationForHumansAttribute(): string
    {
        $seconds = $this->duration_seconds ?? $this->started_at->diffInSeconds(now());
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
    }

    public function stop(): void
    {
        $this->stopped_at = now();
        $this->duration_seconds = $this->started_at->diffInSeconds($this->stopped_at);
        $this->is_running = false;
        $this->save();
    }
}
