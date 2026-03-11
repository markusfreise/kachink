<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
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
            'project' => $this->groupByProject($query),
            'client' => $this->groupByClient($query),
            'user' => $this->groupByUser($query),
            'day' => $this->groupByPeriod($query, 'day'),
            'week' => $this->groupByPeriod($query, 'week'),
            'month' => $this->groupByPeriod($query, 'month'),
            default => $this->groupByProject($query),
        };

        $totals = $this->calculateTotals($request);

        return response()->json([
            'data' => $results,
            'meta' => [
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'group_by' => $groupBy,
                'totals' => $totals,
            ],
        ]);
    }

    public function detailed(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $query = $this->buildReportQuery($request)
            ->with(['project.client', 'task', 'user', 'tags'])
            ->orderBy('started_at', 'desc');

        $entries = $query->paginate($request->integer('per_page', 50));

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
        $query = DB::table('projects')
            ->join('clients', 'projects.client_id', '=', 'clients.id')
            ->leftJoin('time_entries', 'time_entries.project_id', '=', 'projects.id')
            ->select(
                'projects.id',
                'projects.name as project_name',
                'clients.name as client_name',
                'projects.budget_hours',
                'projects.hourly_rate',
                'projects.color',
                DB::raw('COALESCE(SUM(time_entries.duration_seconds), 0) as total_seconds'),
                DB::raw('COALESCE(SUM(CASE WHEN time_entries.is_billable THEN time_entries.duration_seconds ELSE 0 END), 0) as billable_seconds'),
            )
            ->where('projects.is_active', true)
            ->whereNotNull('projects.budget_hours')
            ->groupBy('projects.id', 'projects.name', 'clients.name', 'projects.budget_hours', 'projects.hourly_rate', 'projects.color')
            ->orderBy('projects.name');

        if ($request->has('filter.client_id')) {
            $query->where('projects.client_id', $request->input('filter.client_id'));
        }

        $projects = $query->get()->map(function ($row) {
            $totalHours = round($row->total_seconds / 3600, 2);
            $billableHours = round($row->billable_seconds / 3600, 2);
            $budgetHours = (float) $row->budget_hours;
            $percentage = $budgetHours > 0 ? round(($totalHours / $budgetHours) * 100, 1) : 0;

            return [
                'id' => $row->id,
                'project_name' => $row->project_name,
                'client_name' => $row->client_name,
                'color' => $row->color,
                'budget_hours' => $budgetHours,
                'tracked_hours' => $totalHours,
                'billable_hours' => $billableHours,
                'remaining_hours' => round($budgetHours - $totalHours, 2),
                'budget_used_percentage' => $percentage,
                'hourly_rate' => $row->hourly_rate,
                'revenue' => $row->hourly_rate ? round($billableHours * (float) $row->hourly_rate, 2) : null,
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

        if (!$request->user()->isAdmin()) {
            abort(403);
        }

        $users = DB::table('users')
            ->leftJoin('time_entries', function ($join) use ($request) {
                $join->on('time_entries.user_id', '=', 'users.id')
                    ->where('time_entries.started_at', '>=', $request->input('date_from'))
                    ->where('time_entries.started_at', '<=', $request->input('date_to') . ' 23:59:59');
            })
            ->select(
                'users.id',
                'users.name',
                DB::raw('COALESCE(SUM(time_entries.duration_seconds), 0) as total_seconds'),
                DB::raw('COALESCE(SUM(CASE WHEN time_entries.is_billable THEN time_entries.duration_seconds ELSE 0 END), 0) as billable_seconds'),
                DB::raw('COUNT(DISTINCT DATE(time_entries.started_at)) as days_tracked'),
            )
            ->where('users.is_active', true)
            ->groupBy('users.id', 'users.name')
            ->orderBy('users.name')
            ->get()
            ->map(function ($row) {
                $totalHours = round($row->total_seconds / 3600, 2);
                $billableHours = round($row->billable_seconds / 3600, 2);

                return [
                    'id' => $row->id,
                    'name' => $row->name,
                    'total_hours' => $totalHours,
                    'billable_hours' => $billableHours,
                    'non_billable_hours' => round($totalHours - $billableHours, 2),
                    'billable_percentage' => $totalHours > 0 ? round(($billableHours / $totalHours) * 100, 1) : 0,
                    'days_tracked' => $row->days_tracked,
                    'avg_hours_per_day' => $row->days_tracked > 0 ? round($totalHours / $row->days_tracked, 2) : 0,
                ];
            });

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

        return response()->streamDownload(function () use ($entries) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date', 'User', 'Client', 'Project', 'Task', 'Description',
                'Started', 'Stopped', 'Duration (h)', 'Billable', 'Tags',
            ]);

            foreach ($entries as $entry) {
                fputcsv($handle, [
                    $entry->started_at->format('Y-m-d'),
                    $entry->user->name,
                    $entry->project->client->name ?? '',
                    $entry->project->name,
                    $entry->task->name ?? '',
                    $entry->description ?? '',
                    $entry->started_at->format('H:i'),
                    $entry->stopped_at?->format('H:i') ?? 'running',
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
        $query = TimeEntry::query()
            ->where('is_running', false)
            ->whereNotNull('duration_seconds');

        if ($request->has('date_from')) {
            $query->where('started_at', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->where('started_at', '<=', $request->input('date_to') . ' 23:59:59');
        }

        if ($request->has('filter.project_id')) {
            $query->where('project_id', $request->input('filter.project_id'));
        }

        if ($request->has('filter.client_id')) {
            $query->whereHas('project', fn ($q) => $q->where('client_id', $request->input('filter.client_id')));
        }

        if ($request->has('filter.user_id')) {
            $query->where('user_id', $request->input('filter.user_id'));
        }

        if ($request->has('filter.is_billable')) {
            $query->where('is_billable', $request->boolean('filter.is_billable'));
        }

        if ($request->has('filter.tag_ids')) {
            $tagIds = explode(',', $request->input('filter.tag_ids'));
            $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $tagIds));
        }

        // Non-admins can only see their own data
        if (!$request->user()->isAdmin()) {
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
                'project_name' => $row->project->name,
                'client_name' => $row->project->client->name,
                'color' => $row->project->color,
                'total_hours' => round($row->total_seconds / 3600, 2),
                'billable_hours' => round($row->billable_seconds / 3600, 2),
                'entry_count' => $row->entry_count,
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
                'entry_count' => $row->entry_count,
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
                'user_name' => $row->user->name,
                'total_hours' => round($row->total_seconds / 3600, 2),
                'billable_hours' => round($row->billable_seconds / 3600, 2),
                'entry_count' => $row->entry_count,
            ])
            ->sortByDesc('total_hours')
            ->values()
            ->toArray();
    }

    private function groupByPeriod($query, string $period): array
    {
        $dateFormat = match ($period) {
            'day' => 'YYYY-MM-DD',
            'week' => 'IYYY-"W"IW',
            'month' => 'YYYY-MM',
        };

        return $query->select(
            DB::raw("TO_CHAR(started_at, '{$dateFormat}') as period"),
            DB::raw('SUM(duration_seconds) as total_seconds'),
            DB::raw('SUM(CASE WHEN is_billable THEN duration_seconds ELSE 0 END) as billable_seconds'),
            DB::raw('COUNT(*) as entry_count'),
        )
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($row) => [
                'period' => $row->period,
                'total_hours' => round($row->total_seconds / 3600, 2),
                'billable_hours' => round($row->billable_seconds / 3600, 2),
                'entry_count' => $row->entry_count,
            ])
            ->toArray();
    }

    private function calculateTotals(Request $request): array
    {
        $query = $this->buildReportQuery($request);

        $result = $query->select(
            DB::raw('COALESCE(SUM(duration_seconds), 0) as total_seconds'),
            DB::raw('COALESCE(SUM(CASE WHEN is_billable THEN duration_seconds ELSE 0 END), 0) as billable_seconds'),
            DB::raw('COUNT(*) as entry_count'),
        )->first();

        return [
            'total_hours' => round($result->total_seconds / 3600, 2),
            'billable_hours' => round($result->billable_seconds / 3600, 2),
            'non_billable_hours' => round(($result->total_seconds - $result->billable_seconds) / 3600, 2),
            'entry_count' => $result->entry_count,
        ];
    }
}
