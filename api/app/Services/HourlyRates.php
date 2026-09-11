<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the hourly rate that applies to a time entry. The project's
 * rate_mode picks the source; when that source has no rate, the
 * organization default ("Standard") is used.
 *
 *   standard  organization default
 *   user      rate of the member who tracked the entry (organization_user pivot)
 *   client    rate of the project's client
 *   project   the project's own rate
 *
 * Memoizes lookups so a report with thousands of entries costs a handful of
 * queries.
 */
class HourlyRates
{
    public const MODES = ['standard', 'user', 'client', 'project'];

    /** @var array<string, float|null> organization id -> default rate */
    private array $orgRates = [];

    /** @var array<string, array<string, float|null>> organization id -> user id -> rate */
    private array $userRates = [];

    public function organizationRate(string $organizationId): ?float
    {
        if (! array_key_exists($organizationId, $this->orgRates)) {
            $rate = Organization::whereKey($organizationId)->value('hourly_rate');
            $this->orgRates[$organizationId] = $rate !== null ? (float) $rate : null;
        }

        return $this->orgRates[$organizationId];
    }

    public function userRate(string $organizationId, ?string $userId): ?float
    {
        if ($userId === null) {
            return null;
        }
        if (! isset($this->userRates[$organizationId])) {
            $this->userRates[$organizationId] = DB::table('organization_user')
                ->where('organization_id', $organizationId)
                ->pluck('hourly_rate', 'user_id')
                ->map(fn ($r) => $r !== null ? (float) $r : null)
                ->all();
        }

        return $this->userRates[$organizationId][$userId] ?? null;
    }

    /** Rate for one entry of $project tracked by $userId, with the Standard fallback. */
    public function forProject(Project $project, ?string $userId = null): ?float
    {
        $orgId = $project->organization_id;
        $specific = match ($project->rate_mode) {
            'project' => $project->hourly_rate !== null ? (float) $project->hourly_rate : null,
            'client' => $this->clientRate($project),
            'user' => $this->userRate($orgId, $userId),
            default => null,
        };

        return $specific ?? $this->organizationRate($orgId);
    }

    /** Rate a project shows before an entry exists; null when it depends on the member. */
    public function displayRate(Project $project): ?float
    {
        if ($project->rate_mode === 'user') {
            return null;
        }

        return $this->forProject($project);
    }

    /** Where the displayed rate comes from after the fallback: project | client | user | standard | none. */
    public function source(Project $project): string
    {
        $mode = in_array($project->rate_mode, self::MODES, true) ? $project->rate_mode : 'standard';
        $specific = match ($mode) {
            'project' => $project->hourly_rate,
            'client' => $this->clientRate($project),
            'user' => 'depends',
            default => null,
        };
        if ($mode === 'user') {
            return 'user';
        }
        if ($specific !== null) {
            return $mode;
        }

        return $this->organizationRate($project->organization_id) !== null ? 'standard' : 'none';
    }

    /** Billable amount of every finished entry on the project, summed per member so the user mode is exact. */
    public function billableAmount(Project $project): ?float
    {
        $rows = TimeEntry::query()
            ->where('project_id', $project->getKey())
            ->where('is_running', false)
            ->where('is_billable', true)
            ->groupBy('user_id')
            ->select('user_id', DB::raw('COALESCE(SUM(duration_seconds), 0) as seconds'))
            ->get();

        $amount = 0.0;
        $rated = false;
        foreach ($rows as $row) {
            $rate = $this->forProject($project, $row->user_id);
            if ($rate === null) {
                continue;
            }
            $rated = true;
            $amount += ((int) $row->seconds) / 3600 * $rate;
        }

        return $rated ? round($amount, 2) : null;
    }

    private function clientRate(Project $project): ?float
    {
        $rate = $project->relationLoaded('client') ? $project->client?->hourly_rate : $project->client()->value('hourly_rate');

        return $rate !== null ? (float) $rate : null;
    }
}
