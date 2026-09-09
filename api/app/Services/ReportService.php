<?php

namespace App\Services;

use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Builds a complete report document (totals, groupings, day-by-day detail)
 * for an organization, a client, a project or a team member.
 *
 * All aggregation happens in PHP on the entry set for the period so the
 * result is identical on MySQL, PostgreSQL and SQLite and so that rounding
 * and timezone bucketing are applied consistently.
 */
class ReportService
{
    public const SCOPES = ['organization', 'client', 'project', 'user'];

    public function build(
        string $scope,
        ?Model $subject,
        string $from,
        string $to,
        int $rounding,
        User $viewer,
        string $locale = 'en',
    ): array {
        $tz = config('reports.timezone');
        $appTz = config('app.timezone');

        $fromDate = CarbonImmutable::parse($from, $tz)->startOfDay();
        $toDate = CarbonImmutable::parse($to, $tz)->endOfDay();

        $query = TimeEntry::query()
            ->with(['project.client', 'task', 'user'])
            ->where('is_running', false)
            ->whereNotNull('duration_seconds')
            ->whereBetween('started_at', [
                $fromDate->setTimezone($appTz),
                $toDate->setTimezone($appTz),
            ]);

        match ($scope) {
            'client' => $query->whereHas('project', fn ($q) => $q->where('client_id', $subject->getKey())),
            'project' => $query->where('project_id', $subject->getKey()),
            'user' => $query->where('user_id', $subject->getKey()),
            default => null,
        };

        if (! $viewer->isAdmin()) {
            $query->where('user_id', $viewer->id);
        }

        $rows = $query->orderBy('started_at')->get()
            ->map(fn (TimeEntry $entry) => $this->row($entry, $rounding, $tz));

        return [
            'scope' => $this->scopeInfo($scope, $subject),
            'period' => $this->period($fromDate, $toDate, $locale),
            'rounding_minutes' => $rounding,
            'timezone' => $tz,
            'generated_at' => CarbonImmutable::now($tz)->toIso8601String(),
            'totals' => $this->totals($rows),
            'by_client' => $this->group($rows, 'client_id', 'client_name', 'client_color'),
            'by_project' => $this->group($rows, 'project_id', 'project_name', 'project_color', 'client_name'),
            'by_task' => $this->group($rows, 'task_id', 'task_name', null, 'project_name'),
            'by_user' => $this->group($rows, 'user_id', 'user_name'),
            'days' => $this->days($rows),
        ];
    }

    public static function roundSeconds(?int $seconds, int $roundingMinutes): int
    {
        if (! $seconds) {
            return 0;
        }
        if ($roundingMinutes <= 0) {
            return $seconds;
        }
        $interval = $roundingMinutes * 60;

        return (int) (ceil($seconds / $interval) * $interval);
    }

    private function row(TimeEntry $entry, int $rounding, string $tz): array
    {
        $started = $entry->started_at->copy()->setTimezone($tz);
        $stopped = $entry->stopped_at?->copy()->setTimezone($tz);
        $rounded = self::roundSeconds($entry->duration_seconds, $rounding);
        $rate = $entry->project?->hourly_rate;
        $amount = ($entry->is_billable && $rate) ? round($rounded / 3600 * (float) $rate, 2) : 0.0;

        return [
            'id' => $entry->id,
            'date' => $started->toDateString(),
            'started_at' => $started->toIso8601String(),
            'stopped_at' => $stopped?->toIso8601String(),
            'start_time' => $started->format('H:i'),
            'end_time' => $stopped?->format('H:i'),
            'user_id' => $entry->user_id,
            'user_name' => $entry->user?->name ?? '',
            'client_id' => $entry->project?->client_id,
            'client_name' => $entry->project?->client?->name ?? '',
            'client_color' => $entry->project?->client?->color,
            'project_id' => $entry->project_id,
            'project_name' => $entry->project?->name ?? '',
            'project_color' => $entry->project?->color,
            'task_id' => $entry->task_id,
            'task_name' => $entry->task?->name ?? '',
            'description' => $entry->description ?? '',
            'duration_seconds' => (int) $entry->duration_seconds,
            'rounded_seconds' => $rounded,
            'is_billable' => (bool) $entry->is_billable,
            'hourly_rate' => $rate !== null ? (float) $rate : null,
            'amount' => $amount,
        ];
    }

    private function scopeInfo(string $scope, ?Model $subject): array
    {
        $org = app()->has('current_organization') ? app('current_organization') : null;

        return [
            'type' => $scope,
            'id' => $subject?->getKey(),
            'name' => $subject?->name ?? $org?->name ?? '',
            'color' => $subject?->color ?? null,
            'client_name' => $scope === 'project' ? ($subject?->client?->name ?? null) : null,
            'organization_name' => $org?->name ?? '',
        ];
    }

    private function period(CarbonImmutable $from, CarbonImmutable $to, string $locale): array
    {
        $isFullMonth = $from->isSameMonth($to)
            && $from->day === 1
            && $to->day === $to->daysInMonth;

        $label = $isFullMonth
            ? $from->locale($locale)->isoFormat('MMMM YYYY')
            : $from->locale($locale)->isoFormat('L') . ' - ' . $to->locale($locale)->isoFormat('L');

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'label' => $label,
            'is_full_month' => $isFullMonth,
        ];
    }

    private function totals(Collection $rows): array
    {
        $total = (int) $rows->sum('rounded_seconds');
        $billable = (int) $rows->where('is_billable', true)->sum('rounded_seconds');
        $raw = (int) $rows->sum('duration_seconds');

        return [
            'total_seconds' => $total,
            'billable_seconds' => $billable,
            'non_billable_seconds' => $total - $billable,
            'raw_seconds' => $raw,
            'total_hours' => round($total / 3600, 2),
            'billable_hours' => round($billable / 3600, 2),
            'non_billable_hours' => round(($total - $billable) / 3600, 2),
            'entry_count' => $rows->count(),
            'amount' => round((float) $rows->sum('amount'), 2),
            'days_tracked' => $rows->pluck('date')->unique()->count(),
        ];
    }

    private function group(Collection $rows, string $idKey, string $nameKey, ?string $colorKey = null, ?string $subtitleKey = null): array
    {
        return $rows
            ->groupBy(fn ($r) => $r[$idKey] ?? '')
            ->map(function (Collection $items, $id) use ($nameKey, $colorKey, $subtitleKey) {
                $first = $items->first();
                $total = (int) $items->sum('rounded_seconds');
                $billable = (int) $items->where('is_billable', true)->sum('rounded_seconds');

                return [
                    'id' => $id === '' ? null : $id,
                    'name' => $first[$nameKey] ?: '',
                    'color' => $colorKey ? $first[$colorKey] : null,
                    'subtitle' => $subtitleKey ? $first[$subtitleKey] : null,
                    'total_seconds' => $total,
                    'billable_seconds' => $billable,
                    'total_hours' => round($total / 3600, 2),
                    'billable_hours' => round($billable / 3600, 2),
                    'entry_count' => $items->count(),
                    'amount' => round((float) $items->sum('amount'), 2),
                ];
            })
            ->sortByDesc('total_seconds')
            ->values()
            ->all();
    }

    private function days(Collection $rows): array
    {
        return $rows
            ->groupBy('date')
            ->sortKeys()
            ->map(fn (Collection $items, string $date) => [
                'date' => $date,
                'total_seconds' => (int) $items->sum('rounded_seconds'),
                'billable_seconds' => (int) $items->where('is_billable', true)->sum('rounded_seconds'),
                'entries' => $items->values()->all(),
            ])
            ->values()
            ->all();
    }
}
