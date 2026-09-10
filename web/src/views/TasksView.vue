<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { ProjectTask, Project, User, PaginationMeta, TaskPriority } from '@/types'
import { useAuthStore } from '@/stores/auth'
import { useToastStore, errorMessage } from '@/stores/toast'
import TaskRow from '@/components/TaskRow.vue'
import TaskFormModal from '@/components/TaskFormModal.vue'
import AppPagination from '@/components/AppPagination.vue'
import { PlusIcon, ClipboardDocumentListIcon } from '@heroicons/vue/24/outline'
import { TASK_PRIORITIES } from '@/utils/tasks'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToastStore()

const tasks = ref<ProjectTask[]>([])
const meta = ref<PaginationMeta | null>(null)
const loading = ref(true)
const projects = ref<Project[]>([])
const users = ref<User[]>([])

const search = ref('')
const projectId = ref(typeof route.query.project === 'string' ? route.query.project : '')
const assignee = ref(typeof route.query.assignee === 'string' ? route.query.assignee : '')
const priority = ref<TaskPriority | ''>('')
const status = ref<'open' | 'done' | 'all'>('open')
const sort = ref('priority')
const page = ref(1)
const perPage = 50

const showForm = ref(false)
const busyId = ref<string | null>(null)

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
const hasFilters = computed(() => !!search.value || !!projectId.value || !!assignee.value || !!priority.value || status.value !== 'open')

let searchTimer: ReturnType<typeof setTimeout> | undefined

async function fetchTasks() {
  loading.value = true
  try {
    const params: Record<string, unknown> = {
      'filter[status]': status.value,
      sort: sort.value,
      page: page.value,
      per_page: perPage,
    }
    if (search.value.trim()) params['filter[q]'] = search.value.trim()
    if (projectId.value) params['filter[project_id]'] = projectId.value
    if (assignee.value) params['filter[assignee_id]'] = assignee.value
    if (priority.value) params['filter[priority]'] = priority.value
    const { data } = await api.get('/project-tasks', { params })
    tasks.value = data.data
    meta.value = data.meta ?? null
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
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
    await fetchTasks()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    busyId.value = null
  }
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
  status.value = 'open'
}

watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; fetchTasks() }, 300)
})
watch([projectId, assignee, priority, status, sort], () => {
  page.value = 1
  router.replace({ query: { ...(projectId.value ? { project: projectId.value } : {}), ...(assignee.value ? { assignee: assignee.value } : {}) } })
  fetchTasks()
})
watch(page, fetchTasks)

onMounted(() => {
  fetchTasks()
  fetchOptions()
})
</script>

<template>
  <div class="page tasks">
    <div class="page__header">
      <div>
        <h1 class="heading-1 page__title">{{ $t('tasks.title') }}</h1>
        <p v-if="meta" class="page__subtitle small">{{ $t('tasks.count', { count: meta.total }) }}</p>
      </div>
      <div class="page__actions">
        <button type="button" class="btn btn--primary" @click="showForm = true">
          <PlusIcon class="btn__icon" aria-hidden="true" />
          {{ $t('tasks.newTask') }}
        </button>
      </div>
    </div>

    <div class="toolbar">
      <div class="toolbar__group">
        <input
          id="tasks-search"
          v-model="search"
          type="search"
          class="form__input form__input--sm toolbar__search"
          :placeholder="$t('tasks.search')"
          :aria-label="$t('tasks.search')"
        />
        <div class="segmented" role="group" :aria-label="$t('tasks.status')">
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
      </div>
      <div class="toolbar__group">
        <label for="tasks-sort" class="toolbar__label">{{ $t('tasks.sort') }}</label>
        <select id="tasks-sort" v-model="sort" class="form__select form__select--sm form__select--inline">
          <option v-for="o in sortOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading && tasks.length === 0" class="loading">
      <div class="spinner" role="status"></div>
    </div>

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
        <TaskRow v-for="task in tasks" :key="task.id" :task="task" show-project :busy="busyId === task.id" @toggle="toggle" />
      </ul>
    </div>

    <AppPagination v-if="meta && meta.last_page > 1" :meta="meta" :page="page" @update:page="page = $event" />

    <TaskFormModal v-if="showForm" :task="null" :project-id="projectId || null" @close="showForm = false" @saved="onSaved" />
  </div>
</template>
