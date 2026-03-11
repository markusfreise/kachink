<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { TimeEntry, Project, Task } from '@/types'
import { PencilIcon, TrashIcon, PlusIcon } from '@heroicons/vue/24/outline'
import ManualEntryModal from '@/components/ManualEntryModal.vue'
import ComboBox from '@/components/ComboBox.vue'

const { t } = useI18n()
const entries = ref<TimeEntry[]>([])
const projects = ref<Project[]>([])
const tasks = ref<Task[]>([])
const loading = ref(true)
const showManualEntry = ref(false)
const meta = ref({ current_page: 1, last_page: 1, total: 0 })

// Filters
const dateFrom = ref(getLast30Days())
const dateTo = ref(new Date().toISOString().split('T')[0]!)
const filterProjectId = ref('')

const projectOptions = computed(() =>
  projects.value.map(p => ({
    id: p.id,
    label: p.name,
    subtitle: p.client?.name,
    color: p.color,
  }))
)

function getLast30Days(): string {
  const d = new Date()
  d.setDate(d.getDate() - 30)
  return d.toISOString().split('T')[0]!
}

async function fetchEntries(page = 1) {
  loading.value = true
  try {
    const params: Record<string, string | number> = {
      'filter[date_from]': dateFrom.value,
      'filter[date_to]': dateTo.value,
      per_page: 50,
      page,
      sort: '-started_at',
    }
    if (filterProjectId.value) {
      params['filter[project_id]'] = filterProjectId.value
    }
    const { data } = await api.get('/time-entries', { params })
    entries.value = data.data
    meta.value = data.meta
  } finally {
    loading.value = false
  }
}

async function deleteEntry(id: string) {
  if (!confirm(t('timeEntries.deleteConfirm'))) return
  await api.delete(`/time-entries/${id}`)
  entries.value = entries.value.filter((e) => e.id !== id)
}

function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString(undefined, {
    weekday: 'short',
    day: '2-digit',
    month: '2-digit',
  })
}

function onEntryCreated() {
  showManualEntry.value = false
  fetchEntries()
}

watch([dateFrom, dateTo, filterProjectId], () => fetchEntries())

onMounted(async () => {
  const [, projectsRes, tasksRes] = await Promise.all([
    fetchEntries(),
    api.get('/projects', { params: { 'filter[is_active]': true, per_page: 100 } }),
    api.get('/tasks'),
  ])
  projects.value = projectsRes.data.data
  tasks.value = tasksRes.data.data
})
</script>

<template>
  <div class="page-container">
    <div class="page-header">
      <h1 class="heading-1">{{ $t('timeEntries.title') }}</h1>
      <button class="btn-primary" @click="showManualEntry = true">
        <PlusIcon class="btn-icon-sm" />
        {{ $t('timeEntries.manualEntry') }}
      </button>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
      <div class="form-group">
        <label class="form-label">{{ $t('common.from') }}</label>
        <input v-model="dateFrom" type="date" class="form-input" />
      </div>
      <div class="form-group">
        <label class="form-label">{{ $t('common.to') }}</label>
        <input v-model="dateTo" type="date" class="form-input" />
      </div>
      <div class="form-group">
        <label class="form-label">{{ $t('timeEntries.project') }}</label>
        <ComboBox
          v-model="filterProjectId"
          :options="projectOptions"
          :placeholder="$t('timeEntries.allProjects')"
          :clearable="true"
          :clear-label="$t('timeEntries.allProjects')"
        />
      </div>
    </div>

    <!-- Table -->
    <div class="card">
      <div v-if="loading" class="card-body loading-container">
        <div class="loading-spinner"></div>
      </div>
      <div v-else-if="entries.length === 0" class="empty-state">
        <p class="empty-state-text">{{ $t('timeEntries.noEntries') }}</p>
      </div>
      <div v-else class="table-container">
        <table class="table">
          <thead class="table-header">
            <tr>
              <th class="table-th">{{ $t('timeEntries.date') }}</th>
              <th class="table-th">{{ $t('timeEntries.project') }}</th>
              <th class="table-th">{{ $t('timeEntries.task') }}</th>
              <th class="table-th">{{ $t('timeEntries.description') }}</th>
              <th class="table-th">{{ $t('timeEntries.start') }}</th>
              <th class="table-th">{{ $t('timeEntries.stop') }}</th>
              <th class="table-th">{{ $t('timeEntries.duration') }}</th>
              <th class="table-th">{{ $t('timeEntries.billable') }}</th>
              <th class="table-th">{{ $t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in entries" :key="entry.id" class="table-row">
              <td class="table-td">{{ formatDate(entry.started_at) }}</td>
              <td class="table-td">
                <div class="project-cell">
                  <span class="color-dot" :style="{ backgroundColor: entry.project?.color }"></span>
                  {{ entry.project?.name }}
                </div>
              </td>
              <td class="table-td">{{ entry.task?.name || '—' }}</td>
              <td class="table-td table-td-desc">{{ entry.description || '—' }}</td>
              <td class="table-td table-td-mono">{{ formatTime(entry.started_at) }}</td>
              <td class="table-td table-td-mono">
                {{ entry.stopped_at ? formatTime(entry.stopped_at) : '—' }}
              </td>
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
              <td class="table-td">
                <div class="action-btns">
                  <button class="btn-ghost btn-icon btn-sm" @click="deleteEntry(entry.id)">
                    <TrashIcon class="action-icon" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="meta.last_page > 1" class="pagination-bar">
        <span class="text-muted">{{ $t('common.entries', { count: meta.total }) }}</span>
        <div class="pagination-btns">
          <button
            class="btn-secondary btn-sm"
            :disabled="meta.current_page <= 1"
            @click="fetchEntries(meta.current_page - 1)"
          >
            {{ $t('common.previous') }}
          </button>
          <span class="text-muted">{{ meta.current_page }} / {{ meta.last_page }}</span>
          <button
            class="btn-secondary btn-sm"
            :disabled="meta.current_page >= meta.last_page"
            @click="fetchEntries(meta.current_page + 1)"
          >
            {{ $t('common.next') }}
          </button>
        </div>
      </div>
    </div>

    <ManualEntryModal
      v-if="showManualEntry"
      :projects="projects"
      :tasks="tasks"
      @close="showManualEntry = false"
      @created="onEntryCreated"
    />
  </div>
</template>

<style scoped>
@reference "../assets/main.css";
.page-header {
  @apply flex items-center justify-between mb-6;
}

.filters-bar {
  @apply grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6;
}

.loading-container {
  @apply flex justify-center py-12;
}

.project-cell {
  @apply flex items-center gap-2;
}

.table-td-desc {
  @apply max-w-xs truncate;
}

.table-td-mono {
  @apply font-mono text-xs tabular-nums;
}

.action-btns {
  @apply flex gap-1;
}

.action-icon {
  @apply h-4 w-4;
}

.btn-icon-sm {
  @apply h-4 w-4;
}

.pagination-bar {
  @apply flex items-center justify-between px-6 py-3 border-t border-gray-200;
}

.pagination-btns {
  @apply flex items-center gap-3;
}
</style>
