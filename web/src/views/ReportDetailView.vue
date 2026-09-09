<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import { downloadFile } from '@/api/download'
import { useSettingsStore } from '@/stores/settings'
import { useAuthStore } from '@/stores/auth'
import { useToastStore, errorMessage } from '@/stores/toast'
import type { ScopedReport, ReportScope, ReportGroupRow } from '@/types'
import { monthlyReportRange, monthRange, shiftMonths, isFullMonth, type DateRange } from '@/composables/useReportPeriod'
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
const toast = useToastStore()

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
const showUserCol = computed(() => auth.isAdmin && scope.value !== 'user')
const dayColspan = computed(() => 3 + (showUserCol.value ? 1 : 0) + (showProjects.value ? 1 : 0))

type LinkScope = 'clients' | 'projects' | 'users'
function entityLink(target: LinkScope, id: string | null | undefined) {
  if (!id) return null
  if (target === 'users' && !auth.isAdmin) return null
  return { name: 'report-detail', params: { scope: target, id }, query: { from: range.value.from, to: range.value.to } }
}

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
    error.value = err.response?.status === 404 ? t('reportDetail.notFound') : errorMessage(e, t('reportDetail.loadFailed'))
    toast.error(error.value)
    report.value = null
  } finally {
    loading.value = false
  }
}

async function download(format: 'pdf' | 'csv') {
  downloading.value = format
  try {
    await downloadFile(`/reports/${routeScope.value}/${subjectId.value}`, { ...queryParams(), format })
  } catch (e) {
    toast.error(errorMessage(e, t('reportDetail.downloadFailed')))
  } finally {
    downloading.value = null
  }
}

function step(delta: number) {
  range.value = shiftMonths(range.value, delta)
}

function setThisMonth() {
  const now = new Date()
  range.value = monthRange(now.getFullYear(), now.getMonth())
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
  <div class="page report-detail">
    <div class="report-detail__back">
      <button type="button" class="btn btn--ghost btn--sm" @click="router.back()">
        <ArrowLeftIcon class="btn__icon" />
        {{ $t('reportDetail.back') }}
      </button>
    </div>

    <div class="page__header">
      <div class="report-detail__title-block">
        <span class="kicker">{{ scopeTitle }}</span>
        <h1 class="heading-1 report-detail__name">
          <span v-if="report?.scope.color" class="color-dot color-dot--lg" :style="{ backgroundColor: report.scope.color }"></span>
          {{ report?.scope.name ?? '' }}
        </h1>
        <span v-if="report?.scope.client_name" class="report-detail__sub">{{ report.scope.client_name }}</span>
      </div>

      <div class="page__actions report-detail__actions">
        <button type="button" class="btn btn--secondary" :disabled="loading || downloading !== null" @click="download('csv')">
          <ArrowDownTrayIcon class="btn__icon" />
          {{ downloading === 'csv' ? $t('reportDetail.preparing') : 'CSV' }}
        </button>
        <button type="button" class="btn btn--primary" :disabled="loading || downloading !== null" @click="download('pdf')">
          <DocumentTextIcon class="btn__icon" />
          {{ downloading === 'pdf' ? $t('reportDetail.preparing') : $t('reportDetail.downloadPdf') }}
        </button>
      </div>
    </div>

    <div class="toolbar report-detail__toolbar">
      <div class="toolbar__group report-detail__period">
        <button type="button" class="btn btn--secondary btn--sm btn--icon" :aria-label="$t('reportDetail.previousMonth')" @click="step(-1)">
          <ChevronLeftIcon class="btn__icon" />
        </button>
        <span class="report-detail__period-label">{{ periodLabel }}</span>
        <button type="button" class="btn btn--secondary btn--sm btn--icon" :aria-label="$t('reportDetail.nextMonth')" @click="step(1)">
          <ChevronRightIcon class="btn__icon" />
        </button>
        <button type="button" class="btn btn--ghost btn--sm" @click="setMonthlyDefault">{{ $t('reportDetail.monthlyDefault') }}</button>
        <button type="button" class="btn btn--ghost btn--sm" @click="setThisMonth">{{ $t('reports.thisMonth') }}</button>
      </div>

      <div class="toolbar__group report-detail__custom">
        <label class="toolbar__label" for="report-from">{{ $t('common.from') }}</label>
        <input id="report-from" v-model="range.from" type="date" class="form__input form__input--sm form__input--inline report-detail__date" :max="range.to" />
        <label class="toolbar__label" for="report-to">{{ $t('common.to') }}</label>
        <input id="report-to" v-model="range.to" type="date" class="form__input form__input--sm form__input--inline report-detail__date" :min="range.from" />
        <label class="toolbar__label" for="report-rounding">{{ $t('reportDetail.rounding') }}</label>
        <select id="report-rounding" v-model="rounding" class="form__select form__select--sm form__select--inline report-detail__rounding">
          <option v-for="r in roundingOptions" :key="r" :value="r">
            {{ r === 0 ? $t('reportDetail.noRounding') : $t('reportDetail.roundingMinutes', { minutes: r }) }}
          </option>
        </select>
      </div>
    </div>

    <div v-if="error" class="alert">{{ error }}</div>

    <div v-if="loading && !report" class="loading">
      <div class="spinner" role="status"></div>
    </div>

    <template v-else-if="report">
      <div class="grid grid--5 report-detail__stats" :class="{ 'is-loading': loading }">
        <div class="stat">
          <span class="stat__label">{{ $t('reports.total') }}</span>
          <span class="stat__value">{{ formatDuration(report.totals.total_seconds) }} h</span>
          <span class="stat__sub">{{ formatHoursDecimal(report.totals.total_seconds) }} h</span>
        </div>
        <div class="stat">
          <span class="stat__label">{{ $t('reports.billable') }}</span>
          <span class="stat__value stat__value--accent">{{ formatDuration(report.totals.billable_seconds) }} h</span>
          <span class="stat__sub">{{ formatHoursDecimal(report.totals.billable_seconds) }} h</span>
        </div>
        <div class="stat">
          <span class="stat__label">{{ $t('reports.nonBillable') }}</span>
          <span class="stat__value">{{ formatDuration(report.totals.non_billable_seconds) }} h</span>
          <span class="stat__sub">{{ formatHoursDecimal(report.totals.non_billable_seconds) }} h</span>
        </div>
        <div class="stat">
          <span class="stat__label">{{ $t('reports.entries') }}</span>
          <span class="stat__value">{{ report.totals.entry_count }}</span>
          <span class="stat__sub">{{ $t('reportDetail.daysTracked', { count: report.totals.days_tracked }) }}</span>
        </div>
        <div v-if="showAmount" class="stat">
          <span class="stat__label">{{ $t('reports.billableAmount') }}</span>
          <span class="stat__value">{{ formatCurrency(report.totals.amount) }}</span>
        </div>
      </div>
      <p v-if="report.rounding_minutes > 0" class="report-detail__note">
        {{ $t('reports.roundingNote', { minutes: report.rounding_minutes }) }}
      </p>

      <div v-if="report.totals.entry_count === 0" class="card">
        <div class="empty">
          <p class="empty__text">{{ $t('reports.noEntries') }}</p>
        </div>
      </div>

      <template v-else>
        <div class="grid grid--2 report-detail__groups" :class="{ 'is-loading': loading }">
          <!-- By client -->
          <div v-if="showClients" class="card">
            <div class="card__header"><h2 class="card__title">{{ $t('reports.byClient') }}</h2></div>
            <div class="table-wrap">
              <table class="table table--compact">
                <thead>
                  <tr>
                    <th>{{ $t('reports.client') }}</th>
                    <th class="table__num">{{ $t('reportDetail.share') }}</th>
                    <th class="table__num">{{ $t('reports.totalHours') }}</th>
                    <th class="table__num">{{ $t('reports.billable') }}</th>
                    <th v-if="showAmount" class="table__num">{{ $t('reports.billableAmount') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in report.by_client" :key="row.id ?? 'none'" class="table__row">
                    <td>
                      <component :is="entityLink('clients', row.id) ? 'RouterLink' : 'span'" :to="entityLink('clients', row.id) ?? undefined" class="cell" :class="{ 'report-detail__link': entityLink('clients', row.id) }">
                        <span v-if="row.color" class="color-dot" :style="{ backgroundColor: row.color }"></span>
                        <span class="cell__title">{{ row.name }}</span>
                      </component>
                    </td>
                    <td class="table__num">
                      <span class="share"><span class="bar"><span class="bar__fill" :style="{ width: share(row) + '%' }"></span></span>{{ share(row) }}%</span>
                    </td>
                    <td class="table__num">{{ formatDuration(row.total_seconds) }}</td>
                    <td class="table__num">{{ formatDuration(row.billable_seconds) }}</td>
                    <td v-if="showAmount" class="table__num">{{ formatCurrency(row.amount) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- By project -->
          <div v-if="showProjects" class="card">
            <div class="card__header"><h2 class="card__title">{{ $t('reports.byProject') }}</h2></div>
            <div class="table-wrap">
              <table class="table table--compact">
                <thead>
                  <tr>
                    <th>{{ $t('reports.project') }}</th>
                    <th v-if="scope !== 'client'">{{ $t('reports.client') }}</th>
                    <th class="table__num">{{ $t('reportDetail.share') }}</th>
                    <th class="table__num">{{ $t('reports.totalHours') }}</th>
                    <th class="table__num">{{ $t('reports.billable') }}</th>
                    <th v-if="showAmount" class="table__num">{{ $t('reports.billableAmount') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in report.by_project" :key="row.id ?? 'none'" class="table__row">
                    <td>
                      <RouterLink
                        :to="{ name: 'report-detail', params: { scope: 'projects', id: row.id }, query: { from: range.from, to: range.to } }"
                        class="cell report-detail__link"
                      >
                        <span v-if="row.color" class="color-dot" :style="{ backgroundColor: row.color }"></span>
                        <span class="cell__title">{{ row.name }}</span>
                      </RouterLink>
                    </td>
                    <td v-if="scope !== 'client'" class="table__muted">{{ row.subtitle }}</td>
                    <td class="table__num">
                      <span class="share"><span class="bar"><span class="bar__fill" :style="{ width: share(row) + '%' }"></span></span>{{ share(row) }}%</span>
                    </td>
                    <td class="table__num">{{ formatDuration(row.total_seconds) }}</td>
                    <td class="table__num">{{ formatDuration(row.billable_seconds) }}</td>
                    <td v-if="showAmount" class="table__num">{{ formatCurrency(row.amount) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- By task -->
          <div v-if="showTasks" class="card">
            <div class="card__header"><h2 class="card__title">{{ $t('reportDetail.byTask') }}</h2></div>
            <div class="table-wrap">
              <table class="table table--compact">
                <thead>
                  <tr>
                    <th>{{ $t('reports.printTask') }}</th>
                    <th class="table__num">{{ $t('reportDetail.share') }}</th>
                    <th class="table__num">{{ $t('reports.totalHours') }}</th>
                    <th class="table__num">{{ $t('reports.billable') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in report.by_task" :key="row.id ?? 'none'" class="table__row">
                    <td>{{ row.name || '-' }}</td>
                    <td class="table__num">
                      <span class="share"><span class="bar"><span class="bar__fill" :style="{ width: share(row) + '%' }"></span></span>{{ share(row) }}%</span>
                    </td>
                    <td class="table__num">{{ formatDuration(row.total_seconds) }}</td>
                    <td class="table__num">{{ formatDuration(row.billable_seconds) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- By team member -->
          <div v-if="showUsers" class="card">
            <div class="card__header"><h2 class="card__title">{{ $t('reports.byTeamMember') }}</h2></div>
            <div class="table-wrap">
              <table class="table table--compact">
                <thead>
                  <tr>
                    <th>{{ $t('reports.teamMember') }}</th>
                    <th class="table__num">{{ $t('reportDetail.share') }}</th>
                    <th class="table__num">{{ $t('reports.totalHours') }}</th>
                    <th class="table__num">{{ $t('reports.billable') }}</th>
                    <th class="table__num">{{ $t('reports.entries') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in report.by_user" :key="row.id ?? 'none'" class="table__row">
                    <td>
                      <RouterLink
                        :to="{ name: 'report-detail', params: { scope: 'users', id: row.id }, query: { from: range.from, to: range.to } }"
                        class="report-detail__link"
                      >
                        {{ row.name }}
                      </RouterLink>
                    </td>
                    <td class="table__num">
                      <span class="share"><span class="bar"><span class="bar__fill" :style="{ width: share(row) + '%' }"></span></span>{{ share(row) }}%</span>
                    </td>
                    <td class="table__num">{{ formatDuration(row.total_seconds) }}</td>
                    <td class="table__num">{{ formatDuration(row.billable_seconds) }}</td>
                    <td class="table__num">{{ row.entry_count }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Detail -->
        <div class="card report-detail__entries" :class="{ 'is-loading': loading }">
          <div class="card__header"><h2 class="card__title">{{ $t('reportDetail.detail') }}</h2></div>
          <div class="table-wrap">
            <table class="table report-detail__table">
              <thead>
                <tr>
                  <th class="report-detail__col-time">{{ $t('reportDetail.time') }}</th>
                  <th v-if="showUserCol">{{ $t('reports.teamMember') }}</th>
                  <th v-if="showProjects">{{ $t('reports.project') }}</th>
                  <th>{{ $t('reports.printTask') }}</th>
                  <th>{{ $t('timeEntries.description') }}</th>
                  <th class="table__num">{{ $t('reports.printDuration') }}</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="day in report.days" :key="day.date">
                  <tr class="table__group">
                    <td :colspan="dayColspan">{{ formatDateLong(day.date + 'T00:00:00') }}</td>
                    <td class="table__num">{{ formatDuration(day.total_seconds) }}</td>
                  </tr>
                  <tr v-for="e in day.entries" :key="e.id" class="table__row">
                    <td class="table__time">{{ e.start_time }}<template v-if="e.end_time"> - {{ e.end_time }}</template></td>
                    <td v-if="showUserCol" class="report-detail__col-user">
                      <component :is="entityLink('users', e.user_id) ? 'RouterLink' : 'span'" :to="entityLink('users', e.user_id) ?? undefined" :class="{ 'report-detail__link': entityLink('users', e.user_id) }">{{ e.user_name }}</component>
                    </td>
                    <td v-if="showProjects">
                      <span class="cell">
                        <span v-if="e.project_color" class="color-dot" :style="{ backgroundColor: e.project_color }"></span>
                        <span class="cell__title">
                          <template v-if="scope !== 'client' && e.client_name">
                            <RouterLink :to="entityLink('clients', e.client_id) ?? {}" class="table__muted report-detail__link">{{ e.client_name }}</RouterLink><span class="table__muted"> / </span>
                          </template>
                          <RouterLink :to="entityLink('projects', e.project_id) ?? {}" class="report-detail__link">{{ e.project_name }}</RouterLink>
                        </span>
                      </span>
                    </td>
                    <td class="table__muted">{{ e.task_name || '-' }}</td>
                    <td class="report-detail__desc">
                      {{ e.description || '-' }}
                      <span v-if="!e.is_billable" class="badge badge--neutral">{{ $t('common.nonBillable') }}</span>
                    </td>
                    <td class="table__num">{{ formatDuration(e.rounded_seconds) }}</td>
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
