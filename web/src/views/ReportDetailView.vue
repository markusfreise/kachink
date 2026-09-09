<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import { downloadFile } from '@/api/download'
import { useSettingsStore } from '@/stores/settings'
import { useAuthStore } from '@/stores/auth'
import type { ScopedReport, ReportScope, ReportGroupRow } from '@/types'
import { monthlyReportRange, shiftMonths, isFullMonth, type DateRange } from '@/composables/useReportPeriod'
import { formatDuration, formatHoursDecimal, formatCurrency, formatDateLong, formatMonth, formatDate } from '@/utils/format'
import {
  ArrowLeftIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  ArrowDownTrayIcon,
  DocumentTextIcon,
} from '@heroicons/vue/24/outline'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const settings = useSettingsStore()
const auth = useAuthStore()

type RouteScope = 'clients' | 'projects' | 'users'
const scopeMap: Record<RouteScope, ReportScope> = { clients: 'client', projects: 'project', users: 'user' }

const routeScope = computed(() => route.params.scope as RouteScope)
const scope = computed<ReportScope>(() => scopeMap[routeScope.value] ?? 'client')
const subjectId = computed(() => route.params.id as string)

const initial = monthlyReportRange()
const range = ref<DateRange>({
  from: (route.query.from as string) || initial.from,
  to: (route.query.to as string) || initial.to,
})
const rounding = ref<number>(settings.roundingInterval)
const roundingOptions = [0, 5, 10, 15, 30, 60]

const report = ref<ScopedReport | null>(null)
const loading = ref(false)
const error = ref('')
const downloading = ref<'pdf' | 'csv' | null>(null)

const periodIsMonth = computed(() => isFullMonth(range.value))
const periodLabel = computed(() =>
  periodIsMonth.value
    ? formatMonth(range.value.from)
    : `${formatDate(range.value.from + 'T00:00:00')} - ${formatDate(range.value.to + 'T00:00:00')}`
)

const scopeTitle = computed(() => t(`reportDetail.scope.${scope.value}`))

const showProjects = computed(() => scope.value !== 'project')
const showClients = computed(() => scope.value === 'user' && (report.value?.by_client.length ?? 0) > 1)
const showUsers = computed(() => auth.isAdmin && (report.value?.by_user.length ?? 0) > 0 && scope.value !== 'user')
const showTasks = computed(() => (report.value?.by_task ?? []).some((row) => row.name !== ''))
const showAmount = computed(() => (report.value?.totals.amount ?? 0) > 0)

function share(row: ReportGroupRow): number {
  const total = report.value?.totals.total_seconds ?? 0
  return total > 0 ? Math.round((row.total_seconds / total) * 100) : 0
}

function queryParams(): Record<string, string | number> {
  return {
    date_from: range.value.from,
    date_to: range.value.to,
    rounding: rounding.value,
    locale: locale.value,
  }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/reports/${routeScope.value}/${subjectId.value}`, { params: queryParams() })
    report.value = data.data
  } catch (e: unknown) {
    const err = e as { response?: { status?: number; data?: { message?: string } } }
    error.value = err.response?.status === 404 ? t('reportDetail.notFound') : (err.response?.data?.message || t('reportDetail.loadFailed'))
    report.value = null
  } finally {
    loading.value = false
  }
}

async function download(format: 'pdf' | 'csv') {
  downloading.value = format
  try {
    await downloadFile(`/reports/${routeScope.value}/${subjectId.value}`, { ...queryParams(), format })
  } catch {
    error.value = t('reportDetail.downloadFailed')
  } finally {
    downloading.value = null
  }
}

function step(delta: number) {
  range.value = shiftMonths(range.value, delta)
}

function setThisMonth() {
  const now = new Date()
  range.value = shiftMonths({ from: `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`, to: monthlyReportRange().to }, 0)
  // ensure a proper full month for the current month
  const y = now.getFullYear()
  const m = now.getMonth()
  const last = new Date(y, m + 1, 0)
  range.value = { from: `${y}-${String(m + 1).padStart(2, '0')}-01`, to: `${y}-${String(m + 1).padStart(2, '0')}-${String(last.getDate()).padStart(2, '0')}` }
}

function setMonthlyDefault() {
  range.value = monthlyReportRange()
}

watch(range, (r) => {
  router.replace({ query: { ...route.query, from: r.from, to: r.to } })
  load()
}, { deep: true })

watch(rounding, load)
watch(() => [route.params.scope, route.params.id], load)

onMounted(load)
</script>

<template>
  <div class="page-container">
    <div class="report-back">
      <button class="btn-ghost btn-sm" @click="router.back()">
        <ArrowLeftIcon class="icon-sm" />
        {{ $t('reportDetail.back') }}
      </button>
    </div>

    <div class="report-header">
      <div class="report-title-block">
        <span class="report-kicker">{{ scopeTitle }}</span>
        <h1 class="heading-1 report-title">
          <span v-if="report?.scope.color" class="color-dot report-dot" :style="{ backgroundColor: report.scope.color }"></span>
          {{ report?.scope.name ?? '' }}
        </h1>
        <span v-if="report?.scope.client_name" class="text-muted">{{ report.scope.client_name }}</span>
      </div>

      <div class="report-actions">
        <button class="btn-secondary" :disabled="loading || downloading !== null" @click="download('csv')">
          <ArrowDownTrayIcon class="icon-sm" />
          {{ downloading === 'csv' ? $t('reportDetail.preparing') : 'CSV' }}
        </button>
        <button class="btn-primary" :disabled="loading || downloading !== null" @click="download('pdf')">
          <DocumentTextIcon class="icon-sm" />
          {{ downloading === 'pdf' ? $t('reportDetail.preparing') : $t('reportDetail.downloadPdf') }}
        </button>
      </div>
    </div>

    <!-- Period toolbar -->
    <div class="report-toolbar">
      <div class="period-nav">
        <button class="btn-secondary btn-sm" :aria-label="$t('reportDetail.previousMonth')" @click="step(-1)">
          <ChevronLeftIcon class="icon-sm" />
        </button>
        <span class="period-label">{{ periodLabel }}</span>
        <button class="btn-secondary btn-sm" :aria-label="$t('reportDetail.nextMonth')" @click="step(1)">
          <ChevronRightIcon class="icon-sm" />
        </button>
        <button class="btn-ghost btn-sm" @click="setMonthlyDefault">{{ $t('reportDetail.monthlyDefault') }}</button>
        <button class="btn-ghost btn-sm" @click="setThisMonth">{{ $t('reports.thisMonth') }}</button>
      </div>

      <div class="period-custom">
        <label class="form-label" for="report-from">{{ $t('common.from') }}</label>
        <input id="report-from" v-model="range.from" type="date" class="form-input date-input" />
        <label class="form-label" for="report-to">{{ $t('common.to') }}</label>
        <input id="report-to" v-model="range.to" type="date" class="form-input date-input" :min="range.from" />
        <label class="form-label" for="report-rounding">{{ $t('reportDetail.rounding') }}</label>
        <select id="report-rounding" v-model="rounding" class="form-select rounding-select">
          <option v-for="r in roundingOptions" :key="r" :value="r">
            {{ r === 0 ? $t('reportDetail.noRounding') : $t('reportDetail.roundingMinutes', { minutes: r }) }}
          </option>
        </select>
      </div>
    </div>

    <div v-if="error" class="form-error-box">{{ error }}</div>

    <div v-if="loading && !report" class="loading-center">
      <div class="loading-spinner" role="status"></div>
    </div>

    <template v-else-if="report">
      <!-- Totals -->
      <div class="report-stats" :class="{ 'is-loading': loading }">
        <div class="stat">
          <span class="stat-label">{{ $t('reports.total') }}</span>
          <span class="stat-value">{{ formatDuration(report.totals.total_seconds) }} h</span>
          <span class="stat-sub">{{ formatHoursDecimal(report.totals.total_seconds) }} h</span>
        </div>
        <div class="stat">
          <span class="stat-label">{{ $t('reports.billable') }}</span>
          <span class="stat-value stat-value-billable">{{ formatDuration(report.totals.billable_seconds) }} h</span>
          <span class="stat-sub">{{ formatHoursDecimal(report.totals.billable_seconds) }} h</span>
        </div>
        <div class="stat">
          <span class="stat-label">{{ $t('reports.nonBillable') }}</span>
          <span class="stat-value">{{ formatDuration(report.totals.non_billable_seconds) }} h</span>
        </div>
        <div class="stat">
          <span class="stat-label">{{ $t('reports.entries') }}</span>
          <span class="stat-value">{{ report.totals.entry_count }}</span>
          <span class="stat-sub">{{ $t('reportDetail.daysTracked', { count: report.totals.days_tracked }) }}</span>
        </div>
        <div v-if="showAmount" class="stat">
          <span class="stat-label">{{ $t('reports.billableAmount') }}</span>
          <span class="stat-value">{{ formatCurrency(report.totals.amount) }}</span>
        </div>
      </div>
      <p v-if="report.rounding_minutes > 0" class="text-muted report-note">
        {{ $t('reports.roundingNote', { minutes: report.rounding_minutes }) }}
      </p>

      <div v-if="report.totals.entry_count === 0" class="card">
        <div class="empty-state">
          <p class="empty-state-text">{{ $t('reports.noEntries') }}</p>
        </div>
      </div>

      <template v-else>
        <div class="report-groups">
          <!-- By client -->
          <div v-if="showClients" class="card">
            <div class="card-header"><h2 class="heading-3">{{ $t('reports.byClient') }}</h2></div>
            <table class="table group-table">
              <thead class="table-header">
                <tr>
                  <th class="table-th">{{ $t('reports.client') }}</th>
                  <th class="table-th th-num">{{ $t('reportDetail.share') }}</th>
                  <th class="table-th th-num">{{ $t('reports.totalHours') }}</th>
                  <th class="table-th th-num">{{ $t('reports.billable') }}</th>
                  <th v-if="showAmount" class="table-th th-num">{{ $t('reports.billableAmount') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in report.by_client" :key="row.id ?? 'none'" class="table-row">
                  <td class="table-td">
                    <span v-if="row.color" class="color-dot" :style="{ backgroundColor: row.color }"></span>
                    {{ row.name }}
                  </td>
                  <td class="table-td td-num"><span class="share-bar"><span class="share-fill" :style="{ width: share(row) + '%' }"></span></span>{{ share(row) }}%</td>
                  <td class="table-td td-num">{{ formatDuration(row.total_seconds) }}</td>
                  <td class="table-td td-num">{{ formatDuration(row.billable_seconds) }}</td>
                  <td v-if="showAmount" class="table-td td-num">{{ formatCurrency(row.amount) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- By project -->
          <div v-if="showProjects" class="card">
            <div class="card-header"><h2 class="heading-3">{{ $t('reports.byProject') }}</h2></div>
            <table class="table group-table">
              <thead class="table-header">
                <tr>
                  <th class="table-th">{{ $t('reports.project') }}</th>
                  <th v-if="scope !== 'client'" class="table-th">{{ $t('reports.client') }}</th>
                  <th class="table-th th-num">{{ $t('reportDetail.share') }}</th>
                  <th class="table-th th-num">{{ $t('reports.totalHours') }}</th>
                  <th class="table-th th-num">{{ $t('reports.billable') }}</th>
                  <th v-if="showAmount" class="table-th th-num">{{ $t('reports.billableAmount') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in report.by_project" :key="row.id ?? 'none'" class="table-row">
                  <td class="table-td">
                    <RouterLink :to="{ name: 'report-detail', params: { scope: 'projects', id: row.id }, query: { from: range.from, to: range.to } }" class="row-link">
                      <span v-if="row.color" class="color-dot" :style="{ backgroundColor: row.color }"></span>
                      {{ row.name }}
                    </RouterLink>
                  </td>
                  <td v-if="scope !== 'client'" class="table-td text-muted">{{ row.subtitle }}</td>
                  <td class="table-td td-num"><span class="share-bar"><span class="share-fill" :style="{ width: share(row) + '%' }"></span></span>{{ share(row) }}%</td>
                  <td class="table-td td-num">{{ formatDuration(row.total_seconds) }}</td>
                  <td class="table-td td-num">{{ formatDuration(row.billable_seconds) }}</td>
                  <td v-if="showAmount" class="table-td td-num">{{ formatCurrency(row.amount) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- By task -->
          <div v-if="showTasks" class="card">
            <div class="card-header"><h2 class="heading-3">{{ $t('reportDetail.byTask') }}</h2></div>
            <table class="table group-table">
              <thead class="table-header">
                <tr>
                  <th class="table-th">{{ $t('reports.printTask') }}</th>
                  <th class="table-th th-num">{{ $t('reportDetail.share') }}</th>
                  <th class="table-th th-num">{{ $t('reports.totalHours') }}</th>
                  <th class="table-th th-num">{{ $t('reports.billable') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in report.by_task" :key="row.id ?? 'none'" class="table-row">
                  <td class="table-td">{{ row.name || '-' }}</td>
                  <td class="table-td td-num"><span class="share-bar"><span class="share-fill" :style="{ width: share(row) + '%' }"></span></span>{{ share(row) }}%</td>
                  <td class="table-td td-num">{{ formatDuration(row.total_seconds) }}</td>
                  <td class="table-td td-num">{{ formatDuration(row.billable_seconds) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- By team member -->
          <div v-if="showUsers" class="card">
            <div class="card-header"><h2 class="heading-3">{{ $t('reports.byTeamMember') }}</h2></div>
            <table class="table group-table">
              <thead class="table-header">
                <tr>
                  <th class="table-th">{{ $t('reports.teamMember') }}</th>
                  <th class="table-th th-num">{{ $t('reportDetail.share') }}</th>
                  <th class="table-th th-num">{{ $t('reports.totalHours') }}</th>
                  <th class="table-th th-num">{{ $t('reports.billable') }}</th>
                  <th class="table-th th-num">{{ $t('reports.entries') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in report.by_user" :key="row.id ?? 'none'" class="table-row">
                  <td class="table-td">
                    <RouterLink :to="{ name: 'report-detail', params: { scope: 'users', id: row.id }, query: { from: range.from, to: range.to } }" class="row-link">{{ row.name }}</RouterLink>
                  </td>
                  <td class="table-td td-num"><span class="share-bar"><span class="share-fill" :style="{ width: share(row) + '%' }"></span></span>{{ share(row) }}%</td>
                  <td class="table-td td-num">{{ formatDuration(row.total_seconds) }}</td>
                  <td class="table-td td-num">{{ formatDuration(row.billable_seconds) }}</td>
                  <td class="table-td td-num">{{ row.entry_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Detail -->
        <div class="card report-detail">
          <div class="card-header"><h2 class="heading-3">{{ $t('reportDetail.detail') }}</h2></div>
          <div class="table-container">
            <table class="table">
              <thead class="table-header">
                <tr>
                  <th class="table-th th-time">{{ $t('reportDetail.time') }}</th>
                  <th v-if="auth.isAdmin && scope !== 'user'" class="table-th">{{ $t('reports.teamMember') }}</th>
                  <th v-if="showProjects" class="table-th">{{ $t('reports.project') }}</th>
                  <th class="table-th">{{ $t('reports.printTask') }}</th>
                  <th class="table-th">{{ $t('timeEntries.description') }}</th>
                  <th class="table-th th-num">{{ $t('reports.printDuration') }}</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="day in report.days" :key="day.date">
                  <tr class="day-row">
                    <td class="table-td" :colspan="3 + (auth.isAdmin && scope !== 'user' ? 1 : 0) + (showProjects ? 1 : 0)">{{ formatDateLong(day.date + 'T00:00:00') }}</td>
                    <td class="table-td td-num">{{ formatDuration(day.total_seconds) }}</td>
                  </tr>
                  <tr v-for="e in day.entries" :key="e.id" class="table-row">
                    <td class="table-td td-time">{{ e.start_time }}<template v-if="e.end_time"> - {{ e.end_time }}</template></td>
                    <td v-if="auth.isAdmin && scope !== 'user'" class="table-td">{{ e.user_name }}</td>
                    <td v-if="showProjects" class="table-td">
                      <span v-if="e.project_color" class="color-dot" :style="{ backgroundColor: e.project_color }"></span>
                      <span v-if="scope !== 'client' && e.client_name" class="text-muted">{{ e.client_name }} / </span>{{ e.project_name }}
                    </td>
                    <td class="table-td">{{ e.task_name || '-' }}</td>
                    <td class="table-td td-desc">
                      {{ e.description }}
                      <span v-if="!e.is_billable" class="badge-gray">{{ $t('common.nonBillable') }}</span>
                    </td>
                    <td class="table-td td-num">{{ formatDuration(e.rounded_seconds) }}</td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>

<style scoped>
@reference "../assets/main.css";

.report-back { @apply mb-2; }
.report-header { @apply flex flex-wrap items-start justify-between gap-4 mb-5; }
.report-title-block { @apply flex flex-col gap-1; }
.report-kicker { @apply text-xs font-semibold uppercase tracking-wider text-gray-500; }
.report-title { @apply flex items-center gap-2; }
.report-dot { @apply h-3 w-3; }
.report-actions { @apply flex items-center gap-2; }

.report-toolbar { @apply flex flex-wrap items-center justify-between gap-3 mb-5; }
.period-nav { @apply flex items-center gap-2; }
.period-label { @apply min-w-40 text-center text-base font-semibold text-gray-900; }
.period-custom { @apply flex flex-wrap items-center gap-2; }
.date-input { @apply w-40; }
.rounding-select { @apply w-32; }

.report-stats { @apply grid grid-cols-2 gap-3 mb-2 sm:grid-cols-3 lg:grid-cols-5 transition-opacity; }
.report-stats.is-loading { @apply opacity-50; }
.stat { @apply bg-white rounded-lg border border-gray-200 shadow-sm px-5 py-4 flex flex-col; }
.stat-label { @apply text-xs font-medium uppercase tracking-wider text-gray-500; }
.stat-value { @apply text-2xl font-bold text-gray-900 tabular-nums; }
.stat-value-billable { @apply text-green-700; }
.stat-sub { @apply text-xs text-gray-500 tabular-nums; }
.report-note { @apply mb-5; }

.report-groups { @apply grid grid-cols-1 gap-5 mb-5 xl:grid-cols-2; }
.group-table { @apply w-full; }
.th-num, .td-num { @apply text-right tabular-nums whitespace-nowrap; }
.th-time { @apply w-32; }
.td-time { @apply text-gray-500 tabular-nums whitespace-nowrap; }
.td-desc { @apply text-gray-700; }
.row-link { @apply inline-flex items-center gap-2 hover:underline; }
.share-bar { @apply inline-block w-16 h-1.5 bg-gray-200 rounded-full mr-2 align-middle overflow-hidden; }
.share-fill { @apply block h-full bg-primary-500 rounded-full; }
.day-row td { @apply bg-gray-50 font-semibold text-gray-800; }
.icon-sm { @apply h-4 w-4; }
.loading-center { @apply flex justify-center py-12; }
.form-error-box { @apply rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 border border-red-200 mb-4; }
</style>
