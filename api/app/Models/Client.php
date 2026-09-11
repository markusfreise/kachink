<?php

namespace App\Models;

use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class Client extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'color',
        'hourly_rate',
        'is_active',
        'notes',
        'harvest_id',
        'asana_project_gid',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'hourly_rate' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            if (empty($client->slug)) {
                $client->slug = Str::slug($client->name);
            }
        });
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function timeEntries(): HasManyThrough
    {
        return $this->hasManyThrough(TimeEntry::class, Project::class);
    }
}
