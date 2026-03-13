<?php

namespace App\Traits;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function ($query) {
            if (app()->has('current_organization')) {
                $query->where(
                    $query->getModel()->getTable() . '.organization_id',
                    app('current_organization')->id
                );
            }
        });

        static::creating(function ($model) {
            if (empty($model->organization_id) && app()->has('current_organization')) {
                $model->organization_id = app('current_organization')->id;
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
