<script setup lang="ts">
import { ref, watch, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import { downloadFile } from '@/api/download'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useToastStore, errorMessage } from '@/stores/toast'
import type { SummaryRow, ReportTotals, BudgetRow, UtilizationRow, Project, TimeEntry, PaginationMeta } from '@/types'
import { formatDuration, formatHoursDecimal, formatCurrency, formatDate, formatDateLong, formatTime, formatMonth, toDateString } from '@/utils/format'
import { ArrowDownTrayIcon, DocumentTextIcon } from '@heroicons/vue/24/outline'
import ComboBox from '@/components/ComboBox.vue'
import AppPagination from '@/components/AppPagination.vue'

const { t, locale } = useI18n()
const auth = useAuthStore()
const settings = useSettingsStore()
const toast = useToastStore()

type ReportType = 'summary' | 'timesheet' | 'budget' | 'utilization'
type GroupBy = 'project' | 'client' | 'user' | 'day' | 'week' | 'month'
type ChartGroup = 'project' | 'client' | 'user'
type Preset = 'this_week' | 'last_week' | 'this_month' | 'last_month' | 'this_quarter' | 'this_year'

const CHART_GROUPS: readonly ChartGroup[] = ['project', 'client', 'user']
const CHART_LIMIT = 8
const TIMESHEET_PER_PAGE = 100

const reportType = ref<ReportType>('summary')
const groupBy = ref<GroupBy>('project')
const dateFrom = ref('')
const dateTo = ref('')
const filterProjectId = ref('')
const activePreset = ref<Preset | null>(null)

const summaryData = ref<SummaryRow[]>([])
const totals = ref<ReportTotals | null>(null)
const chartData = ref<Record<ChartGroup, SummaryRow[]>>({ project: [], client: [], user: [] })
const budgetData = ref<BudgetRow[]>([])
const utilizationData = ref<UtilizationRow[]>([])
const timesheetEntries = ref<TimeEntry[]>([])
const timesheetMeta = ref<PaginationMeta | null>(null)
const timesheetPage = ref(1)

const projects = ref<Project[]>([])
const loading = ref(false)
const downloading = ref<'pdf' | 'csv' | null>(null)

// Cache keys so tab switches and group-by changes do not refetch unchanged data.
const summaryKey = ref('')
const utilizationKey = ref('')
const timesheetKey = ref('')
const budgetLoaded = ref(false)

const tabs = computed(() => {
  const list: { key: ReportType; label: string }[] = [
    { key: 'summary', label: t('reports.summary') },
    { key: 'timesheet', label: t('reports.timesheet') },
    { key: 'budget', label: t('reports.budget') },
  ]
  if (auth.isAdmin) list.push({ key: 'utilization', label: t('reports.utilization') })
  return list
})

const presets = computed<{ key: Preset; label: string }[]>(() => [
  { key: 'this_week', label: t('reports.thisWeek') },
  { key: 'last_week', label: t('reports.lastWeek') },
  { key: 'this_month', label: t('reports.thisMonth') },
  { key: 'last_month', label: t('reports.lastMonth') },
  { key: 'this_quarter', label: t('reports.thisQuarter') },
  { key: 'this_year', label: t('reports.thisYear') },
])

const groupOptions = computed<{ value: GroupBy; label: string }[]>(() => [
  { value: 'project', label: t('reports.groupProject') },
  { value: 'client', label: t('reports.groupClient') },
  { value: 'user', label: t('reports.groupUser') },
  { value: 'day', label: t('reports.groupDay') },
  { value: 'week', label: t('reports.groupWeek') },
  { value: 'month', label: t('reports.groupMonth') },
])

function setPreset(preset: Preset) {
  activePreset.value = preset
  const today = new Date()
  const y = today.getFullYear()
  const m = today.getMonth()
  const mondayOf = (d: Date) => {
    const monday = new Date(d)
    monday.setDate(d.getDate() - ((d.getDay() + 6) % 7))
    return monday
  }

  let from: Date
  let to: Date = today
  switch (preset) {
    case 'this_week':
      from = mondayOf(today)
      break
    case 'last_week': {
      const monday = mondayOf(today)
      from = new Date(monday)
      from.setDate(monday.getDate() - 7)
      to = new Date(monday)
      to.setDate(monday.getDate() - 1)
      break
    }
    case 'last_month':
      from = new Date(y, m - 1, 1)
      to = new Date(y, m, 0)
      break
    case 'this_quarter':
      from = new Date(y, Math.floor(m / 3) * 3, 1)
      break
    case 'this_year':
      from = new Date(y, 0, 1)
      break
    default:
      from = new Date(y, m, 1)
  }
  dateFrom.value = toDateString(from)
  dateTo.value = toDateString(to)
}

function onDateInput() {
  activePreset.value = null
}

const rangeValid = computed(() => !!dateFrom.value && !!dateTo.value && dateFrom.value <= dateTo.value)

function baseParams(): Record<string, string> {
  const params: Record<string, string> = { date_from: dateFrom.value, date_to: dateTo.value }
  if (filterProjectId.value) params['filter[project_id]'] = filterProjectId.value
  return params
}

function rangeKey(): string {
  return `${dateFrom.value}|${dateTo.value}|${filterProjectId.value}`
}

function isChartGroup(g: GroupBy): g is ChartGroup {
  return (CHART_GROUPS as readonly string[]).includes(g)
}

async function fetchSummary() {
  if (!rangeValid.value) return
  const key = rangeKey()
  const cached = summaryKey.value === key
  const group = groupBy.value

  if (cached && isChartGroup(group)) {
    summaryData.value = chartData.value[group]
    return
  }

  loading.value = true
  try {
    if (cached) {
      // Only the non-chart grouping (day/week/month) is missing.
      const { data } = await api.get('/reports/summary', { params: { ...baseParams(), group_by: group } })
      summaryData.value = data.data
      return
    }

    const fetches = CHART_GROUPS.map((g) => api.get('/reports/summary', { params: { ...baseParams(), group_by: g } }))
    if (!isChartGroup(group)) {
      fetches.push(api.get('/reports/summary', { params: { ...baseParams(), group_by: group } }))
    }
    const results = await Promise.all(fetches)
    chartData.value = {
      project: results[0]!.data.data,
      client: results[1]!.data.data,
      user: results[2]!.data.data,
    }
    totals.value = results[0]!.data.meta?.totals ?? null
    summaryData.value = isChartGroup(group) ? chartData.value[group] : results[3]!.data.data
    summaryKey.value = key
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
  }
}

async function fetchTimesheet() {
  if (!rangeValid.value) return
  const key = `${rangeKey()}|${timesheetPage.value}`
  if (timesheetKey.value === key) return

  loading.value = true
  try {
    const { data } = await api.get('/reports/detailed', {
      params: { ...baseParams(), per_page: TIMESHEET_PER_PAGE, page: timesheetPage.value },
    })
    timesheetEntries.value = data.data
    timesheetMeta.value = {
      current_page: data.meta.current_page,
      last_page: data.meta.last_page,
      per_page: data.meta.per_page ?? TIMESHEET_PER_PAGE,
      total: data.meta.total,
    }
    totals.value = data.meta?.totals ?? null
    timesheetKey.value = key
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
  }
}

async function fetchBudget() {
  if (budgetLoaded.value) return
  loading.value = true
  try {
    const { data } = await api.get('/reports/budget')
    budgetData.value = data.data
    budgetLoaded.value = true
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
  }
}

async function fetchUtilization() {
  if (!rangeValid.value) return
  const key = `${dateFrom.value}|${dateTo.value}`
  if (utilizationKey.value === key) return

  loading.value = true
  try {
    const { data } = await api.get('/reports/utilization', { params: { date_from: dateFrom.value, date_to: dateTo.value } })
    utilizationData.value = data.data
    utilizationKey.value = key
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
  }
}

function fetchReport() {
  switch (reportType.value) {
    case 'summary':
      return fetchSummary()
    case 'timesheet':
      return fetchTimesheet()
    case 'budget':
      return fetchBudget()
    case 'utilization':
      return fetchUtilization()
  }
}

async function fetchProjects() {
  try {
    const { data } = await api.get('/projects', { params: { 'filter[is_active]': true, per_page: 500 } })
    projects.value = data.data
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  }
}

async function downloadPdf() {
  if (!rangeValid.value) return
  downloading.value = 'pdf'
  try {
    const url = filterProjectId.value ? `/reports/projects/${filterProjectId.value}` : '/reports/organization'
    await downloadFile(url, {
      date_from: dateFrom.value,
      date_to: dateTo.value,
      rounding: settings.roundingInterval,
      locale: locale.value,
      format: 'pdf',
    })
  } catch (e) {
    toast.error(errorMessage(e, t('reports.pdfFailed')))
  } finally {
    downloading.value = null
  }
}

async function exportCsv() {
  if (!rangeValid.value) return
  downloading.value = 'csv'
  try {
    await downloadFile('/reports/export', { ...baseParams(), format: 'csv' })
  } catch (e) {
    toast.error(errorMessage(e, t('reports.csvFailed')))
  } finally {
    downloading.value = null
  }
}

// ---------------------------------------------------------------- Derived

const projectOptions = computed(() =>
  projects.value.map((p) => ({ id: p.id, label: p.name, subtitle: p.client?.name, color: p.color }))
)

const selectedProject = computed(() =>
  filterProjectId.value ? projects.value.find((p) => p.id === filterProjectId.value) ?? null : null
)

const showProjectCol = computed(() => !filterProjectId.value)
const showFilters = computed(() => reportType.value !== 'budget')
const showProjectFilter = computed(() => reportType.value === 'summary' || reportType.value === 'timesheet')
const showPdf = computed(() => reportType.value === 'summary' || reportType.value === 'timesheet')

/** Billable amount computed from the project grouping and the project hourly rates. */
const billableAmount = computed(() => {
  let total = 0
  for (const row of chartData.value.project) {
    if (!row.project_id) continue
    const project = projects.value.find((p) => p.id === row.project_id)
    if (project?.hourly_rate && row.billable_hours > 0) total += row.billable_hours * project.hourly_rate
  }
  return total
})

interface ChartRow {
  key: string
  label: string
  color?: string
  total: number
  billable: number
}

interface Chart {
  key: ChartGroup
  title: string
  rows: ChartRow[]
  max: number
}

function toChartRows(rows: SummaryRow[], label: (r: SummaryRow) => string, key: (r: SummaryRow) => string): ChartRow[] {
  return [...rows]
    .sort((a, b) => b.total_hours - a.total_hours)
    .slice(0, CHART_LIMIT)
    .map((r) => ({ key: key(r), label: label(r), color: r.color, total: r.total_hours, billable: r.billable_hours }))
}

const charts = computed<Chart[]>(() => {
  const list: Chart[] = [
    { key: 'project', title: t('reports.byProject'), rows: toChartRows(chartData.value.project, (r) => r.project_name ?? '', (r) => r.project_id ?? ''), max: 1 },
    { key: 'client', title: t('reports.byClient'), rows: toChartRows(chartData.value.client, (r) => r.client_name ?? '', (r) => r.client_id ?? ''), max: 1 },
    { key: 'user', title: t('reports.byTeamMember'), rows: toChartRows(chartData.value.user, (r) => r.user_name ?? '', (r) => r.user_id ?? ''), max: 1 },
  ]
  for (const chart of list) chart.max = Math.max(...chart.rows.map((r) => r.total), 1)
  return list.filter((c) => c.rows.length > 0)
})

const chartsTotal = computed(() => charts.value.reduce((n, c) => n + c.rows.length, 0))

const summaryLabelHeading = computed(() => {
  switch (groupBy.value) {
    case 'project': return t('reports.project')
    case 'client': return t('reports.client')
    case 'user': return t('reports.user')
    default: return t('reports.period')
  }
})

type ReportScopeKey = 'project' | 'client' | 'user'
const scopeRoute: Record<ReportScopeKey, string> = { project: 'projects', client: 'clients', user: 'users' }

/** Route to an entity report, keeping the current period. */
function reportLink(scope: ReportScopeKey, id: string | null | undefined) {
  if (!id) return null
  return { name: 'report-detail', params: { scope: scopeRoute[scope], id }, query: { from: dateFrom.value, to: dateTo.value } }
}

function summaryLink(row: SummaryRow) {
  if (row.project_id) return reportLink('project', row.project_id)
  if (row.client_id) return reportLink('client', row.client_id)
  if (row.user_id && auth.isAdmin) return reportLink('user', row.user_id)
  return null
}

function summaryLabel(row: SummaryRow): string {
  if (row.period) {
    if (groupBy.value === 'day') return formatDate(row.period + 'T00:00:00')
    if (groupBy.value === 'month') return formatMonth(row.period + '-01')
    return row.period
  }
  return row.project_name || row.client_name || row.user_name || '-'
}

/** Decimal hours -> "h:mm". */
function hours(h: number | null | undefined): string {
  return formatDuration(Math.round((h ?? 0) * 3600))
}

interface TimesheetDay {
  date: string
  entries: TimeEntry[]
  totalSeconds: number
}

const timesheetByDate = computed<TimesheetDay[]>(() => {
  const map = new Map<string, TimesheetDay>()
  for (const entry of timesheetEntries.value) {
    const date = entry.started_at.split('T')[0]!
    let day = map.get(date)
    if (!day) {
      day = { date, entries: [], totalSeconds: 0 }
      map.set(date, day)
    }
    day.entries.push(entry)
    day.totalSeconds += settings.roundUpSeconds(entry.duration_seconds)
  }
  return [...map.values()].sort((a, b) => b.date.localeCompare(a.date))
})

const roundedTimesheetTotals = computed<ReportTotals | null>(() => {
  if (!settings.roundingInterval || !timesheetEntries.value.length) return null
  let totalSec = 0
  let billableSec = 0
  for (const e of timesheetEntries.value) {
    const r = settings.roundUpSeconds(e.duration_seconds)
    totalSec += r
    if (e.is_billable) billableSec += r
  }
  return {
    total_hours: totalSec / 3600,
    billable_hours: billableSec / 3600,
    non_billable_hours: (totalSec - billableSec) / 3600,
    entry_count: timesheetMeta.value?.total ?? timesheetEntries.value.length,
  }
})

const displayTotals = computed(() =>
  reportType.value === 'timesheet' ? (roundedTimesheetTotals.value ?? totals.value) : totals.value
)

const timesheetColspan = computed(() => (showProjectCol.value ? 5 : 4))

function budgetBadge(status: BudgetRow['status']): string {
  return status === 'on_track' ? 'badge--success' : status === 'at_risk' ? 'badge--warning' : 'badge--danger'
}

function budgetFill(status: BudgetRow['status']): string {
  return status === 'on_track' ? 'bar__fill--success' : status === 'at_risk' ? 'bar__fill--warning' : 'bar__fill--danger'
}

function budgetLabel(status: BudgetRow['status']): string {
  return status === 'on_track' ? t('reports.onTrack') : status === 'at_risk' ? t('reports.atRisk') : t('reports.overBudget')
}

// ---------------------------------------------------------------- Watchers

watch([dateFrom, dateTo, filterProjectId], () => {
  summaryKey.value = ''
  utilizationKey.value = ''
  timesheetKey.value = ''
  timesheetPage.value = 1
  fetchReport()
})

watch(reportType, fetchReport)
watch(groupBy, () => {
  if (reportType.value === 'summary') fetchSummary()
})
watch(timesheetPage, fetchTimesheet)

onMounted(() => {
  setPreset('this_month')
  fetchProjects()
})
</script>

<template>
  <div class="page reports">
    <div class="page__header">
      <h1 class="heading-1">{{ $t('reports.title') }}</h1>
      <div class="page__actions">
        <button type="button" class="btn btn--secondary" :disabled="downloading !== null || !rangeValid" @click="exportCsv">
          <ArrowDownTrayIcon class="btn__icon" />
          {{ downloading === 'csv' ? $t('reportDetail.preparing') : $t('reports.exportCsv') }}
        </button>
        <button v-if="showPdf" type="button" class="btn btn--primary" :disabled="downloading !== null || !rangeValid" :title="$t('reports.pdfHint')" @click="downloadPdf">
          <DocumentTextIcon class="btn__icon" />
          {{ downloading === 'pdf' ? $t('reportDetail.preparing') : $t('reportDetail.downloadPdf') }}
        </button>
      </div>
    </div>

    <div class="reports__tabs">
      <div class="segmented" role="tablist" :aria-label="$t('reports.tabs')">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          type="button"
          role="tab"
          class="segmented__item"
          :class="{ 'segmented__item--active': reportType === tab.key }"
          :aria-selected="reportType === tab.key"
          @click="reportType = tab.key"
        >
          {{ tab.label }}
        </button>
      </div>
    </div>

    <div v-if="showFilters" class="reports__filters">
      <div class="reports__presets" role="group" :aria-label="$t('reports.presets')">
        <button
          v-for="preset in presets"
          :key="preset.key"
          type="button"
          class="btn btn--sm reports__preset"
          :class="activePreset === preset.key ? 'reports__preset--active' : 'btn--secondary'"
          :aria-pressed="activePreset === preset.key"
          @click="setPreset(preset.key)"
        >
          {{ preset.label }}
        </button>
      </div>

      <div class="reports__controls">
        <div class="form__group">
          <label class="form__label" for="reports-from">{{ $t('common.from') }}</label>
          <input id="reports-from" v-model="dateFrom" type="date" class="form__input" :max="dateTo || undefined" @change="onDateInput" />
        </div>
        <div class="form__group">
          <label class="form__label" for="reports-to">{{ $t('common.to') }}</label>
          <input id="reports-to" v-model="dateTo" type="date" class="form__input" :min="dateFrom || undefined" @change="onDateInput" />
        </div>
        <div v-if="showProjectFilter" class="form__group reports__control--wide">
          <label class="form__label" for="reports-project">{{ $t('reports.project') }}</label>
          <ComboBox
            id="reports-project"
            v-model="filterProjectId"
            :options="projectOptions"
            :placeholder="$t('reports.allProjects')"
            :clearable="true"
            :clear-label="$t('reports.allProjects')"
          />
        </div>
        <div v-if="reportType === 'summary'" class="form__group">
          <label class="form__label" for="reports-group">{{ $t('reports.groupBy') }}</label>
          <select id="reports-group" v-model="groupBy" class="form__select">
            <option v-for="o in groupOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Totals -->
    <div v-if="displayTotals && showFilters" class="grid grid--5 reports__stats" :class="{ 'is-loading': loading }">
      <div class="stat">
        <span class="stat__label">{{ $t('reports.total') }}</span>
        <span class="stat__value">{{ hours(displayTotals.total_hours) }} {{ $t('reports.hoursUnit') }}</span>
        <span class="stat__sub">{{ formatHoursDecimal(displayTotals.total_hours * 3600) }} {{ $t('reports.hoursUnit') }}</span>
      </div>
      <div class="stat">
        <span class="stat__label">{{ $t('reports.billable') }}</span>
        <span class="stat__value stat__value--accent">{{ hours(displayTotals.billable_hours) }} {{ $t('reports.hoursUnit') }}</span>
        <span class="stat__sub">{{ formatHoursDecimal(displayTotals.billable_hours * 3600) }} {{ $t('reports.hoursUnit') }}</span>
      </div>
      <div class="stat">
        <span class="stat__label">{{ $t('reports.nonBillable') }}</span>
        <span class="stat__value">{{ hours(displayTotals.non_billable_hours) }} {{ $t('reports.hoursUnit') }}</span>
        <span class="stat__sub">{{ formatHoursDecimal(displayTotals.non_billable_hours * 3600) }} {{ $t('reports.hoursUnit') }}</span>
      </div>
      <div class="stat">
        <span class="stat__label">{{ $t('reports.entries') }}</span>
        <span class="stat__value">{{ displayTotals.entry_count }}</span>
      </div>
      <div v-if="billableAmount > 0" class="stat">
        <span class="stat__label">{{ $t('reports.billableAmount') }}</span>
        <span class="stat__value">{{ formatCurrency(billableAmount) }}</span>
      </div>
    </div>

    <!-- Summary -->
    <template v-if="reportType === 'summary'">
      <div v-if="charts.length" class="card reports__charts-card" :class="{ 'is-loading': loading }">
        <div class="card__header">
          <h2 class="card__title">{{ $t('reports.chartsTitle') }}</h2>
          <span class="reports__legend">
            <span class="reports__legend-item"><span class="reports__legend-swatch"></span>{{ $t('reports.billable') }}</span>
            <span class="reports__legend-item"><span class="reports__legend-swatch reports__legend-swatch--muted"></span>{{ $t('reports.nonBillable') }}</span>
          </span>
        </div>
        <div class="card__body">
          <div class="reports__charts" :class="`reports__charts--${charts.length}`">
            <section v-for="chart in charts" :key="chart.key" class="reports__chart">
              <h3 class="reports__chart-title">{{ chart.title }}</h3>
              <div class="reports__chart-rows">
                <div v-for="row in chart.rows" :key="row.key" class="reports__chart-row">
                  <component :is="chart.key === 'user' && !auth.isAdmin ? 'span' : 'RouterLink'" :to="reportLink(chart.key, row.key) ?? undefined" class="reports__chart-label" :class="{ 'reports__link': chart.key !== 'user' || auth.isAdmin }">
                    <span v-if="row.color" class="color-dot" :style="{ backgroundColor: row.color }"></span>
                    <span class="reports__chart-name">{{ row.label }}</span>
                  </component>
                  <span class="reports__chart-value">
                    <span class="bar bar--block reports__bar">
                      <span class="bar__fill" :style="{ width: (row.billable / chart.max) * 100 + '%' }"></span>
                      <span class="bar__fill reports__bar-fill--muted" :style="{ width: ((row.total - row.billable) / chart.max) * 100 + '%' }"></span>
                    </span>
                    <span class="reports__chart-hours">{{ hours(row.total) }}</span>
                  </span>
                </div>
              </div>
            </section>
          </div>
          <p v-if="chartsTotal" class="reports__charts-hint">{{ $t('reports.chartsHint', { count: CHART_LIMIT }) }}</p>
        </div>
      </div>

      <div class="card">
        <div v-if="loading && !summaryData.length" class="loading"><div class="spinner" role="status"></div></div>
        <div v-else-if="summaryData.length === 0" class="empty">
          <p class="empty__text">{{ $t('reports.noData') }}</p>
        </div>
        <div v-else class="table-wrap" :class="{ 'is-loading': loading }">
          <table class="table">
            <thead>
              <tr>
                <th>{{ summaryLabelHeading }}</th>
                <th class="table__num">{{ $t('reports.totalHours') }}</th>
                <th class="table__num">{{ $t('reports.billableHours') }}</th>
                <th class="table__num">{{ $t('reports.entries') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, i) in summaryData" :key="i" class="table__row">
                <td>
                  <component :is="summaryLink(row) ? 'RouterLink' : 'span'" :to="summaryLink(row) ?? undefined" class="cell" :class="{ 'reports__link': summaryLink(row) }">
                    <span v-if="row.color" class="color-dot" :style="{ backgroundColor: row.color }"></span>
                    <span class="cell__title">{{ summaryLabel(row) }}</span>
                  </component>
                </td>
                <td class="table__num">{{ hours(row.total_hours) }}</td>
                <td class="table__num">{{ hours(row.billable_hours) }}</td>
                <td class="table__num">{{ row.entry_count }}</td>
              </tr>
            </tbody>
            <tfoot v-if="totals">
              <tr class="table__total">
                <td>{{ $t('reports.total') }}</td>
                <td class="table__num">{{ hours(totals.total_hours) }}</td>
                <td class="table__num">{{ hours(totals.billable_hours) }}</td>
                <td class="table__num">{{ totals.entry_count }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </template>

    <!-- Timesheet -->
    <template v-if="reportType === 'timesheet'">
      <div v-if="selectedProject" class="reports__subject">
        <span class="color-dot color-dot--lg" :style="{ backgroundColor: selectedProject.color }"></span>
        <span class="reports__subject-name">{{ selectedProject.name }}</span>
        <span v-if="selectedProject.client?.name" class="reports__subject-sub">{{ selectedProject.client.name }}</span>
      </div>
      <p v-if="settings.roundingInterval" class="reports__note">
        {{ $t('reports.roundingNote', { minutes: settings.roundingInterval }) }}
      </p>

      <div class="card">
        <div v-if="loading && !timesheetEntries.length" class="loading"><div class="spinner" role="status"></div></div>
        <div v-else-if="timesheetEntries.length === 0" class="empty">
          <p class="empty__text">{{ $t('reports.noEntries') }}</p>
        </div>
        <template v-else>
          <div class="table-wrap" :class="{ 'is-loading': loading }">
            <table class="table reports__timesheet">
              <thead>
                <tr>
                  <th class="reports__col-time">{{ $t('reports.time') }}</th>
                  <th>{{ $t('reports.client') }}</th>
                  <th v-if="showProjectCol">{{ $t('reports.project') }}</th>
                  <th>{{ $t('reports.printTask') }}</th>
                  <th>{{ $t('reports.description') }}</th>
                  <th class="table__num">{{ $t('reports.printDuration') }}</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="day in timesheetByDate" :key="day.date">
                  <tr class="table__group">
                    <td :colspan="timesheetColspan">{{ formatDateLong(day.date + 'T00:00:00') }}</td>
                    <td class="table__num">{{ formatDuration(day.totalSeconds) }}</td>
                  </tr>
                  <tr v-for="entry in day.entries" :key="entry.id" class="table__row">
                    <td class="table__time">
                      {{ formatTime(entry.started_at) }}<template v-if="entry.stopped_at"> - {{ formatTime(entry.stopped_at) }}</template>
                    </td>
                    <td class="reports__col-client">
                      <RouterLink v-if="entry.project?.client" :to="reportLink('client', entry.project.client_id ?? entry.project.client.id) ?? {}" class="reports__link">{{ entry.project.client.name }}</RouterLink>
                      <template v-else>-</template>
                    </td>
                    <td v-if="showProjectCol">
                      <RouterLink :to="reportLink('project', entry.project_id) ?? {}" class="cell reports__link">
                        <span v-if="entry.project?.color" class="color-dot" :style="{ backgroundColor: entry.project.color }"></span>
                        <span class="cell__title">{{ entry.project?.name }}</span>
                      </RouterLink>
                    </td>
                    <td class="table__muted">{{ entry.task?.name ?? '-' }}</td>
                    <td class="reports__desc">
                      <span class="table__truncate reports__desc-text">{{ entry.description || '-' }}</span>
                      <span v-if="!entry.is_billable" class="badge badge--neutral">{{ $t('common.nonBillable') }}</span>
                    </td>
                    <td class="table__num">{{ formatDuration(settings.roundUpSeconds(entry.duration_seconds)) }}</td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
          <div v-if="timesheetMeta && timesheetMeta.last_page > 1" class="card__footer reports__pagination">
            <span class="toolbar__count">
              {{ $t('reports.timesheetPagination', { total: timesheetMeta.total, current: timesheetPage, last: timesheetMeta.last_page }) }}
            </span>
            <AppPagination :meta="timesheetMeta" :page="timesheetPage" @update:page="timesheetPage = $event" />
          </div>
        </template>
      </div>
    </template>

    <!-- Budget -->
    <div v-if="reportType === 'budget'" class="card">
      <div class="card__header">
        <h2 class="card__title">{{ $t('reports.budget') }}</h2>
        <span class="reports__hint">{{ $t('reports.budgetHint') }}</span>
      </div>
      <div v-if="loading && !budgetData.length" class="loading"><div class="spinner" role="status"></div></div>
      <div v-else-if="budgetData.length === 0" class="empty">
        <p class="empty__text">{{ $t('reports.noBudgets') }}</p>
      </div>
      <div v-else class="table-wrap">
        <table class="table table--compact">
          <thead>
            <tr>
              <th>{{ $t('reports.project') }}</th>
              <th>{{ $t('reports.client') }}</th>
              <th class="table__num">{{ $t('reports.budget') }}</th>
              <th class="table__num">{{ $t('reports.total') }}</th>
              <th class="table__num">{{ $t('reports.remaining') }}</th>
              <th class="table__num">{{ $t('reports.hourlyRate') }}</th>
              <th class="table__num">{{ $t('reports.revenue') }}</th>
              <th class="table__num">{{ $t('reports.progress') }}</th>
              <th>{{ $t('reports.status') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in budgetData" :key="row.id" class="table__row">
              <td>
                <RouterLink :to="{ name: 'report-detail', params: { scope: 'projects', id: row.id } }" class="cell reports__link">
                  <span class="color-dot" :style="{ backgroundColor: row.color }"></span>
                  <span class="cell__title">{{ row.project_name }}</span>
                </RouterLink>
              </td>
              <td class="table__muted reports__col-client">{{ row.client_name }}</td>
              <td class="table__num">{{ hours(row.budget_hours) }}</td>
              <td class="table__num">{{ hours(row.tracked_hours) }}</td>
              <td class="table__num" :class="{ 'reports__num--danger': row.remaining_hours < 0 }">{{ hours(row.remaining_hours) }}</td>
              <td class="table__num">{{ row.hourly_rate ? formatCurrency(row.hourly_rate) : '-' }}</td>
              <td class="table__num">{{ row.revenue ? formatCurrency(row.revenue) : '-' }}</td>
              <td class="table__num">
                <span class="share">
                  <span class="bar">
                    <span class="bar__fill" :class="budgetFill(row.status)" :style="{ width: Math.min(row.budget_used_percentage, 100) + '%' }"></span>
                  </span>
                  {{ row.budget_used_percentage }}%
                </span>
              </td>
              <td><span class="badge" :class="budgetBadge(row.status)">{{ budgetLabel(row.status) }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Utilization -->
    <div v-if="reportType === 'utilization'" class="card">
      <div v-if="loading && !utilizationData.length" class="loading"><div class="spinner" role="status"></div></div>
      <div v-else-if="utilizationData.length === 0" class="empty">
        <p class="empty__text">{{ $t('reports.noUtilization') }}</p>
      </div>
      <div v-else class="table-wrap" :class="{ 'is-loading': loading }">
        <table class="table">
          <thead>
            <tr>
              <th>{{ $t('reports.teamMember') }}</th>
              <th class="table__num">{{ $t('reports.totalHours') }}</th>
              <th class="table__num">{{ $t('reports.billable') }}</th>
              <th class="table__num">{{ $t('reports.nonBillable') }}</th>
              <th class="table__num">{{ $t('reports.billablePercent') }}</th>
              <th class="table__num">{{ $t('reports.daysTracked') }}</th>
              <th class="table__num">{{ $t('reports.avgPerDay') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in utilizationData" :key="row.id" class="table__row">
              <td>
                <RouterLink :to="{ name: 'report-detail', params: { scope: 'users', id: row.id }, query: { from: dateFrom, to: dateTo } }" class="reports__link">
                  {{ row.name }}
                </RouterLink>
              </td>
              <td class="table__num">{{ hours(row.total_hours) }}</td>
              <td class="table__num">{{ hours(row.billable_hours) }}</td>
              <td class="table__num">{{ hours(row.non_billable_hours) }}</td>
              <td class="table__num">
                <span class="share">
                  <span class="bar"><span class="bar__fill bar__fill--success" :style="{ width: Math.min(row.billable_percentage, 100) + '%' }"></span></span>
                  {{ row.billable_percentage }}%
                </span>
              </td>
              <td class="table__num">{{ row.days_tracked }}</td>
              <td class="table__num">{{ formatHoursDecimal(row.avg_hours_per_day * 3600) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
