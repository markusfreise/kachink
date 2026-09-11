<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { Project, Client, TimeEntry, PaginationMeta, ProjectTask, User } from '@/types'
import BaseModal from '@/components/BaseModal.vue'
import { useAuthStore } from '@/stores/auth'
import { EyeIcon, EyeSlashIcon } from '@heroicons/vue/24/outline'
import { ArrowLeftIcon, DocumentTextIcon, PencilSquareIcon, ClockIcon, PlusIcon, ClipboardDocumentListIcon } from '@heroicons/vue/24/outline'
import TaskTree from '@/components/TaskTree.vue'
import UserAvatar from '@/components/UserAvatar.vue'
import TaskFormModal from '@/components/TaskFormModal.vue'
import ProjectFormModal from '@/components/ProjectFormModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useToastStore, errorMessage } from '@/stores/toast'
import { formatDuration, formatHoursDecimal, formatCurrency, formatDate, formatMonth } from '@/utils/format'

/** Project with the time summary the API adds on show. */
interface ProjectWithSummary extends Project {
  tracked_seconds?: number
  billable_seconds?: number
  billable_hours?: number
  billable_amount?: number | null
}

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToastStore()
const auth = useAuthStore()

const project = ref<ProjectWithSummary | null>(null)
const loading = ref(true)
const notFound = ref(false)

const entries = ref<TimeEntry[]>([])
const entriesMeta = ref<PaginationMeta | null>(null)
const entriesLoading = ref(true)
const entriesPage = ref(1)
const entriesLoadingMore = ref(false)

const clients = ref<Client[]>([])

const tasks = ref<ProjectTask[]>([])
const tasksMeta = ref<PaginationMeta | null>(null)
const tasksLoading = ref(true)
const showTaskForm = ref(false)
const busyTaskId = ref<string | null>(null)

// Watchers
const watchers = ref<User[]>([])
const watchBusy = ref(false)
const showWatchers = ref(false)
const members = ref<User[]>([])
const watcherDraft = ref<string[]>([])
const isWatching = computed(() => watchers.value.some((w) => w.id === auth.user?.id))

async function toggleWatch() {
  if (!project.value) return
  watchBusy.value = true
  try {
    const { data } = isWatching.value
      ? await api.delete(`/projects/${project.value.id}/watch`)
      : await api.post(`/projects/${project.value.id}/watch`)
    watchers.value = data.data
    toast.success(isWatching.value ? t('watchers.nowWatching') : t('watchers.stopped'))
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    watchBusy.value = false
  }
}

async function openWatchers() {
  if (members.value.length === 0) {
    try {
      const { data } = await api.get('/users', { params: { 'filter[is_active]': 1 } })
      members.value = data.data
    } catch (e) {
      toast.error(errorMessage(e, t('common.loadFailed')))
      return
    }
  }
  watcherDraft.value = watchers.value.map((w) => w.id)
  showWatchers.value = true
}

function toggleWatcher(id: string) {
  watcherDraft.value = watcherDraft.value.includes(id) ? watcherDraft.value.filter((x) => x !== id) : [...watcherDraft.value, id]
}

async function saveWatchers() {
  if (!project.value) return
  watchBusy.value = true
  try {
    const { data } = await api.put(`/projects/${project.value.id}/watchers`, { user_ids: watcherDraft.value })
    watchers.value = data.data
    showWatchers.value = false
    toast.success(t('watchers.saved'))
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    watchBusy.value = false
  }
}
const showForm = ref(false)
const showArchive = ref(false)
const archiveBusy = ref(false)

const projectId = computed(() => String(route.params.id))

const trackedSeconds = computed(() => project.value?.tracked_seconds ?? 0)
const billableSeconds = computed(() => project.value?.billable_seconds ?? 0)
const billableShare = computed(() => (trackedSeconds.value > 0 ? Math.round((billableSeconds.value / trackedSeconds.value) * 100) : 0))
const budgetPercent = computed(() => project.value?.budget_used_percentage ?? null)
const hourlyRate = computed(() => (project.value?.effective_hourly_rate != null ? Number(project.value.effective_hourly_rate) : null))
const revenue = computed(() => (project.value?.billable_amount != null ? Number(project.value.billable_amount) : null))
const rateLabel = computed(() => {
  if (!project.value) return ''
  if (project.value.rate_mode === 'user') return t('rates.byMember')
  return hourlyRate.value != null ? t('projectDetail.revenueSub', { rate: formatCurrency(hourlyRate.value) }) : ''
})

const budgetTone = computed(() => {
  const p = budgetPercent.value ?? 0
  if (p >= 100) return 'bar__fill--danger'
  if (p >= 80) return 'bar__fill--warning'
  return 'bar__fill--success'
})
const budgetWidth = computed(() => `${Math.min(Math.max(budgetPercent.value ?? 0, 0), 100)}%`)

async function fetchProject() {
  loading.value = true
  notFound.value = false
  try {
    const { data } = await api.get(`/projects/${projectId.value}`)
    project.value = data.data
    watchers.value = data.data.watchers ?? []
  } catch (e) {
    project.value = null
    notFound.value = true
    const status = (e as { response?: { status?: number } })?.response?.status
    if (status !== 404) toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
  }
}

async function fetchEntries(page = 1) {
  if (page === 1) entriesLoading.value = true
  else entriesLoadingMore.value = true
  try {
    const { data } = await api.get('/time-entries', {
      params: {
        'filter[project_id]': projectId.value,
        all_users: 1,
        per_page: 25,
        page,
        sort: '-started_at',
      },
    })
    entries.value = page === 1 ? data.data : [...entries.value, ...data.data]
    entriesMeta.value = data.meta ?? null
    entriesPage.value = page
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    entriesLoading.value = false
    entriesLoadingMore.value = false
  }
}

const hasMoreEntries = computed(() => !!entriesMeta.value && entriesMeta.value.current_page < entriesMeta.value.last_page)

interface MonthGroup {
  key: string
  label: string
  seconds: number
  entries: TimeEntry[]
}

/** Loaded entries clustered by month (newest first) with the sum of the loaded entries per month. */
const entryMonths = computed<MonthGroup[]>(() => {
  const groups: MonthGroup[] = []
  for (const entry of entries.value) {
    const key = entry.started_at.slice(0, 7)
    let group = groups[groups.length - 1]
    if (!group || group.key !== key) {
      group = { key, label: formatMonth(`${key}-01`), seconds: 0, entries: [] }
      groups.push(group)
    }
    group.entries.push(entry)
    if (!entry.is_running) group.seconds += entry.duration_seconds ?? 0
  }
  return groups
})

async function fetchTasks() {
  tasksLoading.value = true
  try {
    const { data } = await api.get('/project-tasks', {
      params: { 'filter[project_id]': projectId.value, 'filter[parent_id]': 'root', 'filter[status]': 'open', per_page: 10 },
    })
    tasks.value = data.data
    tasksMeta.value = data.meta ?? null
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    tasksLoading.value = false
  }
}

async function toggleTask(task: ProjectTask, completed: boolean) {
  busyTaskId.value = task.id
  try {
    await api.put(`/project-tasks/${task.id}`, { completed })
    await fetchTasks()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    busyTaskId.value = null
  }
}

function onTaskSaved(task: ProjectTask) {
  showTaskForm.value = false
  router.push({ name: 'task-detail', params: { id: task.id } })
}

async function openEdit() {
  if (clients.value.length === 0) {
    try {
      const { data } = await api.get('/clients', { params: { 'filter[is_active]': 1, per_page: 500, sort: 'name' } })
      clients.value = data.data
    } catch (e) {
      toast.error(errorMessage(e, t('common.loadFailed')))
      return
    }
  }
  showForm.value = true
}

function onSaved() {
  toast.success(t('projects.updated'))
  showForm.value = false
  fetchProject()
}

async function setActive(isActive: boolean) {
  if (!project.value) return
  archiveBusy.value = true
  try {
    await api.put(`/projects/${project.value.id}`, { is_active: isActive })
    toast.success(isActive ? t('projects.restored') : t('projects.archived'))
    showArchive.value = false
    await fetchProject()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    archiveBusy.value = false
  }
}

async function load() {
  // Entries are only requested for an existing project: an unknown id would just add a second failure.
  await fetchProject()
  if (project.value) {
    fetchEntries()
    fetchTasks()
  } else {
    entriesLoading.value = false
    tasksLoading.value = false
  }
}

onMounted(load)
watch(projectId, load)
</script>

<template>
  <div class="page project-detail">
    <div v-if="loading" class="loading">
      <span class="spinner" role="status"></span>
    </div>

    <div v-else-if="notFound || !project" class="empty card">
      <p class="empty__title">{{ $t('projectDetail.notFound') }}</p>
      <p class="empty__text">{{ $t('projectDetail.notFoundText') }}</p>
      <RouterLink class="btn btn--secondary btn--sm" :to="{ name: 'projects' }">
        <ArrowLeftIcon class="btn__icon" aria-hidden="true" />
        {{ $t('projectDetail.backToProjects') }}
      </RouterLink>
    </div>

    <template v-else>
      <RouterLink class="project-detail__back" :to="{ name: 'projects' }">
        <ArrowLeftIcon class="project-detail__back-icon" aria-hidden="true" />
        {{ $t('projects.title') }}
      </RouterLink>

      <div class="page__header">
        <div class="project-detail__ident">
          <span class="color-dot color-dot--lg project-detail__dot" :style="{ backgroundColor: project.color }" aria-hidden="true"></span>
          <div class="project-detail__titles">
            <h1 class="heading-1 project-detail__name">{{ project.name }}</h1>
            <div class="project-detail__meta">
              <span class="project-detail__client">{{ project.client?.name }}</span>
              <span class="badge" :class="project.is_billable ? 'badge--success' : 'badge--neutral'">
                {{ project.is_billable ? $t('common.billable') : $t('common.nonBillable') }}
              </span>
              <span v-if="!project.is_active" class="badge badge--neutral">{{ $t('common.archived') }}</span>
            </div>
            <div class="project-detail__watchers">
              <button type="button" class="btn btn--ghost btn--sm" :disabled="watchBusy" :aria-pressed="isWatching" @click="toggleWatch">
                <component :is="isWatching ? EyeSlashIcon : EyeIcon" class="btn__icon" aria-hidden="true" />
                {{ isWatching ? $t('watchers.unwatch') : $t('watchers.watch') }}
              </button>
              <button type="button" class="project-detail__watcher-list" :title="$t('watchers.manage')" @click="openWatchers">
                <span v-if="watchers.length === 0" class="small muted">{{ $t('watchers.none') }}</span>
                <template v-else>
                  <UserAvatar v-for="w in watchers.slice(0, 6)" :key="w.id" :name="w.name" :avatar-url="w.avatar_url" size="sm" />
                  <span class="small muted">{{ $t('watchers.count', { count: watchers.length }) }}</span>
                </template>
              </button>
            </div>
          </div>
        </div>
        <div class="page__actions">
          <RouterLink class="btn btn--primary" :to="{ name: 'report-detail', params: { scope: 'projects', id: project.id } }">
            <DocumentTextIcon class="btn__icon" aria-hidden="true" />
            {{ $t('reportDetail.report') }}
          </RouterLink>
          <button type="button" class="btn btn--secondary" @click="openEdit">
            <PencilSquareIcon class="btn__icon" aria-hidden="true" />
            {{ $t('common.edit') }}
          </button>
          <button v-if="project.is_active" type="button" class="btn btn--ghost" @click="showArchive = true">
            {{ $t('common.archive') }}
          </button>
          <button v-else type="button" class="btn btn--ghost" :disabled="archiveBusy" @click="setActive(true)">
            {{ $t('projects.unarchive') }}
          </button>
        </div>
      </div>

      <div class="grid grid--4 page__section">
        <div class="stat">
          <span class="stat__label">{{ $t('projects.tracked') }}</span>
          <span class="stat__value">{{ $t('projects.trackedValue', { hours: formatDuration(trackedSeconds) }) }}</span>
          <span class="stat__sub">{{ $t('projectDetail.trackedAllTime') }}</span>
        </div>
        <div class="stat">
          <span class="stat__label">{{ $t('projectDetail.billableHours') }}</span>
          <span class="stat__value stat__value--accent">{{ $t('projects.trackedValue', { hours: formatDuration(billableSeconds) }) }}</span>
          <span class="stat__sub">{{ $t('projectDetail.billableShare', { percent: billableShare }) }}</span>
        </div>
        <div class="stat">
          <span class="stat__label">{{ $t('projectDetail.budgetUsage') }}</span>
          <template v-if="project.budget_hours">
            <span class="stat__value" :class="{ 'project-detail__stat-value--over': (budgetPercent ?? 0) >= 100 }">{{ Math.round(budgetPercent ?? 0) }}%</span>
            <span class="stat__sub">
              {{ $t('projects.budgetUsage', { used: formatHoursDecimal(trackedSeconds), total: formatHoursDecimal(project.budget_hours * 3600) }) }}
            </span>
            <div class="bar bar--block project-detail__budget-bar" role="progressbar" :aria-valuenow="Math.round(budgetPercent ?? 0)" aria-valuemin="0" aria-valuemax="100">
              <span class="bar__fill" :class="budgetTone" :style="{ width: budgetWidth }"></span>
            </div>
          </template>
          <template v-else>
            <span class="stat__value project-detail__stat-value--muted">&ndash;</span>
            <span class="stat__sub">{{ $t('projectDetail.budgetNone') }}</span>
          </template>
        </div>
        <div class="stat">
          <span class="stat__label">{{ $t('projectDetail.revenue') }}</span>
          <template v-if="revenue != null">
            <span class="stat__value">{{ formatCurrency(revenue) }}</span>
            <span class="stat__sub">{{ rateLabel }}</span>
          </template>
          <template v-else>
            <span class="stat__value project-detail__stat-value--muted">&ndash;</span>
            <span class="stat__sub">{{ $t('projectDetail.rateNone') }}</span>
          </template>
        </div>
      </div>

      <section class="card page__section">
        <div class="card__header">
          <h2 class="card__title">{{ $t('tasks.title') }}</h2>
          <div class="project-detail__task-actions">
            <span v-if="tasksMeta && tasks.length" class="toolbar__count">{{ $t('tasks.openCount', { count: tasksMeta.total }) }}</span>
            <RouterLink class="btn btn--ghost btn--sm" :to="{ name: 'tasks', query: { project: project.id } }">{{ $t('tasks.allTasksOfProject') }}</RouterLink>
            <button type="button" class="btn btn--secondary btn--sm" @click="showTaskForm = true">
              <PlusIcon class="btn__icon" aria-hidden="true" />
              {{ $t('tasks.newTask') }}
            </button>
          </div>
        </div>
        <div class="card__body card__body--flush">
          <div v-if="tasksLoading" class="loading">
            <span class="spinner" role="status"></span>
          </div>
          <div v-else-if="tasks.length === 0" class="empty">
            <ClipboardDocumentListIcon class="empty__icon" aria-hidden="true" />
            <p class="empty__title">{{ $t('tasks.noTasks') }}</p>
            <p class="empty__text">{{ $t('tasks.noTasksText') }}</p>
          </div>
          <ul v-else class="task-list">
            <TaskTree v-for="task in tasks" :key="task.id" :task="task" :busy-id="busyTaskId" @toggle="toggleTask" />
          </ul>
        </div>
      </section>

      <section class="card page__section">
        <div class="card__header">
          <h2 class="card__title">{{ $t('projectDetail.recentEntries') }}</h2>
          <span v-if="entriesMeta && entries.length" class="toolbar__count">
            {{ $t('projectDetail.recentEntriesSub', { count: entries.length, total: entriesMeta.total }) }}
          </span>
        </div>
        <div class="card__body card__body--flush">
          <div v-if="entriesLoading" class="loading">
            <span class="spinner" role="status"></span>
          </div>
          <div v-else-if="entries.length === 0" class="empty">
            <ClockIcon class="empty__icon" aria-hidden="true" />
            <p class="empty__title">{{ $t('projectDetail.noEntries') }}</p>
            <p class="empty__text">{{ $t('projectDetail.noEntriesText') }}</p>
          </div>
          <div v-else class="table-wrap">
            <table class="table table--compact project-detail__entries">
              <thead>
                <tr>
                  <th scope="col">{{ $t('common.date') }}</th>
                  <th scope="col">{{ $t('projectDetail.member') }}</th>
                  <th scope="col">{{ $t('timeEntries.task') }}</th>
                  <th scope="col">{{ $t('timeEntries.description') }}</th>
                  <th scope="col" class="table__num">{{ $t('common.duration') }}</th>
                </tr>
              </thead>
              <tbody v-for="month in entryMonths" :key="month.key">
                <tr class="table__group project-detail__month">
                  <th scope="rowgroup" colspan="4">{{ month.label }}</th>
                  <td class="table__num">{{ formatDuration(month.seconds) }}</td>
                </tr>
                <tr v-for="entry in month.entries" :key="entry.id" class="table__row">
                  <td class="table__time">{{ formatDate(entry.started_at) }}</td>
                  <td class="project-detail__member">
                    <span class="cell">
                      <UserAvatar :name="entry.user?.name" :avatar-url="entry.user?.avatar_url" size="sm" />
                      <span class="cell__title">{{ entry.user?.name ?? '–' }}</span>
                    </span>
                  </td>
                  <td :class="{ 'table__muted': !entry.task }">{{ entry.task?.name ?? $t('projectDetail.noTask') }}</td>
                  <td class="table__truncate" :class="{ 'table__muted': !entry.description }" :title="entry.description ?? undefined">
                    {{ entry.description || '–' }}
                  </td>
                  <td class="table__num">
                    <span v-if="entry.is_running" class="badge badge--info">{{ $t('projectDetail.running') }}</span>
                    <template v-else>{{ formatDuration(entry.duration_seconds) }}</template>
                  </td>
                </tr>
              </tbody>
            </table>
            <div v-if="hasMoreEntries" class="project-detail__more">
              <button type="button" class="btn btn--secondary btn--sm" :disabled="entriesLoadingMore" @click="fetchEntries(entriesPage + 1)">
                {{ entriesLoadingMore ? $t('common.saving') : $t('projectDetail.moreEntries') }}
              </button>
            </div>
          </div>
        </div>
      </section>
    </template>

    <ProjectFormModal
      v-if="showForm && project"
      :project="project"
      :clients="clients"
      @close="showForm = false"
      @saved="onSaved"
    />

    <TaskFormModal v-if="showTaskForm && project" :task="null" :project-id="project.id" @close="showTaskForm = false" @saved="onTaskSaved" />

    <BaseModal v-if="showWatchers" :title="$t('watchers.manage')" size="narrow" @close="showWatchers = false">
      <p class="small muted">{{ $t('watchers.manageIntro') }}</p>
      <ul class="watcher-picker">
        <li v-for="m in members" :key="m.id">
          <label class="form__check watcher-picker__row">
            <input type="checkbox" :checked="watcherDraft.includes(m.id)" @change="toggleWatcher(m.id)" />
            <UserAvatar :name="m.name" :avatar-url="m.avatar_url" size="sm" />
            <span>{{ m.name }}</span>
          </label>
        </li>
      </ul>
      <template #footer>
        <button type="button" class="btn btn--secondary" @click="showWatchers = false">{{ $t('common.cancel') }}</button>
        <button type="button" class="btn btn--primary" :disabled="watchBusy" @click="saveWatchers">{{ $t('common.save') }}</button>
      </template>
    </BaseModal>

    <ConfirmDialog
      v-if="showArchive && project"
      :title="$t('projects.archiveTitle')"
      :text="$t('projects.archiveText', { name: project.name })"
      :confirm-label="$t('common.archive')"
      :busy="archiveBusy"
      @confirm="setActive(false)"
      @cancel="showArchive = false"
    />
  </div>
</template>
