<?php

namespace App\Models;

use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A task inside a project. Subtasks are tasks with a parent_id; they share
 * every feature of a top-level task.
 */
class ProjectTask extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    public const PRIORITIES = ['immediate', 'urgent', 'soon', 'easy'];

    /** Attributes whose changes are written to the history. */
    public const TRACKED = [
        'title', 'description', 'assignee_id', 'priority', 'status_id', 'estimate_minutes',
        'budget', 'deadline', 'reminder_at', 'parent_id', 'completed_at',
    ];

    protected $fillable = [
        'organization_id',
        'project_id',
        'parent_id',
        'title',
        'description',
        'assignee_id',
        'created_by',
        'priority',
        'status_id',
        'today_moved_on',
        'today_acknowledged_on',
        'estimate_minutes',
        'budget',
        'deadline',
        'reminder_at',
        'reminder_sent_at',
        'completed_at',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'estimate_minutes' => 'integer',
            'budget' => 'decimal:2',
            'deadline' => 'date:Y-m-d',
            'reminder_at' => 'date:Y-m-d',
            'today_moved_on' => 'date:Y-m-d',
            'today_acknowledged_on' => 'date:Y-m-d',
            'reminder_sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProjectTask $task) {
            if (empty($task->created_by) && auth()->check()) {
                $task->created_by = auth()->id();
            }
        });

        static::created(function (ProjectTask $task) {
            $task->recordHistory('created');
        });

        static::updated(function (ProjectTask $task) {
            $changes = [];
            foreach (self::TRACKED as $field) {
                if ($task->wasChanged($field)) {
                    $changes[$field] = [
                        'from' => self::historyValue($task->getOriginal($field)),
                        'to' => self::historyValue($task->getAttribute($field)),
                    ];
                }
            }

            if (isset($changes['completed_at'])) {
                $action = $task->completed_at ? 'completed' : 'reopened';
                unset($changes['completed_at']);
                $task->recordHistory($action);
            }

            if ($changes) {
                $task->recordHistory('updated', $changes);
            }
        });
    }

    private static function historyValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    public function recordHistory(string $action, ?array $changes = null): ProjectTaskHistory
    {
        return $this->histories()->create([
            'organization_id' => $this->organization_id,
            'user_id' => auth()->id(),
            'action' => $action,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------ relations

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'parent_id')->orderBy('position')->orderBy('created_at');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'status_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'project_task_tag');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'project_task_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ProjectTaskComment::class)->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProjectTaskAttachment::class)->orderBy('created_at');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ProjectTaskHistory::class)->orderByDesc('created_at');
    }

    // ------------------------------------------------------------ due today

    public static function today(): string
    {
        return Carbon::now(config('reports.timezone'))->toDateString();
    }

    /** Deadline is today, still open and not confirmed for today: shows the blinking marker. */
    public function isDueTodayAlert(): bool
    {
        if ($this->completed_at !== null || $this->deadline === null) {
            return false;
        }
        $today = self::today();

        return $this->deadline->toDateString() === $today && $this->today_acknowledged_on?->toDateString() !== $today;
    }

    /**
     * Moves every open task of the organization whose deadline is today into
     * the "Heute" status, once per day. Idempotent and cheap, so it runs when
     * lists are loaded.
     */
    public static function moveDueTodayIntoHeute(Organization $organization): int
    {
        $heute = TaskStatus::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('is_locked', true)
            ->first();
        if (! $heute) {
            return 0;
        }
        $today = self::today();

        $due = static::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereNull('completed_at')
            ->whereDate('deadline', $today)
            ->where(fn ($q) => $q->whereNull('today_moved_on')->orWhereDate('today_moved_on', '<', $today))
            ->where(fn ($q) => $q->whereNull('status_id')->orWhere('status_id', '!=', $heute->id))
            ->get();

        foreach ($due as $task) {
            $task->forceFill(['status_id' => $heute->id, 'today_moved_on' => $today])->save();
        }
        // Tasks already in Heute only need the marker date.
        static::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereNull('completed_at')
            ->whereDate('deadline', $today)
            ->where('status_id', $heute->id)
            ->where(fn ($q) => $q->whereNull('today_moved_on')->orWhereDate('today_moved_on', '<', $today))
            ->update(['today_moved_on' => $today]);

        return $due->count();
    }

    // ------------------------------------------------------------ helpers

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    public function scopeDone(Builder $query): Builder
    {
        return $query->whereNotNull('completed_at');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /** Parent chain from the root down to the direct parent. */
    public function ancestors(): array
    {
        $chain = [];
        $current = $this->parent;
        $guard = 0;
        while ($current && $guard++ < 50) {
            array_unshift($chain, $current);
            $current = $current->parent;
        }

        return $chain;
    }

    /** True when $candidate is this task or one of its descendants (cycle check for re-parenting). */
    public function isSelfOrDescendant(string $candidateId): bool
    {
        if ($candidateId === $this->getKey()) {
            return true;
        }

        $frontier = [$this->getKey()];
        $guard = 0;
        while ($frontier && $guard++ < 50) {
            $childIds = self::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            if (in_array($candidateId, $childIds, true)) {
                return true;
            }
            $frontier = $childIds;
        }

        return false;
    }
}
