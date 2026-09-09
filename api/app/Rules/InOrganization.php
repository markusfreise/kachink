<?php

namespace App\Rules;

use Illuminate\Validation\Rules\Exists;

/**
 * Builds `exists` rules that are restricted to the current organization so
 * that ids of another tenant are rejected with a validation error instead of
 * silently attaching foreign rows.
 */
final class InOrganization
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        $rule = new Exists($table, $column);

        if (app()->has('current_organization')) {
            $rule->where('organization_id', app('current_organization')->getKey());
        }

        return $rule;
    }
}
