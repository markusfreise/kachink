<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import { useTimerStore } from '@/stores/timer'
import { useAuthStore } from '@/stores/auth'
import { useToastStore, errorMessage } from '@/stores/toast'
import type { TimeEntry, Project, Task } from '@/types'
import TimerWidget from '@/components/TimerWidget.vue'
import {
  toDateString,
  formatDuration,
  formatHoursDecimal,
  formatDateLong,
  formatDate,
} from '@/utils/format'
import { ClockIcon, PlayIcon } from '@heroicons/vue/24/outline'

const { t } = useI18n()
const timer = useTimerStore()
const auth = useAuthStore()
const toast = useToastStore()

const todayEntries = ref<TimeEntry[]>([])
const weekEntries = ref<TimeEntry[]>([])
const projects = ref<Project[]>([])
const tasks = ref<Task[]>([])
const loading = ref(true)
const restartingId = ref<string | null>(null)

interface ProjectShare {
  id: string
  name: string
  client: string | null
  color: string
  seconds: number
  percent: number
}

interface WeekDay {
  date: string
  label: string
  seconds: number
  percent: number
  isToday: boolean
  isFuture: boolean
}

const RECENT_LIMIT = 10

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 12) return t('dashboard.morning')
  if (hour < 18) return t('dashboard.afternoon')
  return t('dashboard.evening')
})

const firstName = computed(() => auth.user?.name?.split(' ')[0] ?? '')
const todayLong = computed(() => formatDateLong(new Date().toISOString()))

/** Seconds of an entry, using the live elapsed value for the running entry so the tiles tick. */
function entrySeconds(entry: TimeEntry): number {
  if (entry.is_running && timer.runningEntry?.id === entry.id) return timer.elapsed
  return entry.duration_seconds || 0
}

function sumSeconds(entries: TimeEntry[]): number {
  return entries.reduce((sum, e) => sum + entrySeconds(e), 0)
}

const todaySeconds = computed(() => sumSeconds(todayEntries.value))
const todayBillableSeconds = computed(() => sumSeconds(todayEntries.value.filter((e) => e.is_billable)))
const weekSeconds = computed(() => sumSeconds(weekEntries.value))

const billableShare = computed(() =>
  todaySeconds.value > 0 ? Math.round((todayBillableSeconds.value / todaySeconds.value) * 100) : 0
)

const weekDaysTracked = computed(() => {
  const days = new Set<string>()
  weekEntries.value.forEach((e) => days.add(toDateString(new Date(e.started_at))))
  return days.size
})

const weekAverageSeconds = computed(() =>
  weekDaysTracked.value > 0 ? Math.round(weekSeconds.value / weekDaysTracked.value) : 0
)

const todayByProject = computed<ProjectShare[]>(() => {
  const map = new Map<string, ProjectShare>()
  todayEntries.value.forEach((e) => {
    if (!e.project) return
    const row = map.get(e.project_id) ?? {
      id: e.project_id,
      name: e.project.name,
      client: e.project.client?.name ?? null,
      color: e.project.color,
      seconds: 0,
      percent: 0,
    }
    row.seconds += entrySeconds(e)
    map.set(e.project_id, row)
  })
  const rows = Array.from(map.values()).sort((a, b) => b.seconds - a.seconds)
  const max = rows[0]?.seconds ?? 0
  rows.forEach((row) => {
    row.percent = max > 0 ? Math.round((row.seconds / max) * 100) : 0
  })
  return rows
})

const projectsTodayCount = computed(() => todayByProject.value.length)

/** Monday..Sunday of the current week with tracked seconds per day. */
const weekDays = computed<WeekDay[]>(() => {
  const now = new Date()
  const todayStr = toDateString(now)
  const monday = new Date(now)
  monday.setHours(0, 0, 0, 0)
  monday.setDate(now.getDate() - ((now.getDay() + 6) % 7))

  const perDay = new Map<string, number>()
  weekEntries.value.forEach((e) => {
    const key = toDateString(new Date(e.started_at))
    perDay.set(key, (perDay.get(key) ?? 0) + entrySeconds(e))
  })

  const days: WeekDay[] = []
  for (let i = 0; i < 7; i++) {
    const d = new Date(monday)
    d.setDate(monday.getDate() + i)
    const date = toDateString(d)
    days.push({
      date,
      // Weekday only: override the numeric defaults of formatDate.
      label: formatDate(d.toISOString(), { weekday: 'short', day: undefined, month: undefined, year: undefined }),
      seconds: perDay.get(date) ?? 0,
      percent: 0,
      isToday: date === todayStr,
      isFuture: date > todayStr,
    })
  }
  const max = Math.max(0, ...days.map((d) => d.seconds))
  days.forEach((d) => {
    d.percent = max > 0 ? Math.round((d.seconds / max) * 100) : 0
  })
  return days
})

const recentEntries = computed(() => todayEntries.value.slice(0, RECENT_LIMIT))

function entryDuration(entry: TimeEntry): string {
  if (entry.is_running && timer.runningEntry?.id === entry.id) return timer.elapsedFormatted
  return formatDuration(entry.duration_seconds)
}

async function load() {
  const now = new Date()
  const today = toDateString(now)
  const weekStart = new Date(now)
  weekStart.setDate(now.getDate() - ((now.getDay() + 6) % 7))
  const weekStartStr = toDateString(weekStart)

  try {
    const [todayRes, weekRes, projectsRes, tasksRes] = await Promise.all([
      api.get('/time-entries', {
        params: { 'filter[date_from]': today, 'filter[date_to]': today, per_page: 100, sort: '-started_at' },
      }),
      api.get('/time-entries', {
        params: { 'filter[date_from]': weekStartStr, 'filter[date_to]': today, per_page: 200 },
      }),
      api.get('/projects', {
        params: { 'filter[is_active]': true, per_page: 100 },
      }),
      api.get('/tasks'),
    ])
    todayEntries.value = todayRes.data.data
    weekEntries.value = weekRes.data.data
    projects.value = projectsRes.data.data
    tasks.value = tasksRes.data.data
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
  }
}

async function restart(entry: TimeEntry) {
  if (restartingId.value) return
  restartingId.value = entry.id
  try {
    await timer.start(entry.project_id, entry.task_id || undefined, entry.description || undefined, entry.is_billable)
    await load()
  } catch (e) {
    toast.error(errorMessage(e, t('dashboard.restartFailed')))
  } finally {
    restartingId.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="page dashboard">
    <header class="page__header">
      <div>
        <h1 class="heading-1">{{ greeting }}, {{ firstName }}</h1>
        <p class="page__subtitle">{{ todayLong }}</p>
      </div>
    </header>

    <TimerWidget :projects="projects" :tasks="tasks" class="dashboard__timer" @changed="load" />

    <div v-if="loading" class="loading" role="status">
      <span class="spinner"></span>
      <span class="sr-only">{{ $t('dashboard.loading') }}</span>
    </div>

    <template v-else>
      <div class="grid grid--4 dashboard__stats">
        <div class="stat">
          <span class="stat__label">{{ $t('dashboard.today') }}</span>
          <span class="stat__value">{{ formatDuration(todaySeconds) }}</span>
          <span class="stat__sub">{{ $t('dashboard.decimalHours', { hours: formatHoursDecimal(todaySeconds) }) }}</span>
        </div>
        <div class="stat">
          <span class="stat__label">{{ $t('dashboard.billableToday') }}</span>
          <span class="stat__value stat__value--accent">{{ formatDuration(todayBillableSeconds) }}</span>
          <span class="stat__sub">
            {{ todayBillableSeconds > 0 ? $t('dashboard.billableShare', { percent: billableShare }) : $t('dashboard.nothingBillable') }}
          </span>
        </div>
        <div class="stat">
          <span class="stat__label">{{ $t('dashboard.thisWeek') }}</span>
          <span class="stat__value">{{ formatDuration(weekSeconds) }}</span>
          <span class="stat__sub">
            {{ weekDaysTracked > 0 ? $t('dashboard.weekAverage', { hours: formatDuration(weekAverageSeconds) }) : $t('dashboard.noWeekEntries') }}
          </span>
        </div>
        <div class="stat">
          <span class="stat__label">{{ $t('dashboard.entriesToday') }}</span>
          <span class="stat__value">{{ todayEntries.length }}</span>
          <span class="stat__sub">{{ $t('dashboard.projectsToday', { count: projectsTodayCount }, projectsTodayCount) }}</span>
        </div>
      </div>

      <div class="dashboard__columns">
        <section class="card dashboard__projects" :aria-label="$t('dashboard.todayByProject')">
          <div class="card__header">
            <h2 class="card__title">{{ $t('dashboard.todayByProject') }}</h2>
            <span class="table__time">{{ formatDuration(todaySeconds) }}</span>
          </div>
          <div v-if="todayByProject.length === 0" class="empty">
            <ClockIcon class="empty__icon" aria-hidden="true" />
            <p class="empty__title">{{ $t('dashboard.noProjectsToday') }}</p>
            <p class="empty__text">{{ $t('dashboard.noProjectsTodayHint') }}</p>
          </div>
          <ul v-else class="card__body dashboard__project-list">
            <li v-for="row in todayByProject" :key="row.id" class="dashboard__project">
              <div class="dashboard__project-head">
                <span class="color-dot" :style="{ backgroundColor: row.color }"></span>
                <span class="dashboard__project-name">{{ row.name }}</span>
                <span v-if="row.client" class="dashboard__project-client">{{ row.client }}</span>
                <span class="dashboard__project-hours">{{ formatDuration(row.seconds) }}</span>
              </div>
              <span class="bar bar--block">
                <span class="bar__fill" :style="{ width: row.percent + '%', backgroundColor: row.color }"></span>
              </span>
            </li>
          </ul>
        </section>

        <section class="card dashboard__week" :aria-label="$t('dashboard.thisWeek')">
          <div class="card__header">
            <h2 class="card__title">{{ $t('dashboard.thisWeek') }}</h2>
            <span class="table__time">{{ $t('dashboard.weekTotal', { hours: formatDuration(weekSeconds) }) }}</span>
          </div>
          <div class="card__body">
            <ol class="dashboard__days">
              <li
                v-for="day in weekDays"
                :key="day.date"
                class="dashboard__day"
                :class="{ 'dashboard__day--today': day.isToday, 'dashboard__day--future': day.isFuture }"
              >
                <span class="dashboard__day-hours">{{ day.seconds > 0 ? formatDuration(day.seconds) : '' }}</span>
                <span class="dashboard__day-bar" aria-hidden="true">
                  <span class="dashboard__day-fill" :style="{ height: day.percent + '%' }"></span>
                </span>
                <span class="dashboard__day-label">{{ day.label }}</span>
              </li>
            </ol>
          </div>
        </section>
      </div>

      <section class="card dashboard__recent" :aria-label="$t('dashboard.recentEntries')">
        <div class="card__header">
          <h2 class="card__title">{{ $t('dashboard.recentEntries') }}</h2>
          <span class="toolbar__count">{{ $t('common.entries', { count: todayEntries.length }) }}</span>
        </div>

        <div v-if="recentEntries.length === 0" class="empty">
          <ClockIcon class="empty__icon" aria-hidden="true" />
          <p class="empty__text">{{ $t('dashboard.noEntries') }}</p>
        </div>

        <div v-else class="table-wrap">
          <table class="table table--compact dashboard__table">
            <thead>
              <tr>
                <th>{{ $t('dashboard.project') }}</th>
                <th class="dashboard__col-task">{{ $t('dashboard.task') }}</th>
                <th class="dashboard__col-desc">{{ $t('dashboard.description') }}</th>
                <th class="dashboard__col-billable">{{ $t('common.billable') }}</th>
                <th class="table__num">{{ $t('dashboard.duration') }}</th>
                <th><span class="sr-only">{{ $t('common.actions') }}</span></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="entry in recentEntries"
                :key="entry.id"
                class="table__row"
                :class="{ 'dashboard__row--running': entry.is_running }"
              >
                <td>
                  <div class="cell">
                    <span class="color-dot" :style="{ backgroundColor: entry.project?.color }"></span>
                    <div class="cell__stack">
                      <span class="cell__title">{{ entry.project?.name }}</span>
                      <span v-if="entry.project?.client" class="cell__sub">{{ entry.project.client.name }}</span>
                    </div>
                  </div>
                </td>
                <td class="dashboard__col-task" :class="{ 'table__muted': !entry.task }">
                  {{ entry.task?.name || '-' }}
                </td>
                <td class="table__truncate dashboard__col-desc" :class="{ 'table__muted': !entry.description }" :title="entry.description || undefined">
                  {{ entry.description || '-' }}
                </td>
                <td class="dashboard__col-billable">
                  <span class="badge" :class="entry.is_billable ? 'badge--success' : 'badge--neutral'">
                    {{ entry.is_billable ? $t('common.yes') : $t('common.no') }}
                  </span>
                </td>
                <td class="table__num" :class="{ 'dashboard__duration--running': entry.is_running }">
                  {{ entryDuration(entry) }}
                </td>
                <td>
                  <div class="table__actions">
                    <button
                      type="button"
                      class="btn btn--ghost btn--icon btn--sm"
                      :aria-label="$t('dashboard.restart')"
                      :title="$t('dashboard.restart')"
                      :disabled="entry.is_running || restartingId !== null"
                      @click="restart(entry)"
                    >
                      <PlayIcon class="btn__icon" aria-hidden="true" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>
