<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;

class ResolveOrganization
{
    public function handle(Request $request, Closure $next): mixed
    {
        $orgId = $request->header('X-Organization-Id');

        if (!$orgId) {
            return response()->json(['message' => 'X-Organization-Id header is required.'], 400);
        }

        $org = Organization::find($orgId);

        if (!$org) {
            return response()->json(['message' => 'Organization not found.'], 404);
        }

        $isMember = $request->user()
            ->organizations()
            ->where('organizations.id', $org->id)
            ->exists();

        if (!$isMember) {
            return response()->json(['message' => 'You are not a member of this organization.'], 403);
        }

        app()->instance('current_organization', $org);

        return $next($request);
    }
}
