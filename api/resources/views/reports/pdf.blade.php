@php
    use Carbon\CarbonImmutable;

    $fmtH = fn (int $seconds) => sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    $fmtDec = fn (int $seconds) => number_format($seconds / 3600, 2, $locale === 'de' ? ',' : '.', $locale === 'de' ? '.' : ',');
    $fmtMoney = fn (float $amount) => $locale === 'de'
        ? number_format($amount, 2, ',', '.') . ' EUR'
        : 'EUR ' . number_format($amount, 2, '.', ',');
    $fmtDate = fn (string $date) => CarbonImmutable::parse($date)->locale($locale)->isoFormat('dd, L');
    $pct = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100) : 0;

    $scope = $report['scope'];
    $totals = $report['totals'];
    $title = __('reports.title_' . $scope['type']);
    $subject = $scope['type'] === 'organization' ? $scope['organization_name'] : $scope['name'];
    $showAmount = $totals['amount'] > 0;
    $showUsers = count($report['by_user']) > 1 || $scope['type'] === 'user';
    $showClients = in_array($scope['type'], ['organization', 'user']) && count($report['by_client']) > 1;
    $showProjects = $scope['type'] !== 'project';
    $showTasks = count(array_filter($report['by_task'], fn ($t) => $t['name'] !== '')) > 0;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $title }} {{ $subject }} {{ $report['period']['label'] }}</title>
<style>
    @page { margin: 18mm 16mm 20mm 16mm; }
    * { box-sizing: border-box; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 9.5pt; color: #1a1a1a; margin: 0; line-height: 1.35; }
    h1 { font-size: 20pt; margin: 0 0 2pt; font-weight: bold; letter-spacing: -0.01em; }
    h2 { font-size: 11pt; margin: 18pt 0 6pt; text-transform: uppercase; letter-spacing: 0.06em; color: #444; }
    .org { font-size: 8pt; color: #777; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8pt; }
    .subtitle { font-size: 11pt; color: #555; margin-bottom: 12pt; }
    .subtitle strong { color: #1a1a1a; }
    .rule { border-top: 1.5pt solid #1a1a1a; margin: 6pt 0 10pt; }
    table.stats { width: 100%; border-collapse: collapse; margin-bottom: 4pt; }
    table.stats td { padding: 6pt 10pt 6pt 0; vertical-align: top; width: 20%; }
    table.stats .label { display: block; font-size: 7pt; color: #777; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 2pt; }
    table.stats .value { display: block; font-size: 14pt; font-weight: bold; }
    table.stats .value.small { font-size: 10pt; font-weight: normal; color: #444; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.05em; color: #666; padding: 4pt 6pt; border-bottom: 1pt solid #bbb; background: #f4f4f4; }
    table.data td { padding: 4pt 6pt; border-bottom: 0.5pt solid #e2e2e2; vertical-align: top; }
    table.data tr.total td { font-weight: bold; border-top: 1pt solid #1a1a1a; border-bottom: none; background: #fafafa; }
    table.data tr.day td { background: #eeeeee; font-weight: bold; padding: 5pt 6pt; border-top: 0.5pt solid #ccc; }
    td.num, th.num { text-align: right; white-space: nowrap; }
    td.time { white-space: nowrap; color: #555; }
    td.muted { color: #777; }
    .dot { display: inline-block; width: 6pt; height: 6pt; border-radius: 3pt; margin-right: 4pt; vertical-align: middle; }
    .bar { display: inline-block; height: 5pt; background: #1a1a1a; vertical-align: middle; }
    .bar-wrap { display: inline-block; width: 60pt; height: 5pt; background: #e5e5e5; vertical-align: middle; margin-right: 4pt; }
    .desc { color: #444; }
    .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #999; }
    .footer .left { float: left; }
    .footer .right { float: right; }
    .empty { padding: 30pt 0; color: #777; text-align: center; }
    .pagebreak { page-break-before: always; }
</style>
</head>
<body>

<div class="footer">
    <span class="left">{{ $scope['organization_name'] }} &middot; {{ $title }} {{ $subject }} &middot; {{ $report['period']['label'] }}</span>
    <span class="right">{{ __('reports.generated') }} {{ CarbonImmutable::parse($report['generated_at'])->locale($locale)->isoFormat('L LT') }}</span>
</div>

<div class="org">{{ $scope['organization_name'] }}</div>
<h1>{{ $subject }}</h1>
<div class="subtitle">
    {{ $title }}
    @if($scope['type'] === 'project' && $scope['client_name']) &middot; {{ $scope['client_name'] }} @endif
    &middot; <strong>{{ $report['period']['label'] }}</strong>
</div>
<div class="rule"></div>

<table class="stats">
    <tr>
        <td><span class="label">{{ __('reports.total') }}</span><span class="value">{{ $fmtH($totals['total_seconds']) }} {{ __('reports.hours_short') }}</span></td>
        <td><span class="label">{{ __('reports.billable') }}</span><span class="value">{{ $fmtH($totals['billable_seconds']) }} {{ __('reports.hours_short') }}</span></td>
        <td><span class="label">{{ __('reports.non_billable') }}</span><span class="value">{{ $fmtH($totals['non_billable_seconds']) }} {{ __('reports.hours_short') }}</span></td>
        <td><span class="label">{{ __('reports.entries') }}</span><span class="value">{{ $totals['entry_count'] }}</span></td>
        @if($showAmount)
        <td><span class="label">{{ __('reports.amount') }}</span><span class="value">{{ $fmtMoney($totals['amount']) }}</span></td>
        @else
        <td><span class="label">{{ __('reports.period') }}</span><span class="value small">{{ $fmtDate($report['period']['from']) }}<br>{{ $fmtDate($report['period']['to']) }}</span></td>
        @endif
    </tr>
</table>
@if($report['rounding_minutes'] > 0)
<div style="font-size:7.5pt;color:#777;margin-bottom:6pt;">{{ __('reports.rounding_note', ['minutes' => $report['rounding_minutes']]) }}</div>
@endif

@if($totals['entry_count'] === 0)
    <div class="empty">{{ __('reports.no_entries') }}</div>
@else

    @if($showClients)
    <h2>{{ __('reports.by_client') }}</h2>
    <table class="data">
        <thead><tr><th>{{ __('reports.client') }}</th><th class="num">{{ __('reports.share') }}</th><th class="num">{{ __('reports.hours') }}</th><th class="num">{{ __('reports.billable') }}</th>@if($showAmount)<th class="num">{{ __('reports.amount') }}</th>@endif</tr></thead>
        <tbody>
        @foreach($report['by_client'] as $row)
            <tr>
                <td>@if($row['color'])<span class="dot" style="background: {{ $row['color'] }}"></span>@endif{{ $row['name'] }}</td>
                <td class="num"><span class="bar-wrap"><span class="bar" style="width: {{ $pct($row['total_seconds'], $totals['total_seconds']) * 0.6 }}pt"></span></span>{{ $pct($row['total_seconds'], $totals['total_seconds']) }}%</td>
                <td class="num">{{ $fmtH($row['total_seconds']) }}</td>
                <td class="num">{{ $fmtH($row['billable_seconds']) }}</td>
                @if($showAmount)<td class="num">{{ $fmtMoney($row['amount']) }}</td>@endif
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    @if($showProjects)
    <h2>{{ __('reports.by_project') }}</h2>
    <table class="data">
        <thead><tr><th>{{ __('reports.project') }}</th>@if($scope['type'] !== 'client')<th>{{ __('reports.client') }}</th>@endif<th class="num">{{ __('reports.share') }}</th><th class="num">{{ __('reports.hours') }}</th><th class="num">{{ __('reports.billable') }}</th>@if($showAmount)<th class="num">{{ __('reports.amount') }}</th>@endif</tr></thead>
        <tbody>
        @foreach($report['by_project'] as $row)
            <tr>
                <td>@if($row['color'])<span class="dot" style="background: {{ $row['color'] }}"></span>@endif{{ $row['name'] }}</td>
                @if($scope['type'] !== 'client')<td class="muted">{{ $row['subtitle'] }}</td>@endif
                <td class="num"><span class="bar-wrap"><span class="bar" style="width: {{ $pct($row['total_seconds'], $totals['total_seconds']) * 0.6 }}pt"></span></span>{{ $pct($row['total_seconds'], $totals['total_seconds']) }}%</td>
                <td class="num">{{ $fmtH($row['total_seconds']) }}</td>
                <td class="num">{{ $fmtH($row['billable_seconds']) }}</td>
                @if($showAmount)<td class="num">{{ $fmtMoney($row['amount']) }}</td>@endif
            </tr>
        @endforeach
            <tr class="total">
                <td colspan="{{ $scope['type'] !== 'client' ? 3 : 2 }}">{{ __('reports.total') }}</td>
                <td class="num">{{ $fmtH($totals['total_seconds']) }}</td>
                <td class="num">{{ $fmtH($totals['billable_seconds']) }}</td>
                @if($showAmount)<td class="num">{{ $fmtMoney($totals['amount']) }}</td>@endif
            </tr>
        </tbody>
    </table>
    @endif

    @if($showTasks)
    <h2>{{ __('reports.by_task') }}</h2>
    <table class="data">
        <thead><tr><th>{{ __('reports.task') }}</th><th class="num">{{ __('reports.share') }}</th><th class="num">{{ __('reports.hours') }}</th><th class="num">{{ __('reports.billable') }}</th></tr></thead>
        <tbody>
        @foreach($report['by_task'] as $row)
            <tr>
                <td>{{ $row['name'] !== '' ? $row['name'] : '-' }}</td>
                <td class="num"><span class="bar-wrap"><span class="bar" style="width: {{ $pct($row['total_seconds'], $totals['total_seconds']) * 0.6 }}pt"></span></span>{{ $pct($row['total_seconds'], $totals['total_seconds']) }}%</td>
                <td class="num">{{ $fmtH($row['total_seconds']) }}</td>
                <td class="num">{{ $fmtH($row['billable_seconds']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    @if($showUsers)
    <h2>{{ __('reports.by_user') }}</h2>
    <table class="data">
        <thead><tr><th>{{ __('reports.user') }}</th><th class="num">{{ __('reports.share') }}</th><th class="num">{{ __('reports.hours') }}</th><th class="num">{{ __('reports.billable') }}</th><th class="num">{{ __('reports.entries') }}</th></tr></thead>
        <tbody>
        @foreach($report['by_user'] as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td class="num"><span class="bar-wrap"><span class="bar" style="width: {{ $pct($row['total_seconds'], $totals['total_seconds']) * 0.6 }}pt"></span></span>{{ $pct($row['total_seconds'], $totals['total_seconds']) }}%</td>
                <td class="num">{{ $fmtH($row['total_seconds']) }}</td>
                <td class="num">{{ $fmtH($row['billable_seconds']) }}</td>
                <td class="num">{{ $row['entry_count'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    <h2 class="pagebreak">{{ __('reports.detail') }}</h2>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 58pt">{{ __('reports.time') }}</th>
                @if($showUsers)<th>{{ __('reports.user') }}</th>@endif
                @if($showProjects)<th>{{ __('reports.project') }}</th>@endif
                <th>{{ __('reports.task') }}</th>
                <th>{{ __('reports.description') }}</th>
                <th class="num" style="width: 44pt">{{ __('reports.hours') }}</th>
            </tr>
        </thead>
        <tbody>
        @php $detailCols = 3 + ($showUsers ? 1 : 0) + ($showProjects ? 1 : 0); @endphp
        @foreach($report['days'] as $day)
            <tr class="day">
                <td colspan="{{ $detailCols }}">{{ $fmtDate($day['date']) }}</td>
                <td class="num">{{ $fmtH($day['total_seconds']) }}</td>
            </tr>
            @foreach($day['entries'] as $e)
            <tr>
                <td class="time">{{ $e['start_time'] }}@if($e['end_time']) - {{ $e['end_time'] }}@endif</td>
                @if($showUsers)<td>{{ $e['user_name'] }}</td>@endif
                @if($showProjects)<td>@if($scope['type'] !== 'client' && $e['client_name'])<span class="muted">{{ $e['client_name'] }} / </span>@endif{{ $e['project_name'] }}</td>@endif
                <td>{{ $e['task_name'] !== '' ? $e['task_name'] : '-' }}</td>
                <td class="desc">{{ $e['description'] }}@if(! $e['is_billable']) <span class="muted">({{ __('reports.non_billable') }})</span>@endif</td>
                <td class="num">{{ $fmtH($e['rounded_seconds']) }}</td>
            </tr>
            @endforeach
        @endforeach
            <tr class="total">
                <td colspan="{{ $detailCols }}">{{ __('reports.total') }} ({{ $fmtDec($totals['total_seconds']) }} {{ __('reports.hours_short') }})</td>
                <td class="num">{{ $fmtH($totals['total_seconds']) }}</td>
            </tr>
        </tbody>
    </table>
@endif

</body>
</html>
