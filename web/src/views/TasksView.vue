<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { ProjectTask, Project, User, PaginationMeta, TaskPriority, TaskStatus } from '@/types'
import { useAuthStore } from '@/stores/auth'
import { useToastStore, errorMessage } from '@/stores/toast'
import TaskTree from '@/components/TaskTree.vue'
import TaskBoard, { type BoardColumn } from '@/components/TaskBoard.vue'
import TaskFormModal from '@/components/TaskFormModal.vue'
import BaseModal from '@/components/BaseModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import AppPagination from '@/components/AppPagination.vue'
import { PlusIcon, ClipboardDocumentListIcon, ListBulletIcon, ViewColumnsIcon, SunIcon, Cog6ToothIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { TASK_PRIORITIES } from '@/utils/tasks'
import { toDateString } from '@/utils/format'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToastStore()

type ViewMode = 'list' | 'board'
type DeadlineFilter = '' | 'today' | '7' | '14' | 'month' | 'custom'

const tasks = ref<ProjectTask[]>([])
const boardTasks = ref<ProjectTask[]>([])
const meta = ref<PaginationMeta | null>(null)
const loading = ref(true)
const projects = ref<Project[]>([])
const users = ref<User[]>([])
const statuses = ref<TaskStatus[]>([])

const view = ref<ViewMode>((localStorage.getItem('tasks:view') as ViewMode) || 'list')
const search = ref('')
const projectId = ref(typeof route.query.project === 'string' ? route.query.project : '')
const assignee = ref(typeof route.query.assignee === 'string' ? route.query.assignee : '')
const priority = ref<TaskPriority | ''>('')
const statusId = ref(typeof route.query.status === 'string' ? route.query.status : '')
const status = ref<'open' | 'done' | 'all'>('open')
const deadlineFilter = ref<DeadlineFilter>((typeof route.query.deadline === 'string' ? route.query.deadline : '') as DeadlineFilter)
const deadlineCustom = ref('')
const sort = ref('priority')
const page = ref(1)
const perPage = 50

const showForm = ref(false)
const showStatusManager = ref(false)
const busyId = ref<string | null>(null)
const boardBusy = ref(false)

const todayStatus = computed(() => statuses.value.find((s) => s.is_locked) ?? null)
const boardColumns = computed<BoardColumn[]>(() => {
  const cols: BoardColumn[] = [{ key: '__new', id: null, name: t('tasks.boardNew'), color: '#6B7280', fixed: true }]
  if (todayStatus.value) cols.push({ key: todayStatus.value.id, id: todayStatus.value.id, name: todayStatus.value.name, color: todayStatus.value.color, fixed: true })
  for (const s of statuses.value.filter((x) => !x.is_locked).sort((a, b) => a.position - b.position || a.name.localeCompare(b.name))) {
    cols.push({ key: s.id, id: s.id, name: s.name, color: s.color })
  }
  return cols
})
const isTodayActive = computed(() => !!todayStatus.value && statusId.value === todayStatus.value.id)

const statusOptions = computed(() => [
  { value: 'open' as const, label: t('tasks.open') },
  { value: 'done' as const, label: t('tasks.done') },
  { value: 'all' as const, label: t('tasks.all') },
])
const sortOptions = computed(() => [
  { value: 'priority', label: t('tasks.sortPriority') },
  { value: 'deadline', label: t('tasks.sortDeadline') },
  { value: '-created_at', label: t('tasks.sortNewest') },
  { value: '-updated_at', label: t('tasks.sortUpdated') },
  { value: 'title', label: t('tasks.sortTitle') },
])
const deadlineOptions = computed(() => [
  { value: '' as const, label: t('tasks.deadlineAny') },
  { value: 'today' as const, label: t('tasks.deadlineToday') },
  { value: '7' as const, label: t('tasks.deadlineIn', { n: 7 }) },
  { value: '14' as const, label: t('tasks.deadlineIn', { n: 14 }) },
  { value: 'month' as const, label: t('tasks.deadlineMonth') },
  { value: 'custom' as const, label: t('tasks.deadlineCustom') },
])

/** Inclusive upper bound for the deadline filter, or null for no filter. */
const deadlineUntil = computed<string | null>(() => {
  const d = new Date()
  switch (deadlineFilter.value) {
    case 'today':
      return toDateString(d)
    case '7':
    case '14':
      d.setDate(d.getDate() + Number(deadlineFilter.value))
      return toDateString(d)
    case 'month':
      return toDateString(new Date(d.getFullYear(), d.getMonth() + 1, 0))
    case 'custom':
      return deadlineCustom.value || null
    default:
      return null
  }
})

const hasFilters = computed(
  () => !!search.value || !!projectId.value || !!assignee.value || !!priority.value || !!statusId.value || status.value !== 'open' || !!deadlineUntil.value,
)
/** Without a search term the list shows the tree: top-level tasks with expandable subtasks. */
const treeMode = computed(() => !search.value.trim())

let searchTimer: ReturnType<typeof setTimeout> | undefined

function commonParams(): Record<string, unknown> {
  const params: Record<string, unknown> = {}
  if (search.value.trim()) params['filter[q]'] = search.value.trim()
  if (projectId.value) params['filter[project_id]'] = projectId.value
  if (assignee.value) params['filter[assignee_id]'] = assignee.value
  if (priority.value) params['filter[priority]'] = priority.value
  if (deadlineUntil.value) params['filter[deadline_until]'] = deadlineUntil.value
  return params
}

async function fetchTasks() {
  loading.value = true
  try {
    const params: Record<string, unknown> = {
      ...commonParams(),
      'filter[status]': status.value,
      sort: sort.value,
      page: page.value,
      per_page: perPage,
    }
    if (statusId.value) params['filter[status_id]'] = statusId.value
    if (treeMode.value) params['filter[parent_id]'] = 'root'
    const { data } = await api.get('/project-tasks', { params })
    tasks.value = data.data
    meta.value = data.meta ?? null
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
  }
}

async function fetchBoard() {
  loading.value = true
  try {
    const { data } = await api.get('/project-tasks', {
      params: { ...commonParams(), 'filter[status]': 'open', sort: 'position', per_page: 500 },
    })
    boardTasks.value = data.data
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
  }
}

function refresh() {
  return view.value === 'board' ? fetchBoard() : fetchTasks()
}

async function fetchStatuses() {
  try {
    const { data } = await api.get('/task-statuses')
    statuses.value = data.data
  } catch {
    // The status filter simply stays empty.
  }
}

async function fetchOptions() {
  try {
    const [p, u] = await Promise.all([
      api.get('/projects', { params: { 'filter[is_active]': 1, per_page: 500, sort: 'name' } }),
      api.get('/users', { params: { 'filter[is_active]': 1 } }),
    ])
    projects.value = p.data.data
    users.value = u.data.data
  } catch {
    // Filters still work without the option lists.
  }
}

async function toggle(task: ProjectTask, completed: boolean) {
  busyId.value = task.id
  try {
    await api.put(`/project-tasks/${task.id}`, { completed })
    await refresh()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    busyId.value = null
  }
}

async function moveOnBoard(statusIdTarget: string | null, orderedIds: string[]) {
  boardBusy.value = true
  try {
    await api.post('/project-tasks/reorder', { field: 'status_id', status_id: statusIdTarget, ordered_ids: orderedIds })
    await fetchBoard()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    boardBusy.value = false
  }
}

async function reorderStatuses(orderedIds: string[]) {
  try {
    await api.post('/task-statuses/reorder', { ordered_ids: orderedIds })
    await fetchStatuses()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  }
}

function toggleToday() {
  if (!todayStatus.value) return
  statusId.value = isTodayActive.value ? '' : todayStatus.value.id
}

function onSaved(task: ProjectTask) {
  showForm.value = false
  router.push({ name: 'task-detail', params: { id: task.id } })
}

function clearFilters() {
  search.value = ''
  projectId.value = ''
  assignee.value = ''
  priority.value = ''
  statusId.value = ''
  status.value = 'open'
  deadlineFilter.value = ''
  deadlineCustom.value = ''
}

// ---------------------------------------------------------------- status manager

const newStatusName = ref('')
const newStatusColor = ref('#6D4FC2')
const statusSaving = ref(false)
const deleteStatusTarget = ref<TaskStatus | null>(null)
const statusDeleting = ref(false)

async function addStatus() {
  if (!newStatusName.value.trim()) return
  statusSaving.value = true
  try {
    await api.post('/task-statuses', { name: newStatusName.value.trim(), color: newStatusColor.value })
    newStatusName.value = ''
    await fetchStatuses()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    statusSaving.value = false
  }
}

async function updateStatus(s: TaskStatus, payload: Partial<Pick<TaskStatus, 'name' | 'color'>>) {
  try {
    await api.put(`/task-statuses/${s.id}`, payload)
    await fetchStatuses()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
    await fetchStatuses()
  }
}

async function deleteStatus() {
  if (!deleteStatusTarget.value) return
  statusDeleting.value = true
  try {
    await api.delete(`/task-statuses/${deleteStatusTarget.value.id}`)
    if (statusId.value === deleteStatusTarget.value.id) statusId.value = ''
    deleteStatusTarget.value = null
    await Promise.all([fetchStatuses(), refresh()])
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
  } finally {
    statusDeleting.value = false
  }
}

watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; refresh() }, 300)
})
watch([projectId, assignee, priority, statusId, status, sort, deadlineUntil], () => {
  page.value = 1
  router.replace({ query: { ...(projectId.value ? { project: projectId.value } : {}), ...(assignee.value ? { assignee: assignee.value } : {}) } })
  refresh()
})
watch(page, fetchTasks)
watch(view, (v) => {
  localStorage.setItem('tasks:view', v)
  refresh()
})

onMounted(() => {
  refresh()
  fetchStatuses()
  fetchOptions()
})
</script>

<template>
  <div class="page tasks" :class="{ 'tasks--board': view === 'board' }">
    <div class="page__header">
      <div>
        <h1 class="heading-1 page__title">{{ $t('tasks.title') }}</h1>
        <p v-if="meta && view === 'list'" class="page__subtitle small">{{ $t('tasks.count', { count: meta.total }) }}</p>
      </div>
      <div class="page__actions">
        <div class="segmented" role="group" :aria-label="$t('tasks.view')">
          <button type="button" class="segmented__item" :class="{ 'segmented__item--active': view === 'list' }" :aria-pressed="view === 'list'" @click="view = 'list'">
            <ListBulletIcon class="btn__icon" aria-hidden="true" />
            {{ $t('tasks.viewList') }}
          </button>
          <button type="button" class="segmented__item" :class="{ 'segmented__item--active': view === 'board' }" :aria-pressed="view === 'board'" @click="view = 'board'">
            <ViewColumnsIcon class="btn__icon" aria-hidden="true" />
            {{ $t('tasks.viewBoard') }}
          </button>
        </div>
        <button type="button" class="btn btn--primary" @click="showForm = true">
          <PlusIcon class="btn__icon" aria-hidden="true" />
          {{ $t('tasks.newTask') }}
        </button>
      </div>
    </div>

    <div class="toolbar tasks__toolbar">
      <div class="toolbar__group">
        <input
          id="tasks-search"
          v-model="search"
          type="search"
          class="form__input form__input--sm toolbar__search"
          :placeholder="$t('tasks.search')"
          :aria-label="$t('tasks.search')"
        />
        <button
          v-if="todayStatus"
          type="button"
          class="btn btn--sm tasks__today"
          :class="isTodayActive ? 'btn--primary' : 'btn--secondary'"
          :aria-pressed="isTodayActive"
          @click="toggleToday"
        >
          <SunIcon class="btn__icon" aria-hidden="true" />
          {{ todayStatus.name }}
        </button>
        <div v-if="view === 'list'" class="segmented" role="group" :aria-label="$t('tasks.status')">
          <button
            v-for="o in statusOptions"
            :key="o.value"
            type="button"
            class="segmented__item"
            :class="{ 'segmented__item--active': status === o.value }"
            :aria-pressed="status === o.value"
            @click="status = o.value"
          >
            {{ o.label }}
          </button>
        </div>
        <select v-if="view === 'list'" id="tasks-status" v-model="statusId" class="form__select form__select--sm form__select--inline tasks__filter" :aria-label="$t('tasks.status')">
          <option value="">{{ $t('tasks.allStatuses') }}</option>
          <option value="none">{{ $t('tasks.noStatus') }}</option>
          <option v-for="s in statuses" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
        <select id="tasks-project" v-model="projectId" class="form__select form__select--sm form__select--inline tasks__filter" :aria-label="$t('tasks.project')">
          <option value="">{{ $t('tasks.allProjects') }}</option>
          <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <select id="tasks-assignee" v-model="assignee" class="form__select form__select--sm form__select--inline tasks__filter" :aria-label="$t('tasks.assignee')">
          <option value="">{{ $t('tasks.allAssignees') }}</option>
          <option v-if="auth.user" :value="auth.user.id">{{ $t('tasks.me') }}</option>
          <option value="none">{{ $t('tasks.unassigned') }}</option>
          <option v-for="u in users.filter((x) => x.id !== auth.user?.id)" :key="u.id" :value="u.id">{{ u.name }}</option>
        </select>
        <select id="tasks-priority" v-model="priority" class="form__select form__select--sm form__select--inline" :aria-label="$t('tasks.priority')">
          <option value="">{{ $t('tasks.allPriorities') }}</option>
          <option v-for="p in TASK_PRIORITIES" :key="p" :value="p">{{ $t(`tasks.priorities.${p}`) }}</option>
        </select>
        <select id="tasks-deadline" v-model="deadlineFilter" class="form__select form__select--sm form__select--inline" :aria-label="$t('tasks.deadline')">
          <option v-for="o in deadlineOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
        <input
          v-if="deadlineFilter === 'custom'"
          id="tasks-deadline-date"
          v-model="deadlineCustom"
          type="date"
          class="form__input form__input--sm form__select--inline"
          :aria-label="$t('tasks.deadlineCustom')"
        />
      </div>
      <div class="toolbar__group">
        <template v-if="view === 'list'">
          <label for="tasks-sort" class="toolbar__label">{{ $t('tasks.sort') }}</label>
          <select id="tasks-sort" v-model="sort" class="form__select form__select--sm form__select--inline">
            <option v-for="o in sortOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </template>
        <button type="button" class="btn btn--ghost btn--sm" @click="showStatusManager = true">
          <Cog6ToothIcon class="btn__icon" aria-hidden="true" />
          {{ $t('tasks.manageStatuses') }}
        </button>
      </div>
    </div>

    <div v-if="loading && tasks.length === 0 && boardTasks.length === 0" class="loading">
      <div class="spinner" role="status"></div>
    </div>

    <template v-else-if="view === 'board'">
      <TaskBoard :tasks="boardTasks" :columns="boardColumns" field="status_id" :busy="boardBusy || loading" @move="moveOnBoard" @reorder-columns="reorderStatuses" />
    </template>

    <div v-else class="card">
      <div v-if="tasks.length === 0" class="empty">
        <ClipboardDocumentListIcon class="empty__icon" aria-hidden="true" />
        <template v-if="hasFilters">
          <p class="empty__title">{{ $t('common.noResults') }}</p>
          <p class="empty__text">{{ $t('tasks.noMatches') }}</p>
          <button type="button" class="btn btn--secondary btn--sm" @click="clearFilters">{{ $t('tasks.clearFilters') }}</button>
        </template>
        <template v-else>
          <p class="empty__title">{{ $t('tasks.noTasks') }}</p>
          <p class="empty__text">{{ $t('tasks.noTasksText') }}</p>
          <button type="button" class="btn btn--primary btn--sm" @click="showForm = true">
            <PlusIcon class="btn__icon" aria-hidden="true" />
            {{ $t('tasks.newTask') }}
          </button>
        </template>
      </div>
      <ul v-else class="task-list" :class="{ 'is-loading': loading }">
        <TaskTree v-for="task in tasks" :key="task.id" :task="task" show-project :busy-id="busyId" @toggle="toggle" />
      </ul>
    </div>

    <AppPagination v-if="view === 'list' && meta && meta.last_page > 1" :meta="meta" :page="page" @update:page="page = $event" />

    <TaskFormModal v-if="showForm" :task="null" :project-id="projectId || null" @close="showForm = false" @saved="onSaved" />

    <BaseModal v-if="showStatusManager" :title="$t('tasks.manageStatuses')" size="narrow" @close="showStatusManager = false">
      <p class="small muted status-manager__intro">{{ $t('tasks.statusesIntro') }}</p>
      <ul class="status-manager">
        <li v-for="s in statuses" :key="s.id" class="status-manager__row">
          <input type="color" class="form__input form__color status-manager__color" :value="s.color" :aria-label="$t('common.color')" @change="updateStatus(s, { color: ($event.target as HTMLInputElement).value })" />
          <input
            type="text"
            class="form__input form__input--sm status-manager__name"
            :value="s.name"
            :disabled="s.is_locked"
            :aria-label="$t('common.name')"
            @change="updateStatus(s, { name: ($event.target as HTMLInputElement).value })"
          />
          <span class="status-manager__count">{{ s.tasks_count ?? 0 }}</span>
          <button v-if="!s.is_locked" type="button" class="btn btn--danger-ghost btn--icon btn--sm" :aria-label="$t('common.delete')" @click="deleteStatusTarget = s">
            <TrashIcon class="btn__icon" aria-hidden="true" />
          </button>
          <span v-else class="badge badge--neutral">{{ $t('tasks.statusFixed') }}</span>
        </li>
      </ul>
      <form class="status-manager__add" @submit.prevent="addStatus">
        <input v-model="newStatusColor" type="color" class="form__input form__color status-manager__color" :aria-label="$t('common.color')" />
        <input v-model="newStatusName" type="text" class="form__input form__input--sm status-manager__name" :placeholder="$t('tasks.newStatus')" :aria-label="$t('tasks.newStatus')" />
        <button type="submit" class="btn btn--primary btn--sm" :disabled="statusSaving || !newStatusName.trim()">{{ $t('common.add') }}</button>
      </form>
      <template #footer>
        <button type="button" class="btn btn--secondary" @click="showStatusManager = false">{{ $t('common.close') }}</button>
      </template>
    </BaseModal>

    <ConfirmDialog
      v-if="deleteStatusTarget"
      :title="$t('tasks.deleteStatus')"
      :text="$t('tasks.deleteStatusText', { name: deleteStatusTarget.name, count: deleteStatusTarget.tasks_count ?? 0 })"
      :confirm-label="$t('common.delete')"
      danger
      :busy="statusDeleting"
      @confirm="deleteStatus"
      @cancel="deleteStatusTarget = null"
    />
  </div>
</template>
