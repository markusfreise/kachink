<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    // ------------------------------------------------------------------
    // Scoped reports (organization / client / project / team member)
    // ------------------------------------------------------------------

    public function organization(Request $request): Response
    {
        return $this->scoped($request, 'organization', null);
    }

    public function client(Request $request, Client $client): Response
    {
        return $this->scoped($request, 'client', $client);
    }

    public function project(Request $request, Project $project): Response
    {
        $project->loadMissing('client');

        return $this->scoped($request, 'project', $project);
    }

    public function user(Request $request, User $user): Response
    {
        if (! $request->user()->isAdmin() && $user->id !== $request->user()->id) {
            abort(403);
        }

        $isMember = app('current_organization')->users()->where('users.id', $user->id)->exists();
        if (! $isMember) {
            abort(404);
        }

        return $this->scoped($request, 'user', $user);
    }

    private function scoped(Request $request, string $scope, ?Model $subject): Response
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'rounding' => ['sometimes', 'integer', 'in:' . implode(',', config('reports.rounding_intervals'))],
            'format' => ['sometimes', 'in:json,pdf,csv'],
            'locale' => ['sometimes', 'in:de,en'],
        ]);

        $rounding = (int) ($validated['rounding'] ?? config('reports.default_rounding'));
        $locale = $validated['locale'] ?? 'en';
        $format = $validated['format'] ?? 'json';

        $report = $this->reports->build(
            $scope,
            $subject,
            $validated['date_from'],
            $validated['date_to'],
            $rounding,
            $request->user(),
            $locale,
        );

        return match ($format) {
            'pdf' => $this->pdf($report, $locale),
            'csv' => $this->csv($report),
            default => response()->json(['data' => $report]),
        };
    }

    private function pdf(array $report, string $locale): Response
    {
        app()->setLocale($locale);

        $pdf = Pdf::loadView('reports.pdf', ['report' => $report, 'locale' => $locale])
            ->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', false)
            ->setOption('defaultFont', 'Helvetica');

        return $pdf->download($this->filename($report, 'pdf'));
    }

    private function csv(array $report): StreamedResponse
    {
        $rows = collect($report['days'])->flatMap(fn ($day) => $day['entries']);

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Date', 'Start', 'End', 'User', 'Client', 'Project', 'Task', 'Description',
                'Duration (h)', 'Rounded (h)', 'Billable', 'Amount',
            ]);
            foreach ($rows as $r) {
                fputcsv($handle, [
                    $r['date'],
                    $r['start_time'],
                    $r['end_time'] ?? '',
                    $r['user_name'],
                    $r['client_name'],
                    $r['project_name'],
                    $r['task_name'],
                    $r['description'],
                    round($r['duration_seconds'] / 3600, 2),
                    round($r['rounded_seconds'] / 3600, 2),
                    $r['is_billable'] ? 'yes' : 'no',
                    number_format($r['amount'], 2, '.', ''),
                ]);
            }
            fclose($handle);
        }, $this->filename($report, 'csv'), ['Content-Type' => 'text/csv']);
    }

    private function filename(array $report, string $ext): string
    {
        $period = $report['period']['is_full_month']
            ? substr($report['period']['from'], 0, 7)
            : $report['period']['from'] . '_' . $report['period']['to'];

        $parts = array_filter([
            Str::slug($report['scope']['organization_name'] ?: 'report'),
            $report['scope']['type'] !== 'organization' ? Str::slug($report['scope']['name']) : null,
            $period,
        ]);

        return implode('-', $parts) . '.' . $ext;
    }

    // ------------------------------------------------------------------
    // Overview report (summary groupings) used by the reports page
    // ------------------------------------------------------------------

    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'group_by' => ['sometimes', 'in:project,client,user,tag,day,week,month'],
        ]);

        $query = $this->buildReportQuery($request);
        $groupBy = $request->input('group_by', 'project');

        $results = match ($groupBy) {
            'client' => $this->groupByClient($query),
            'user' => $this->groupByUser($query),
            'day', 'week', 'month' => $this->groupByPeriod($query, $groupBy),
            default => $this->groupByProject($query),
        };

        return response()->json([
            'data' => $results,
            'meta' => [
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'group_by' => $groupBy,
                'totals' => $this->calculateTotals($request),
            ],
        ]);
    }

    public function detailed(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $entries = $this->buildReportQuery($request)
            ->with(['project.client', 'task', 'user', 'tags'])
            ->orderBy('started_at', 'desc')
            ->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => $entries->items(),
            'meta' => [
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
                'totals' => $this->calculateTotals($request),
            ],
        ]);
    }

    public function budget(Request $request): JsonResponse
    {
        $query = Project::query()
            ->with('client')
            ->withSum(['timeEntries as total_seconds' => fn ($q) => $q->where('is_running', false)], 'duration_seconds')
            ->withSum(['timeEntries as billable_seconds' => fn ($q) => $q->where('is_running', false)->where('is_billable', true)], 'duration_seconds')
            ->where('is_active', true)
            ->whereNotNull('budget_hours')
            ->orderBy('name');

        if ($request->filled('filter.client_id')) {
            $query->where('client_id', $request->input('filter.client_id'));
        }

        $projects = $query->get()->map(function (Project $project) {
            $totalHours = round(((int) $project->total_seconds) / 3600, 2);
            $billableHours = round(((int) $project->billable_seconds) / 3600, 2);
            $budgetHours = (float) $project->budget_hours;
            $percentage = $budgetHours > 0 ? round(($totalHours / $budgetHours) * 100, 1) : 0;

            return [
                'id' => $project->id,
                'project_name' => $project->name,
                'client_name' => $project->client?->name,
                'color' => $project->color,
                'budget_hours' => $budgetHours,
                'tracked_hours' => $totalHours,
                'billable_hours' => $billableHours,
                'remaining_hours' => round($budgetHours - $totalHours, 2),
                'budget_used_percentage' => $percentage,
                'hourly_rate' => $project->hourly_rate,
                'revenue' => $project->hourly_rate ? round($billableHours * (float) $project->hourly_rate, 2) : null,
                'status' => $percentage >= 100 ? 'over_budget' : ($percentage >= 80 ? 'at_risk' : 'on_track'),
            ];
        });

        return response()->json(['data' => $projects]);
    }

    public function utilization(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        if (! $request->user()->isAdmin()) {
            abort(403);
        }

        $members = app('current_organization')->users()
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->get();

        $stats = $this->buildReportQuery($request)
            ->select(
                'user_id',
                DB::raw('COALESCE(SUM(duration_seconds), 0) as total_seconds'),
                DB::raw('COALESCE(SUM(CASE WHEN is_billable THEN duration_seconds ELSE 0 END), 0) as billable_seconds'),
                DB::raw('COUNT(DISTINCT DATE(started_at)) as days_tracked'),
            )
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $users = $members->map(function (User $user) use ($stats) {
            $row = $stats->get($user->id);
            $totalHours = round(((int) ($row->total_seconds ?? 0)) / 3600, 2);
            $billableHours = round(((int) ($row->billable_seconds ?? 0)) / 3600, 2);
            $days = (int) ($row->days_tracked ?? 0);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'total_hours' => $totalHours,
                'billable_hours' => $billableHours,
                'non_billable_hours' => round($totalHours - $billableHours, 2),
                'billable_percentage' => $totalHours > 0 ? round(($billableHours / $totalHours) * 100, 1) : 0,
                'days_tracked' => $days,
                'avg_hours_per_day' => $days > 0 ? round($totalHours / $days, 2) : 0,
            ];
        })->values();

        return response()->json([
            'data' => $users,
            'meta' => [
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'format' => ['sometimes', 'in:csv'],
        ]);

        $entries = $this->buildReportQuery($request)
            ->with(['project.client', 'task', 'user', 'tags'])
            ->orderBy('started_at', 'desc')
            ->get();

        $tz = config('reports.timezone');

        return response()->streamDownload(function () use ($entries, $tz) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date', 'User', 'Client', 'Project', 'Task', 'Description',
                'Started', 'Stopped', 'Duration (h)', 'Billable', 'Tags',
            ]);

            foreach ($entries as $entry) {
                fputcsv($handle, [
                    $entry->started_at->setTimezone($tz)->format('Y-m-d'),
                    $entry->user->name,
                    $entry->project->client->name ?? '',
                    $entry->project->name,
                    $entry->task->name ?? '',
                    $entry->description ?? '',
                    $entry->started_at->setTimezone($tz)->format('H:i'),
                    $entry->stopped_at?->setTimezone($tz)->format('H:i') ?? 'running',
                    $entry->duration_seconds ? round($entry->duration_seconds / 3600, 2) : '',
                    $entry->is_billable ? 'Yes' : 'No',
                    $entry->tags->pluck('name')->implode(', '),
                ]);
            }

            fclose($handle);
        }, 'timetracker-export-' . date('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function buildReportQuery(Request $request)
    {
        $tz = config('reports.timezone');
        $appTz = config('app.timezone');

        $query = TimeEntry::query()
            ->where('is_running', false)
            ->whereNotNull('duration_seconds');

        if ($request->filled('date_from')) {
            $query->where('started_at', '>=', CarbonImmutable::parse($request->input('date_from'), $tz)->startOfDay()->setTimezone($appTz));
        }

        if ($request->filled('date_to')) {
            $query->where('started_at', '<=', CarbonImmutable::parse($request->input('date_to'), $tz)->endOfDay()->setTimezone($appTz));
        }

        if ($request->filled('filter.project_id')) {
            $query->where('project_id', $request->input('filter.project_id'));
        }

        if ($request->filled('filter.client_id')) {
            $query->whereHas('project', fn ($q) => $q->where('client_id', $request->input('filter.client_id')));
        }

        if ($request->filled('filter.user_id')) {
            $query->where('user_id', $request->input('filter.user_id'));
        }

        if ($request->has('filter.is_billable')) {
            $query->where('is_billable', $request->boolean('filter.is_billable'));
        }

        if ($request->filled('filter.tag_ids')) {
            $tagIds = explode(',', $request->input('filter.tag_ids'));
            $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $tagIds));
        }

        // Non-admins can only see their own data
        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->id);
        }

        return $query;
    }

    private function groupByProject($query): array
    {
        return $query->select(
            'project_id',
            DB::raw('SUM(duration_seconds) as total_seconds'),
            DB::raw('SUM(CASE WHEN is_billable THEN duration_seconds ELSE 0 END) as billable_seconds'),
            DB::raw('COUNT(*) as entry_count'),
        )
            ->groupBy('project_id')
            ->with('project.client')
            ->get()
            ->map(fn ($row) => [
                'project_id' => $row->project_id,
                'project_name' => $row->project?->name,
                'client_name' => $row->project?->client?->name,
                'color' => $row->project?->color,
                'total_hours' => round($row->total_seconds / 3600, 2),
                'billable_hours' => round($row->billable_seconds / 3600, 2),
                'entry_count' => (int) $row->entry_count,
            ])
            ->sortByDesc('total_hours')
            ->values()
            ->toArray();
    }

    private function groupByClient($query): array
    {
        return $query->join('projects', 'time_entries.project_id', '=', 'projects.id')
            ->join('clients', 'projects.client_id', '=', 'clients.id')
            ->select(
                'clients.id as client_id',
                'clients.name as client_name',
                'clients.color',
                DB::raw('SUM(time_entries.duration_seconds) as total_seconds'),
                DB::raw('SUM(CASE WHEN time_entries.is_billable THEN time_entries.duration_seconds ELSE 0 END) as billable_seconds'),
                DB::raw('COUNT(*) as entry_count'),
            )
            ->groupBy('clients.id', 'clients.name', 'clients.color')
            ->orderByDesc('total_seconds')
            ->get()
            ->map(fn ($row) => [
                'client_id' => $row->client_id,
                'client_name' => $row->client_name,
                'color' => $row->color,
                'total_hours' => round($row->total_seconds / 3600, 2),
                'billable_hours' => round($row->billable_seconds / 3600, 2),
                'entry_count' => (int) $row->entry_count,
            ])
            ->toArray();
    }

    private function groupByUser($query): array
    {
        return $query->select(
            'user_id',
            DB::raw('SUM(duration_seconds) as total_seconds'),
            DB::raw('SUM(CASE WHEN is_billable THEN duration_seconds ELSE 0 END) as billable_seconds'),
            DB::raw('COUNT(*) as entry_count'),
        )
            ->groupBy('user_id')
            ->with('user')
            ->get()
            ->map(fn ($row) => [
                'user_id' => $row->user_id,
                'user_name' => $row->user?->name,
                'total_hours' => round($row->total_seconds / 3600, 2),
                'billable_hours' => round($row->billable_seconds / 3600, 2),
                'entry_count' => (int) $row->entry_count,
            ])
            ->sortByDesc('total_hours')
            ->values()
            ->toArray();
    }

    /**
     * Period grouping is done in PHP so it is identical on MySQL, PostgreSQL
     * and SQLite and respects the report timezone.
     */
    private function groupByPeriod($query, string $period): array
    {
        $tz = config('reports.timezone');

        return $query->get(['started_at', 'duration_seconds', 'is_billable'])
            ->groupBy(function (TimeEntry $entry) use ($period, $tz) {
                $date = $entry->started_at->copy()->setTimezone($tz);

                return match ($period) {
                    'week' => $date->isoFormat('GGGG-[W]WW'),
                    'month' => $date->format('Y-m'),
                    default => $date->format('Y-m-d'),
                };
            })
            ->sortKeys()
            ->map(fn ($items, $key) => [
                'period' => $key,
                'total_hours' => round($items->sum('duration_seconds') / 3600, 2),
                'billable_hours' => round($items->where('is_billable', true)->sum('duration_seconds') / 3600, 2),
                'entry_count' => $items->count(),
            ])
            ->values()
            ->toArray();
    }

    private function calculateTotals(Request $request): array
    {
        $result = $this->buildReportQuery($request)->select(
            DB::raw('COALESCE(SUM(duration_seconds), 0) as total_seconds'),
            DB::raw('COALESCE(SUM(CASE WHEN is_billable THEN duration_seconds ELSE 0 END), 0) as billable_seconds'),
            DB::raw('COUNT(*) as entry_count'),
        )->first();

        return [
            'total_hours' => round($result->total_seconds / 3600, 2),
            'billable_hours' => round($result->billable_seconds / 3600, 2),
            'non_billable_hours' => round(($result->total_seconds - $result->billable_seconds) / 3600, 2),
            'entry_count' => (int) $result->entry_count,
        ];
    }
}
