<script setup lang="ts">
import { ref, watch, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import type { SummaryRow, ReportTotals, BudgetRow, UtilizationRow, Project, TimeEntry } from '@/types'
import { ArrowDownTrayIcon, PrinterIcon } from '@heroicons/vue/24/outline'
import ComboBox from '@/components/ComboBox.vue'

const { t } = useI18n()
const auth = useAuthStore()
const settings = useSettingsStore()

type ReportType = 'summary' | 'budget' | 'utilization' | 'timesheet'
const reportType = ref<ReportType>('summary')
const groupBy = ref('project')
const dateFrom = ref(getFirstOfMonth())
const dateTo = ref(new Date().toISOString().split('T')[0]!)
const filterProjectId = ref('')

const summaryData = ref<SummaryRow[]>([])
const totals = ref<ReportTotals | null>(null)
const budgetData = ref<BudgetRow[]>([])
const utilizationData = ref<UtilizationRow[]>([])
const timesheetEntries = ref<TimeEntry[]>([])
const timesheetMeta = ref<{ current_page: number; last_page: number; total: number; totals: ReportTotals | null } | null>(null)
const timesheetPage = ref(1)

const chartProjectData = ref<SummaryRow[]>([])
const chartClientData = ref<SummaryRow[]>([])
const chartUserData = ref<SummaryRow[]>([])

const projects = ref<Project[]>([])
const loading = ref(false)

function fmt(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function getFirstOfMonth(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
}

function formatDuration(seconds: number | null): string {
  if (!seconds) return '0:00'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  return `${h}:${String(m).padStart(2, '0')}`
}

function formatTotalTime(hours: number): string {
  const totalSeconds = Math.round(hours * 3600)
  const h = Math.floor(totalSeconds / 3600)
  const m = Math.floor((totalSeconds % 3600) / 60)
  const parts: string[] = []
  if (h > 0) parts.push(`${h}h`)
  if (m > 0 || parts.length === 0) parts.push(`${m}min`)
  return parts.join(' ')
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString(undefined, { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' })
}

function formatDateLong(iso: string): string {
  return new Date(iso).toLocaleDateString(undefined, { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' })
}

function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
}

function formatCurrency(amount: number): string {
  return amount.toLocaleString(undefined, { style: 'currency', currency: 'EUR' })
}

// Billable amount computed from project-level summary + hourly rates
const billableAmount = computed(() => {
  let total = 0
  for (const row of chartProjectData.value) {
    if (!row.project_id) continue
    const project = projects.value.find(p => p.id === row.project_id)
    if (project?.hourly_rate && row.billable_hours > 0) {
      total += row.billable_hours * project.hourly_rate
    }
  }
  return total
})

// Chart data: top 8 entries by total_hours for each grouping
const topChartProjects = computed(() =>
  [...chartProjectData.value].sort((a, b) => b.total_hours - a.total_hours).slice(0, 8)
)
const topChartClients = computed(() =>
  [...chartClientData.value].sort((a, b) => b.total_hours - a.total_hours).slice(0, 8)
)
const topChartUsers = computed(() =>
  [...chartUserData.value].sort((a, b) => b.total_hours - a.total_hours).slice(0, 8)
)
const maxProjectHours = computed(() => Math.max(...topChartProjects.value.map(r => r.total_hours), 1))
const maxClientHours = computed(() => Math.max(...topChartClients.value.map(r => r.total_hours), 1))
const maxUserHours = computed(() => Math.max(...topChartUsers.value.map(r => r.total_hours), 1))

// Resolved filter context
const selectedProject = computed(() =>
  filterProjectId.value ? projects.value.find(p => p.id === filterProjectId.value) ?? null : null
)
// Hide project column when filtered by project (shown in title instead)
const showProjectCol = computed(() => !filterProjectId.value)

type DateRange = 'this_week' | 'this_month' | 'last_month' | 'this_quarter' | 'this_year'
const activeRange = ref<DateRange | null>('this_month')
const dateRangeOptions = computed(() => [
  { key: 'this_week' as DateRange, label: t('reports.thisWeek') },
  { key: 'this_month' as DateRange, label: t('reports.thisMonth') },
  { key: 'last_month' as DateRange, label: t('reports.lastMonth') },
  { key: 'this_quarter' as DateRange, label: t('reports.thisQuarter') },
  { key: 'this_year' as DateRange, label: t('reports.thisYear') },
])

function setRange(range: DateRange) {
  activeRange.value = range
  const today = new Date()
  const y = today.getFullYear()
  const m = today.getMonth()

  if (range === 'this_week') {
    const dow = today.getDay()
    const monday = new Date(today)
    monday.setDate(today.getDate() - ((dow + 6) % 7))
    dateFrom.value = fmt(monday)
    dateTo.value = fmt(today)
  } else if (range === 'this_month') {
    dateFrom.value = `${y}-${String(m + 1).padStart(2, '0')}-01`
    dateTo.value = fmt(today)
  } else if (range === 'last_month') {
    dateFrom.value = fmt(new Date(y, m - 1, 1))
    dateTo.value = fmt(new Date(y, m, 0))
  } else if (range === 'this_quarter') {
    const q = Math.floor(m / 3)
    dateFrom.value = fmt(new Date(y, q * 3, 1))
    dateTo.value = fmt(today)
  } else if (range === 'this_year') {
    dateFrom.value = `${y}-01-01`
    dateTo.value = fmt(today)
  }
}

async function fetchReport() {
  if (reportType.value === 'timesheet') {
    await fetchTimesheet()
    return
  }

  loading.value = true
  try {
    if (reportType.value === 'summary') {
      const baseParams: Record<string, string> = {
        date_from: dateFrom.value,
        date_to: dateTo.value,
      }
      if (filterProjectId.value) baseParams['filter[project_id]'] = filterProjectId.value

      const chartGroupings = ['project', 'client', 'user'] as const
      const fetches = chartGroupings.map(g =>
        api.get('/reports/summary', { params: { ...baseParams, group_by: g } })
      )
      const needsExtra = !(chartGroupings as readonly string[]).includes(groupBy.value)
      if (needsExtra) {
        fetches.push(api.get('/reports/summary', { params: { ...baseParams, group_by: groupBy.value } }))
      }

      const results = await Promise.all(fetches)
      chartProjectData.value = results[0]!.data.data
      chartClientData.value = results[1]!.data.data
      chartUserData.value = results[2]!.data.data
      totals.value = results[0]!.data.meta?.totals

      if (groupBy.value === 'project') summaryData.value = chartProjectData.value
      else if (groupBy.value === 'client') summaryData.value = chartClientData.value
      else if (groupBy.value === 'user') summaryData.value = chartUserData.value
      else summaryData.value = results[3]!.data.data
    } else if (reportType.value === 'budget') {
      const { data } = await api.get('/reports/budget')
      budgetData.value = data.data
    } else if (reportType.value === 'utilization') {
      const { data } = await api.get('/reports/utilization', {
        params: { date_from: dateFrom.value, date_to: dateTo.value },
      })
      utilizationData.value = data.data
    }
  } finally {
    loading.value = false
  }
}

async function fetchTimesheet() {
  loading.value = true
  try {
    const params: Record<string, string | number> = {
      date_from: dateFrom.value,
      date_to: dateTo.value,
      per_page: 100,
      page: timesheetPage.value,
    }
    if (filterProjectId.value) params['filter[project_id]'] = filterProjectId.value
    const { data } = await api.get('/reports/detailed', { params })
    timesheetEntries.value = data.data
    timesheetMeta.value = data.meta
    totals.value = data.meta?.totals ?? null
  } finally {
    loading.value = false
  }
}

const projectOptions = computed(() =>
  projects.value.map(p => ({
    id: p.id,
    label: p.name,
    subtitle: p.client?.name,
    color: p.color,
  }))
)

// Group timesheet entries by date for display
const timesheetByDate = computed(() => {
  const map = new Map<string, { entries: TimeEntry[]; totalSeconds: number }>()

  for (const entry of timesheetEntries.value) {
    const date = entry.started_at.split('T')[0]!
    if (!map.has(date)) map.set(date, { entries: [], totalSeconds: 0 })
    const g = map.get(date)!
    g.entries.push(entry)
    g.totalSeconds += settings.roundUpSeconds(entry.duration_seconds)
  }

  return [...map.entries()]
    .sort(([a], [b]) => b.localeCompare(a))
    .map(([date, val]) => ({ date, ...val }))
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
    total_hours: Math.round((totalSec / 3600) * 100) / 100,
    billable_hours: Math.round((billableSec / 3600) * 100) / 100,
    non_billable_hours: Math.round(((totalSec - billableSec) / 3600) * 100) / 100,
    entry_count: timesheetEntries.value.length,
  }
})

const displayTotals = computed(() =>
  reportType.value === 'timesheet' ? (roundedTimesheetTotals.value ?? totals.value) : totals.value
)

async function printTimesheet() {
  loading.value = true
  try {
    const params: Record<string, string | number> = {
      date_from: dateFrom.value,
      date_to: dateTo.value,
      per_page: 2000,
      page: 1,
    }
    if (filterProjectId.value) params['filter[project_id]'] = filterProjectId.value

    const { data } = await api.get('/reports/detailed', { params })
    const entries: TimeEntry[] = data.data
    const reportTotals: ReportTotals = data.meta?.totals

    const byDate = new Map<string, TimeEntry[]>()
    for (const e of entries) {
      const d = e.started_at.split('T')[0]!
      if (!byDate.has(d)) byDate.set(d, [])
      byDate.get(d)!.push(e)
    }
    const sortedDates = [...byDate.keys()].sort()

    const roundFn = (s: number | null) => settings.roundUpSeconds(s)

    // Determine if filtering by project
    const proj = selectedProject.value
    const hideProject = !!proj
    const colCount = hideProject ? 4 : 5
    const dateColspan = colCount - 1

    const rows = sortedDates.map((date) => {
      const dayEntries = byDate.get(date)!
      const daySeconds = dayEntries.reduce((s, e) => s + roundFn(e.duration_seconds), 0)
      const dateLabel = formatDateLong(date + 'T00:00:00')

      const entryRows = dayEntries.map(e => `
        <tr>
          <td></td>
          <td>${e.project?.client?.name ?? ''}</td>
          ${hideProject ? '' : `<td>${e.project?.name ?? ''}</td>`}
          <td>${e.task?.name ?? ''}</td>
          <td class="dur">${formatDuration(roundFn(e.duration_seconds))}</td>
        </tr>`).join('')

      return `
        <tr class="date-row">
          <td colspan="${dateColspan}">${dateLabel}</td>
          <td class="dur">${formatDuration(daySeconds)}</td>
        </tr>${entryRows}`
    }).join('')

    // Header title
    const reportTitle = proj ? proj.name : t('reports.timesheet')
    const reportSubtitle = proj
      ? `${proj.client?.name ? proj.client.name + ' · ' : ''}${dateFrom.value} – ${dateTo.value}`
      : `${dateFrom.value} – ${dateTo.value}`

    // Use rounded totals if rounding is active, else server totals
    let printTotalHours = reportTotals?.total_hours ?? 0
    let printBillableHours = reportTotals?.billable_hours ?? 0
    if (settings.roundingInterval) {
      const rTotalSec = entries.reduce((s, e) => s + roundFn(e.duration_seconds), 0)
      const rBillSec = entries.filter(e => e.is_billable).reduce((s, e) => s + roundFn(e.duration_seconds), 0)
      printTotalHours = Math.round((rTotalSec / 3600) * 100) / 100
      printBillableHours = Math.round((rBillSec / 3600) * 100) / 100
    }
    const totalFormatted = formatTotalTime(printTotalHours)
    const billableFormatted = formatTotalTime(printBillableHours)

    // Calculate billable amount for print
    let printBillableAmount = 0
    for (const e of entries) {
      if (e.is_billable && e.project?.hourly_rate && e.duration_seconds) {
        const roundedSec = settings.roundUpSeconds(e.duration_seconds)
        printBillableAmount += (roundedSec / 3600) * e.project.hourly_rate
      }
    }
    const amountNote = printBillableAmount > 0 ? `<div class="stat"><label>${t('reports.billableAmount')}</label><span class="green">${formatCurrency(printBillableAmount)}</span></div>` : ''
    const roundingNote = settings.roundingInterval ? `<div class="stat"><label>${t('reports.printRounding')}</label><span>↑${settings.roundingInterval}m</span></div>` : ''

    const projectColHeader = hideProject ? '' : `<th>${t('reports.project')}</th>`

    const html = `<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>${reportTitle} ${dateFrom.value} – ${dateTo.value}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,Helvetica,Arial,sans-serif;font-size:11px;color:#111}
.header{padding:24px 32px 16px;border-bottom:2px solid #111;margin-bottom:0}
.header-agency{font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}
.header h1{font-size:22px;font-weight:700;margin-bottom:2px}
.header-sub{font-size:12px;color:#555;margin-bottom:12px}
.header-stats{display:flex;gap:0;border-top:1px solid #e5e7eb;padding-top:12px;margin-top:4px}
.stat{padding-right:24px;margin-right:24px;border-right:1px solid #e5e7eb}
.stat:last-child{border-right:none}
.stat label{display:block;font-size:9px;color:#888;text-transform:uppercase;letter-spacing:.08em;margin-bottom:2px}
.stat span{font-size:14px;font-weight:700}
.stat span.green{color:#16a34a}
table{width:100%;border-collapse:collapse;margin-top:12px}
thead th{background:#f3f4f6;font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;padding:5px 8px;text-align:left;color:#666;border-bottom:1px solid #e5e7eb}
tbody td{padding:3px 8px;border-bottom:1px solid #f3f4f6;vertical-align:top;font-size:10px}
.date-row td{background:#eff6ff;font-weight:600;font-size:10px;padding:5px 8px;border-top:1px solid #dbeafe;border-bottom:1px solid #dbeafe}
.dur{font-variant-numeric:tabular-nums;white-space:nowrap;font-weight:600;text-align:right}
@media print{@page{margin:16mm;size:A4 portrait}body{font-size:10px}}
</style></head>
<body>
<div class="header">
  <div class="header-agency">freise design+digital</div>
  <h1>${reportTitle}</h1>
  <div class="header-sub">${reportSubtitle}</div>
  <div class="header-stats">
    <div class="stat"><label>${t('reports.printTotal')}</label><span>${totalFormatted}</span></div>
    <div class="stat"><label>${t('reports.printBillable')}</label><span class="green">${billableFormatted}</span></div>
    <div class="stat"><label>${t('reports.printEntries')}</label><span>${reportTotals?.entry_count ?? 0}</span></div>
    ${amountNote}
    ${roundingNote}
  </div>
</div>
<table>
<thead><tr>
  <th style="width:130px">${t('reports.printDate')}</th>
  <th>${t('reports.printClient')}</th>
  ${projectColHeader}
  <th>${t('reports.printTask')}</th>
  <th style="width:52px;text-align:right">${t('reports.printDuration')}</th>
</tr></thead>
<tbody>${rows}</tbody>
</table>
<script>window.onload=function(){window.print()}<\/script>
</body></html>`

    const w = window.open('', '_blank')
    if (w) { w.document.write(html); w.document.close() }
  } finally {
    loading.value = false
  }
}

async function exportCsv() {
  const params = new URLSearchParams({
    date_from: dateFrom.value,
    date_to: dateTo.value,
    format: 'csv',
  })
  if (filterProjectId.value) params.set('filter[project_id]', filterProjectId.value)
  window.open(`/api/reports/export?${params.toString()}`, '_blank')
}

watch(timesheetPage, fetchTimesheet)

watch([reportType, groupBy, dateFrom, dateTo, filterProjectId], () => {
  timesheetPage.value = 1
  fetchReport()
})

function onDateInput() {
  activeRange.value = null
}

onMounted(async () => {
  const [, projectsRes] = await Promise.all([
    fetchReport(),
    api.get('/projects', { params: { 'filter[is_active]': true, per_page: 500 } }),
  ])
  projects.value = projectsRes.data.data
})
</script>

<template>
  <div class="page-container">
    <div class="page-header">
      <h1 class="heading-1">{{ $t('reports.title') }}</h1>
      <div class="header-actions">
        <button v-if="reportType === 'timesheet'" class="btn-secondary" :disabled="loading" @click="printTimesheet">
          <PrinterIcon class="btn-icon-sm" />
          {{ $t('reports.printPdf') }}
        </button>
        <button class="btn-secondary" @click="exportCsv">
          <ArrowDownTrayIcon class="btn-icon-sm" />
          {{ $t('reports.exportCsv') }}
        </button>
      </div>
    </div>

    <!-- Report type tabs -->
    <div class="report-tabs">
      <button :class="reportType === 'summary' ? 'report-tab-active' : 'report-tab'" @click="reportType = 'summary'">{{ $t('reports.summary') }}</button>
      <button :class="reportType === 'timesheet' ? 'report-tab-active' : 'report-tab'" @click="reportType = 'timesheet'">{{ $t('reports.timesheet') }}</button>
      <button :class="reportType === 'budget' ? 'report-tab-active' : 'report-tab'" @click="reportType = 'budget'">{{ $t('reports.budget') }}</button>
      <button v-if="auth.isAdmin" :class="reportType === 'utilization' ? 'report-tab-active' : 'report-tab'" @click="reportType = 'utilization'">{{ $t('reports.utilization') }}</button>
    </div>

    <!-- Filters -->
    <div v-if="reportType !== 'budget'" class="filters-section">
      <div class="date-ranges">
        <button
          v-for="r in dateRangeOptions"
          :key="r.key"
          :class="activeRange === r.key ? 'range-btn-active' : 'range-btn'"
          @click="setRange(r.key)"
        >
          {{ r.label }}
        </button>
      </div>
      <div class="filters-bar">
        <div class="form-group">
          <label class="form-label">{{ $t('common.from') }}</label>
          <input v-model="dateFrom" type="date" class="form-input" @change="onDateInput" />
        </div>
        <div class="form-group">
          <label class="form-label">{{ $t('common.to') }}</label>
          <input v-model="dateTo" type="date" class="form-input" @change="onDateInput" />
        </div>
        <div v-if="reportType === 'summary' || reportType === 'timesheet'" class="form-group">
          <label class="form-label">{{ $t('reports.project') }}</label>
          <ComboBox
            v-model="filterProjectId"
            :options="projectOptions"
            :placeholder="$t('reports.allProjects')"
            :clearable="true"
            :clear-label="$t('reports.allProjects')"
          />
        </div>
        <div v-if="reportType === 'summary'" class="form-group">
          <label class="form-label">{{ $t('reports.groupBy') }}</label>
          <select v-model="groupBy" class="form-select">
            <option value="project">{{ $t('reports.groupProject') }}</option>
            <option value="client">{{ $t('reports.groupClient') }}</option>
            <option value="user">{{ $t('reports.groupUser') }}</option>
            <option value="day">{{ $t('reports.groupDay') }}</option>
            <option value="week">{{ $t('reports.groupWeek') }}</option>
            <option value="month">{{ $t('reports.groupMonth') }}</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Totals bar -->
    <div v-if="displayTotals && reportType !== 'budget'" class="totals-bar">
      <div class="total-item">
        <span class="total-label">{{ $t('reports.total') }}</span>
        <span class="total-value">{{ displayTotals?.total_hours }}h</span>
      </div>
      <div class="total-item">
        <span class="total-label">{{ $t('reports.billable') }}</span>
        <span class="total-value total-value-green">{{ displayTotals?.billable_hours }}h</span>
      </div>
      <div class="total-item">
        <span class="total-label">{{ $t('reports.nonBillable') }}</span>
        <span class="total-value">{{ displayTotals?.non_billable_hours }}h</span>
      </div>
      <div class="total-item">
        <span class="total-label">{{ $t('reports.entries') }}</span>
        <span class="total-value">{{ displayTotals?.entry_count }}</span>
      </div>
      <div v-if="billableAmount > 0" class="total-item">
        <span class="total-label">{{ $t('reports.billableAmount') }}</span>
        <span class="total-value total-value-green">{{ formatCurrency(billableAmount) }}</span>
      </div>
    </div>

    <!-- Timesheet -->
    <div v-if="reportType === 'timesheet'">
      <div v-if="settings.roundingInterval" class="rounding-note">
        {{ $t('reports.roundingNote', { minutes: settings.roundingInterval }) }}
      </div>
      <!-- Report title strip (shown when filtering by project) -->
      <div v-if="selectedProject" class="ts-title-strip">
        <div class="ts-title-left">
          <span class="color-dot" :style="{ backgroundColor: selectedProject.color }"></span>
          <div>
            <div class="ts-title-name">{{ selectedProject.name }}</div>
            <div class="text-muted">{{ selectedProject.client?.name }}</div>
          </div>
        </div>
        <div v-if="displayTotals" class="ts-title-stats">
          <div class="ts-stat">
            <span class="ts-stat-label">{{ $t('reports.total') }}</span>
            <span class="ts-stat-value">{{ formatTotalTime(displayTotals?.total_hours ?? 0) }}</span>
          </div>
          <div class="ts-stat">
            <span class="ts-stat-label">{{ $t('reports.billable') }}</span>
            <span class="ts-stat-value ts-stat-green">{{ formatTotalTime(displayTotals?.billable_hours ?? 0) }}</span>
          </div>
        </div>
      </div>

      <div class="card">
        <div v-if="loading" class="loading-center"><div class="loading-spinner"></div></div>
        <div v-else-if="timesheetEntries.length === 0" class="empty-state">
          <p class="empty-state-text">{{ $t('reports.noEntries') }}</p>
        </div>
        <div v-else>
          <div class="table-container">
            <table class="table">
              <thead class="table-header">
                <tr>
                  <th class="table-th ts-col-date">{{ $t('reports.printDate') }}</th>
                  <th class="table-th">{{ $t('reports.client') }}</th>
                  <th v-if="showProjectCol" class="table-th">{{ $t('reports.project') }}</th>
                  <th class="table-th">{{ $t('reports.printTask') }}</th>
                  <th class="table-th ts-col-dur">{{ $t('reports.printDuration') }}</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="group in timesheetByDate" :key="group.date">
                  <tr class="date-group-row">
                    <td :colspan="showProjectCol ? 4 : 3" class="date-group-label">{{ formatDate(group.date + 'T00:00:00') }}</td>
                    <td class="date-group-total">{{ formatDuration(group.totalSeconds) }}</td>
                  </tr>
                  <tr v-for="entry in group.entries" :key="entry.id" class="table-row entry-row">
                    <td class="table-td"></td>
                    <td class="table-td">{{ entry.project?.client?.name }}</td>
                    <td v-if="showProjectCol" class="table-td">
                      <div class="project-cell">
                        <span v-if="entry.project?.color" class="color-dot" :style="{ backgroundColor: entry.project.color }"></span>
                        {{ entry.project?.name }}
                      </div>
                    </td>
                    <td class="table-td text-muted">{{ entry.task?.name ?? '–' }}</td>
                    <td class="table-td table-td-mono ts-dur">{{ formatDuration(settings.roundUpSeconds(entry.duration_seconds)) }}</td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
          <div v-if="timesheetMeta && timesheetMeta.last_page > 1" class="ts-pagination">
            <span class="text-muted">{{ $t('reports.timesheetPagination', { total: timesheetMeta.total, current: timesheetPage, last: timesheetMeta.last_page }) }}</span>
            <div class="ts-pagination-btns">
              <button class="btn-secondary btn-sm" :disabled="timesheetPage === 1" @click="timesheetPage--">{{ $t('common.prev') }}</button>
              <button class="btn-secondary btn-sm" :disabled="timesheetPage === timesheetMeta.last_page" @click="timesheetPage++">{{ $t('common.next') }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Charts (summary view) -->
    <div v-if="reportType === 'summary' && !loading && (chartProjectData.length || chartClientData.length || chartUserData.length)" class="chart-card card">
      <div class="card-body">
        <div class="chart-grid">
          <!-- By Project -->
          <div v-if="topChartProjects.length" class="chart-section">
            <h3 class="chart-heading">{{ $t('reports.byProject') }}</h3>
            <div class="chart-bars">
              <div v-for="row in topChartProjects" :key="row.project_id" class="chart-row">
                <div class="chart-label">
                  <span v-if="row.color" class="color-dot" :style="{ backgroundColor: row.color }"></span>
                  <span class="chart-label-text">{{ row.project_name }}</span>
                </div>
                <div class="chart-bar-track">
                  <div class="chart-bar-fill chart-bar-billable" :style="{ width: (row.billable_hours / maxProjectHours * 100) + '%' }"></div>
                  <div class="chart-bar-fill chart-bar-nonbillable" :style="{ width: ((row.total_hours - row.billable_hours) / maxProjectHours * 100) + '%' }"></div>
                </div>
                <span class="chart-hours">{{ formatTotalTime(row.total_hours) }}</span>
              </div>
            </div>
          </div>

          <!-- By Client -->
          <div v-if="topChartClients.length" class="chart-section">
            <h3 class="chart-heading">{{ $t('reports.byClient') }}</h3>
            <div class="chart-bars">
              <div v-for="row in topChartClients" :key="row.client_id" class="chart-row">
                <div class="chart-label">
                  <span v-if="row.color" class="color-dot" :style="{ backgroundColor: row.color }"></span>
                  <span class="chart-label-text">{{ row.client_name }}</span>
                </div>
                <div class="chart-bar-track">
                  <div class="chart-bar-fill chart-bar-billable" :style="{ width: (row.billable_hours / maxClientHours * 100) + '%' }"></div>
                  <div class="chart-bar-fill chart-bar-nonbillable" :style="{ width: ((row.total_hours - row.billable_hours) / maxClientHours * 100) + '%' }"></div>
                </div>
                <span class="chart-hours">{{ formatTotalTime(row.total_hours) }}</span>
              </div>
            </div>
          </div>

          <!-- By Team Member -->
          <div v-if="topChartUsers.length" class="chart-section">
            <h3 class="chart-heading">{{ $t('reports.byTeamMember') }}</h3>
            <div class="chart-bars">
              <div v-for="row in topChartUsers" :key="row.user_id" class="chart-row">
                <div class="chart-label">
                  <span class="chart-label-text">{{ row.user_name }}</span>
                </div>
                <div class="chart-bar-track">
                  <div class="chart-bar-fill chart-bar-billable" :style="{ width: (row.billable_hours / maxUserHours * 100) + '%' }"></div>
                  <div class="chart-bar-fill chart-bar-nonbillable" :style="{ width: ((row.total_hours - row.billable_hours) / maxUserHours * 100) + '%' }"></div>
                </div>
                <span class="chart-hours">{{ formatTotalTime(row.total_hours) }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Legend -->
        <div class="chart-legend">
          <span class="chart-legend-item">
            <span class="chart-legend-dot chart-legend-billable"></span>
            {{ $t('reports.billable') }}
          </span>
          <span class="chart-legend-item">
            <span class="chart-legend-dot chart-legend-nonbillable"></span>
            {{ $t('reports.nonBillable') }}
          </span>
        </div>
      </div>
    </div>

    <!-- Summary Table -->
    <div v-if="reportType === 'summary'" class="card">
      <div v-if="loading" class="loading-center"><div class="loading-spinner"></div></div>
      <div v-else-if="summaryData.length === 0" class="empty-state">
        <p class="empty-state-text">{{ $t('reports.noData') }}</p>
      </div>
      <div v-else class="table-container">
        <table class="table">
          <thead class="table-header">
            <tr>
              <th class="table-th">{{ groupBy === 'project' ? $t('reports.project') : groupBy === 'client' ? $t('reports.client') : groupBy === 'user' ? $t('reports.user') : $t('reports.period') }}</th>
              <th class="table-th">{{ $t('reports.totalHours') }}</th>
              <th class="table-th">{{ $t('reports.billableHours') }}</th>
              <th class="table-th">{{ $t('reports.entries') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, i) in summaryData" :key="i" class="table-row">
              <td class="table-td">
                <div class="report-name-cell">
                  <span v-if="row.color" class="color-dot" :style="{ backgroundColor: row.color }"></span>
                  {{ row.project_name || row.client_name || row.user_name || row.period }}
                </div>
              </td>
              <td class="table-td table-td-mono">{{ row.total_hours }}h</td>
              <td class="table-td table-td-mono">{{ row.billable_hours }}h</td>
              <td class="table-td table-td-mono">{{ row.entry_count }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Budget Table -->
    <div v-if="reportType === 'budget'" class="card">
      <div v-if="loading" class="loading-center"><div class="loading-spinner"></div></div>
      <div v-else-if="budgetData.length === 0" class="empty-state">
        <p class="empty-state-text">{{ $t('reports.noBudgets') }}</p>
      </div>
      <div v-else class="table-container">
        <table class="table">
          <thead class="table-header">
            <tr>
              <th class="table-th">{{ $t('reports.project') }}</th>
              <th class="table-th">{{ $t('reports.client') }}</th>
              <th class="table-th">{{ $t('reports.budget') }}</th>
              <th class="table-th">{{ $t('reports.total') }}</th>
              <th class="table-th">{{ $t('reports.remaining') }}</th>
              <th class="table-th">{{ $t('reports.hourlyRate') }}</th>
              <th class="table-th">{{ $t('reports.revenue') }}</th>
              <th class="table-th">{{ $t('reports.progress') }}</th>
              <th class="table-th">{{ $t('reports.status') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in budgetData" :key="row.id" class="table-row">
              <td class="table-td">
                <div class="report-name-cell">
                  <span class="color-dot" :style="{ backgroundColor: row.color }"></span>
                  {{ row.project_name }}
                </div>
              </td>
              <td class="table-td">{{ row.client_name }}</td>
              <td class="table-td table-td-mono">{{ row.budget_hours }}h</td>
              <td class="table-td table-td-mono">{{ row.tracked_hours }}h</td>
              <td class="table-td table-td-mono">{{ row.remaining_hours }}h</td>
              <td class="table-td table-td-mono">{{ row.hourly_rate ? formatCurrency(row.hourly_rate) : '–' }}</td>
              <td class="table-td table-td-mono">{{ row.revenue ? formatCurrency(row.revenue) : '–' }}</td>
              <td class="table-td">
                <div class="budget-bar-inline">
                  <div class="budget-bar-bg-sm">
                    <div
                      class="budget-bar-fill-sm"
                      :class="{
                        'budget-bar-ok': row.status === 'on_track',
                        'budget-bar-warn': row.status === 'at_risk',
                        'budget-bar-over': row.status === 'over_budget',
                      }"
                      :style="{ width: Math.min(row.budget_used_percentage, 100) + '%' }"
                    ></div>
                  </div>
                  <span class="budget-bar-pct">{{ row.budget_used_percentage }}%</span>
                </div>
              </td>
              <td class="table-td">
                <span :class="{ 'badge-green': row.status === 'on_track', 'badge-yellow': row.status === 'at_risk', 'badge-red': row.status === 'over_budget' }">
                  {{ row.status === 'on_track' ? $t('reports.onTrack') : row.status === 'at_risk' ? $t('reports.atRisk') : $t('reports.overBudget') }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Utilization Table -->
    <div v-if="reportType === 'utilization'" class="card">
      <div v-if="loading" class="loading-center"><div class="loading-spinner"></div></div>
      <div v-else class="table-container">
        <table class="table">
          <thead class="table-header">
            <tr>
              <th class="table-th">{{ $t('reports.teamMember') }}</th>
              <th class="table-th">{{ $t('reports.totalHours') }}</th>
              <th class="table-th">{{ $t('reports.billable') }}</th>
              <th class="table-th">{{ $t('reports.nonBillable') }}</th>
              <th class="table-th">{{ $t('reports.billablePercent') }}</th>
              <th class="table-th">{{ $t('reports.daysTracked') }}</th>
              <th class="table-th">{{ $t('reports.avgPerDay') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in utilizationData" :key="row.id" class="table-row">
              <td class="table-td">{{ row.name }}</td>
              <td class="table-td table-td-mono">{{ row.total_hours }}h</td>
              <td class="table-td table-td-mono">{{ row.billable_hours }}h</td>
              <td class="table-td table-td-mono">{{ row.non_billable_hours }}h</td>
              <td class="table-td table-td-mono">{{ row.billable_percentage }}%</td>
              <td class="table-td table-td-mono">{{ row.days_tracked }}</td>
              <td class="table-td table-td-mono">{{ row.avg_hours_per_day }}h</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../assets/main.css";
.page-header {
  @apply flex items-center justify-between mb-6;
}

.header-actions {
  @apply flex gap-2;
}

.btn-icon-sm {
  @apply h-4 w-4;
}

.report-tabs {
  @apply flex gap-1 mb-6 bg-gray-100 rounded-lg p-1 w-fit;
}

.report-tab {
  @apply px-4 py-2 rounded-md text-sm font-medium text-gray-600 transition-colors hover:text-gray-900;
}

.report-tab-active {
  @apply px-4 py-2 rounded-md text-sm font-medium bg-white text-gray-900 shadow-sm;
}

.filters-section {
  @apply mb-6 space-y-3;
}

.date-ranges {
  @apply flex flex-wrap gap-2;
}

.range-btn {
  @apply px-3 py-1.5 rounded-md text-sm font-medium text-gray-600 border border-gray-200 bg-white hover:bg-gray-50 transition-colors;
}

.range-btn-active {
  @apply px-3 py-1.5 rounded-md text-sm font-medium text-primary-700 border border-primary-300 bg-primary-50;
}

.filters-bar {
  @apply grid grid-cols-2 sm:grid-cols-4 gap-4;
}

.totals-bar {
  @apply grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-6;
}

.total-item {
  @apply bg-white rounded-lg border border-gray-200 shadow-sm p-3 flex flex-col;
}

.total-label {
  @apply text-xs text-gray-500;
}

.total-value {
  @apply text-lg font-bold text-gray-900 tabular-nums;
}

.total-value-green {
  @apply text-green-600;
}

.loading-center {
  @apply flex justify-center py-12;
}

.report-name-cell {
  @apply flex items-center gap-2;
}

.table-td-mono {
  @apply font-mono text-sm tabular-nums;
}

/* Timesheet */
.ts-col-date {
  @apply w-32;
}

.date-group-row {
  @apply bg-primary-50;
}

.date-group-label {
  @apply px-6 py-2 text-sm font-semibold text-primary-800;
}

.date-group-total {
  @apply px-6 py-2 text-sm font-bold text-primary-800 tabular-nums font-mono;
}

.entry-row {
  @apply hover:bg-gray-50;
}

.entry-description {
  @apply max-w-xs truncate;
}

.project-cell {
  @apply flex items-center gap-1.5;
}

.billable-cell {
  @apply text-center;
}

.bill-dot {
  @apply text-xs py-0;
}

.ts-pagination {
  @apply flex items-center justify-between px-6 py-3 border-t border-gray-200;
}

.ts-pagination-btns {
  @apply flex gap-2;
}

.rounding-note {
  @apply text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-1.5 mb-3;
}

/* Budget */
.budget-bar-inline {
  @apply flex items-center gap-2;
}

.budget-bar-bg-sm {
  @apply w-20 h-2 rounded-full bg-gray-200 overflow-hidden;
}

.budget-bar-fill-sm {
  @apply h-full rounded-full transition-all;
}

.budget-bar-ok {
  @apply bg-green-500;
}

.budget-bar-warn {
  @apply bg-yellow-500;
}

.budget-bar-over {
  @apply bg-red-500;
}

.budget-bar-pct {
  @apply text-xs text-gray-500 tabular-nums;
}

/* Charts */
.chart-card {
  @apply mb-6;
}

.chart-grid {
  @apply grid grid-cols-1 lg:grid-cols-3 gap-8;
}

.chart-section {
  @apply space-y-3;
}

.chart-heading {
  @apply text-sm font-semibold text-gray-700;
}

.chart-bars {
  @apply space-y-2;
}

.chart-row {
  @apply flex items-center gap-3;
}

.chart-label {
  @apply flex items-center gap-1.5 w-28 shrink-0;
}

.chart-label-text {
  @apply text-xs text-gray-700 truncate;
}

.chart-bar-track {
  @apply flex flex-1 h-5 rounded-sm overflow-hidden bg-gray-100;
}

.chart-bar-fill {
  @apply h-full transition-all;
}

.chart-bar-billable {
  @apply bg-green-500;
}

.chart-bar-nonbillable {
  @apply bg-gray-300;
}

.chart-hours {
  @apply text-xs font-medium text-gray-600 tabular-nums w-16 text-right shrink-0;
}

.chart-legend {
  @apply flex gap-4 mt-6 pt-4 border-t border-gray-100;
}

.chart-legend-item {
  @apply flex items-center gap-1.5 text-xs text-gray-500;
}

.chart-legend-dot {
  @apply inline-block h-2.5 w-2.5 rounded-sm;
}

.chart-legend-billable {
  @apply bg-green-500;
}

.chart-legend-nonbillable {
  @apply bg-gray-300;
}
</style>
