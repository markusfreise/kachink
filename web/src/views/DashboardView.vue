<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import { useTimerStore } from '@/stores/timer'
import { useAuthStore } from '@/stores/auth'
import type { TimeEntry, Project, Task } from '@/types'
import TimerWidget from '@/components/TimerWidget.vue'
import {
  ClockIcon,
  CurrencyDollarIcon,
  FolderIcon,
  PlayIcon,
} from '@heroicons/vue/24/outline'

const { t } = useI18n()
const timer = useTimerStore()
const auth = useAuthStore()

const todayEntries = ref<TimeEntry[]>([])
const weekEntries = ref<TimeEntry[]>([])
const projects = ref<Project[]>([])
const tasks = ref<Task[]>([])
const loading = ref(true)

const todayHours = computed(() => {
  const total = todayEntries.value.reduce((sum, e) => sum + (e.duration_seconds || 0), 0)
  return (total / 3600).toFixed(1)
})

const todayBillableHours = computed(() => {
  const total = todayEntries.value
    .filter((e) => e.is_billable)
    .reduce((sum, e) => sum + (e.duration_seconds || 0), 0)
  return (total / 3600).toFixed(1)
})

const weekHours = computed(() => {
  const total = weekEntries.value.reduce((sum, e) => sum + (e.duration_seconds || 0), 0)
  return (total / 3600).toFixed(1)
})

const todayByProject = computed(() => {
  const map = new Map<string, { name: string; color: string; seconds: number }>()
  todayEntries.value.forEach((e) => {
    if (!e.project) return
    const existing = map.get(e.project_id) || {
      name: e.project.name,
      color: e.project.color,
      seconds: 0,
    }
    existing.seconds += e.duration_seconds || 0
    map.set(e.project_id, existing)
  })
  return Array.from(map.values()).sort((a, b) => b.seconds - a.seconds)
})

function formatHours(seconds: number): string {
  return (seconds / 3600).toFixed(1)
}

onMounted(async () => {
  const today = new Date().toISOString().split('T')[0]
  const weekStart = new Date()
  weekStart.setDate(weekStart.getDate() - weekStart.getDay() + 1)
  const weekStartStr = weekStart.toISOString().split('T')[0]

  try {
    const [todayRes, weekRes, projectsRes, tasksRes] = await Promise.all([
      api.get('/time-entries', {
        params: { 'filter[date_from]': today, 'filter[date_to]': today, per_page: 100 },
      }),
      api.get('/time-entries', {
        params: { 'filter[date_from]': weekStartStr, 'filter[date_to]': today, per_page: 200 },
      }),
      api.get('/projects', {
        params: { 'filter[is_active]': true, per_page: 100, include_time_summary: true },
      }),
      api.get('/tasks'),
    ])
    todayEntries.value = todayRes.data.data
    weekEntries.value = weekRes.data.data
    projects.value = projectsRes.data.data
    tasks.value = tasksRes.data.data
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="page-container">
    <h1 class="heading-1 dashboard-greeting">
      {{ new Date().getHours() < 12 ? $t('dashboard.morning') : new Date().getHours() < 18 ? $t('dashboard.afternoon') : $t('dashboard.evening') }},
      {{ auth.user?.name?.split(' ')[0] }}
    </h1>

    <!-- Timer widget -->
    <TimerWidget :projects="projects" :tasks="tasks" class="dashboard-timer" />

    <!-- Stats -->
    <div class="dashboard-stats">
      <div class="stat-card">
        <div class="stat-icon stat-icon-blue">
          <ClockIcon class="stat-icon-svg" />
        </div>
        <div class="stat-content">
          <span class="stat-label">{{ $t('dashboard.today') }}</span>
          <span class="stat-value">{{ todayHours }}h</span>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon stat-icon-green">
          <CurrencyDollarIcon class="stat-icon-svg" />
        </div>
        <div class="stat-content">
          <span class="stat-label">{{ $t('dashboard.billableToday') }}</span>
          <span class="stat-value">{{ todayBillableHours }}h</span>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon stat-icon-purple">
          <FolderIcon class="stat-icon-svg" />
        </div>
        <div class="stat-content">
          <span class="stat-label">{{ $t('dashboard.thisWeek') }}</span>
          <span class="stat-value">{{ weekHours }}h</span>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon stat-icon-orange">
          <PlayIcon class="stat-icon-svg" />
        </div>
        <div class="stat-content">
          <span class="stat-label">{{ $t('dashboard.entriesToday') }}</span>
          <span class="stat-value">{{ todayEntries.length }}</span>
        </div>
      </div>
    </div>

    <!-- Today by project -->
    <div v-if="todayByProject.length" class="card">
      <div class="card-header">
        <h2 class="heading-3">{{ $t('dashboard.todayByProject') }}</h2>
      </div>
      <div class="card-body">
        <div class="project-breakdown">
          <div v-for="row in todayByProject" :key="row.name" class="project-row">
            <div class="project-row-info">
              <span class="color-dot" :style="{ backgroundColor: row.color }"></span>
              <span class="project-row-name">{{ row.name }}</span>
            </div>
            <span class="project-row-hours">{{ formatHours(row.seconds) }}h</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Recent entries -->
    <div class="card dashboard-recent">
      <div class="card-header">
        <h2 class="heading-3">{{ $t('dashboard.recentEntries') }}</h2>
      </div>
      <div v-if="todayEntries.length === 0" class="empty-state">
        <ClockIcon class="empty-state-icon" />
        <p class="empty-state-text">{{ $t('dashboard.noEntries') }}</p>
      </div>
      <div v-else class="table-container">
        <table class="table">
          <thead class="table-header">
            <tr>
              <th class="table-th">{{ $t('dashboard.project') }}</th>
              <th class="table-th">{{ $t('dashboard.task') }}</th>
              <th class="table-th">{{ $t('dashboard.description') }}</th>
              <th class="table-th">{{ $t('dashboard.duration') }}</th>
              <th class="table-th">{{ $t('common.billable') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in todayEntries.slice(0, 10)" :key="entry.id" class="table-row">
              <td class="table-td">
                <div class="project-cell">
                  <span class="color-dot" :style="{ backgroundColor: entry.project?.color }"></span>
                  {{ entry.project?.name }}
                </div>
              </td>
              <td class="table-td">{{ entry.task?.name || '—' }}</td>
              <td class="table-td table-td-desc">{{ entry.description || '—' }}</td>
              <td class="table-td table-td-mono">
                <span :class="entry.is_running ? 'timer-running' : ''">
                  {{ entry.duration_human }}
                </span>
              </td>
              <td class="table-td">
                <span :class="entry.is_billable ? 'badge-green' : 'badge-gray'">
                  {{ entry.is_billable ? $t('common.yes') : $t('common.no') }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../assets/main.css";
.dashboard-greeting {
  @apply mb-6;
}

.dashboard-timer {
  @apply mb-6;
}

.dashboard-stats {
  @apply grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6;
}

.stat-card {
  @apply bg-white rounded-lg border border-gray-200 shadow-sm px-6 py-4 flex items-center gap-4;
}

.stat-icon {
  @apply flex h-10 w-10 items-center justify-center rounded-lg shrink-0;
}

.stat-icon-blue {
  @apply bg-primary-100;
}

.stat-icon-green {
  @apply bg-green-100;
}

.stat-icon-purple {
  @apply bg-purple-100;
}

.stat-icon-orange {
  @apply bg-orange-100;
}

.stat-icon-svg {
  @apply h-5 w-5 text-current;
}

.stat-content {
  @apply flex flex-col;
}

.stat-label {
  @apply text-sm text-gray-500;
}

.stat-value {
  @apply text-xl font-bold text-gray-900;
}

.project-breakdown {
  @apply space-y-3;
}

.project-row {
  @apply flex items-center justify-between;
}

.project-row-info {
  @apply flex items-center gap-2;
}

.project-row-name {
  @apply text-sm font-medium text-gray-900;
}

.project-row-hours {
  @apply text-sm font-semibold text-gray-700 tabular-nums;
}

.dashboard-recent {
  @apply mt-6;
}

.project-cell {
  @apply flex items-center gap-2;
}

.table-td-desc {
  @apply max-w-xs truncate;
}

.table-td-mono {
  @apply font-mono tabular-nums;
}
</style>
