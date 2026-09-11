<?php

namespace App\Models;

use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskStatus extends Model
{
    use BelongsToOrganization, HasUuids;

    public const DEFAULT_NAME = 'Heute';

    protected $fillable = ['organization_id', 'user_id', 'name', 'color', 'position', 'is_locked'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_locked' => 'boolean',
        ];
    }

    /** Visible to $userId: the shared fixed status and the member's own ones. */
    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, string $userId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(fn ($q) => $q->where('is_locked', true)->orWhere('user_id', $userId));
    }

    public function isVisibleTo(?string $userId): bool
    {
        return $this->is_locked || ($userId !== null && $this->user_id === $userId);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'status_id');
    }

    /** Every organization has the fixed "Heute" status. */
    public static function ensureDefaults(Organization $organization): void
    {
        static::withoutGlobalScopes()->firstOrCreate(
            ['organization_id' => $organization->id, 'name' => self::DEFAULT_NAME],
            ['color' => '#D23F3F', 'position' => 0, 'is_locked' => true],
        );
    }
}
