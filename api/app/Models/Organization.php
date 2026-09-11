<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'hourly_rate',
        'is_active',
    ];

    protected $hidden = ['asana_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'hourly_rate' => 'float',
            'asana_token' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $org) {
            if (empty($org->slug)) {
                $org->slug = Str::slug($org->name);
            }
        });

        static::created(fn (Organization $org) => TaskStatus::ensureDefaults($org));
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role', 'hourly_rate')
            ->withTimestamps();
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }
}
