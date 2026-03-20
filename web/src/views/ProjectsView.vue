<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { Project, Client, PaginationMeta } from '@/types'
import { PlusIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import ProjectFormModal from '@/components/ProjectFormModal.vue'

const { t } = useI18n()
const router = useRouter()
const projects = ref<Project[]>([])
const clients = ref<Client[]>([])
const loading = ref(true)
const showForm = ref(false)
const editingProject = ref<Project | null>(null)

const page = ref(1)
const perPage = ref(25)
const meta = ref<PaginationMeta | null>(null)
const search = ref('')
const sort = ref('name')
const clientFilter = ref<number | null>(null)

const perPageOptions = [10, 25, 50, 100]
const sortOptions = [
  { value: 'name', label: t('projects.sortName') },
  { value: '-name', label: t('projects.sortNameDesc') },
  { value: '-created_at', label: t('projects.sortNewest') },
  { value: 'created_at', label: t('projects.sortOldest') },
]

let searchTimer: ReturnType<typeof setTimeout> | null = null

async function fetchProjects() {
  loading.value = true
  try {
    const params: Record<string, unknown> = {
      'filter[is_active]': true,
      per_page: perPage.value,
      page: page.value,
      include_time_summary: true,
      sort: sort.value,
    }
    if (search.value) params['filter[name]'] = search.value
    if (clientFilter.value) params['filter[client_id]'] = clientFilter.value
    const { data } = await api.get('/projects', { params })
    projects.value = data.data
    meta.value = data.meta ?? null
  } finally {
    loading.value = false
  }
}

watch(perPage, () => { page.value = 1; fetchProjects() })
watch(page, fetchProjects)
watch(sort, () => { page.value = 1; fetchProjects() })
watch(clientFilter, () => { page.value = 1; fetchProjects() })
watch(search, () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; fetchProjects() }, 300)
})

function openCreate() {
  editingProject.value = null
  showForm.value = true
}

function openEdit(project: Project) {
  editingProject.value = project
  showForm.value = true
}

async function archiveProject(project: Project) {
  if (!confirm(t('projects.archiveConfirm', { name: project.name }))) return
  await api.delete(`/projects/${project.id}`)
  fetchProjects()
}

function onSaved() {
  showForm.value = false
  fetchProjects()
}

onMounted(async () => {
  const [, clientsRes] = await Promise.all([
    fetchProjects(),
    api.get('/clients', { params: { 'filter[is_active]': true, per_page: 500 } }),
  ])
  clients.value = clientsRes.data.data
})
</script>

<template>
  <div class="page-container">
    <div class="page-header">
      <h1 class="heading-1">{{ $t('projects.title') }}</h1>
      <button class="btn-primary" @click="openCreate">
        <PlusIcon class="btn-icon-sm" />
        {{ $t('projects.newProject') }}
      </button>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-filters">
        <input
          v-model="search"
          type="search"
          class="form-input search-input"
          :placeholder="$t('projects.search')"
        />
        <select v-model="clientFilter" class="form-select">
          <option :value="null">{{ $t('projects.allClients') }}</option>
          <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <select v-model="sort" class="form-select">
          <option v-for="o in sortOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
      </div>
      <div class="toolbar-right">
        <span class="text-muted">
          <template v-if="meta">{{ meta.total }} {{ $t('projects.title').toLowerCase() }}</template>
        </span>
        <label class="form-label">{{ $t('common.perPage') }}</label>
        <select v-model="perPage" class="form-select per-page-select">
          <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="loading-center">
      <div class="loading-spinner"></div>
    </div>

    <div v-else class="projects-grid">
      <div
        v-for="project in projects"
        :key="project.id"
        class="project-card"
        @click="router.push({ name: 'project-detail', params: { id: project.id } })"
      >
        <div class="project-card-header">
          <span class="color-dot" :style="{ backgroundColor: project.color }"></span>
          <div class="project-card-info">
            <h3 class="project-card-name">{{ project.name }}</h3>
            <span class="text-muted">{{ project.client?.name }}</span>
          </div>
          <div class="project-card-actions" @click.stop>
            <button class="btn-ghost btn-sm" @click="openEdit(project)">{{ $t('common.edit') }}</button>
            <button class="btn-ghost btn-sm" @click="archiveProject(project)">{{ $t('common.archive') }}</button>
          </div>
        </div>

        <div class="project-card-stats">
          <div class="project-stat">
            <span class="project-stat-label">{{ $t('projects.tracked') }}</span>
            <span class="project-stat-value">{{ project.total_tracked_hours ?? 0 }}h</span>
          </div>
          <div v-if="project.budget_hours" class="project-stat">
            <span class="project-stat-label">{{ $t('projects.budget') }}</span>
            <span class="project-stat-value">{{ project.budget_hours }}h</span>
          </div>
          <div v-if="project.hourly_rate" class="project-stat">
            <span class="project-stat-label">{{ $t('projects.rate') }}</span>
            <span class="project-stat-value">&euro;{{ project.hourly_rate }}/h</span>
          </div>
          <div class="project-stat">
            <span class="project-stat-label">{{ $t('projects.tasks') }}</span>
            <span class="project-stat-value">{{ project.tasks_count ?? 0 }}</span>
          </div>
        </div>

        <div v-if="project.budget_hours && project.budget_used_percentage != null" class="project-budget-bar">
          <div class="budget-bar-bg">
            <div
              class="budget-bar-fill"
              :class="{
                'budget-bar-ok': project.budget_used_percentage < 80,
                'budget-bar-warn': project.budget_used_percentage >= 80 && project.budget_used_percentage < 100,
                'budget-bar-over': project.budget_used_percentage >= 100,
              }"
              :style="{ width: Math.min(project.budget_used_percentage, 100) + '%' }"
            ></div>
          </div>
          <span class="budget-bar-text">{{ project.budget_used_percentage }}%</span>
        </div>

        <div class="project-card-footer">
          <span :class="project.is_billable ? 'badge-green' : 'badge-gray'">
            {{ project.is_billable ? $t('common.billable') : $t('common.nonBillable') }}
          </span>
        </div>
      </div>
    </div>

    <!-- Pagination -->
    <div v-if="meta && meta.last_page > 1" class="pagination">
      <button
        class="btn-secondary btn-sm"
        :disabled="page === 1"
        @click="page--"
      >
        <ChevronLeftIcon class="btn-icon-sm" />
      </button>

      <div class="pagination-pages">
        <button
          v-for="p in meta.last_page"
          :key="p"
          :class="p === page ? 'page-btn-active' : 'page-btn'"
          @click="page = p"
        >
          {{ p }}
        </button>
      </div>

      <button
        class="btn-secondary btn-sm"
        :disabled="page === meta.last_page"
        @click="page++"
      >
        <ChevronRightIcon class="btn-icon-sm" />
      </button>

      <span class="pagination-info">
        {{ $t('common.page', { current: page, last: meta.last_page }) }}
      </span>
    </div>

    <ProjectFormModal
      v-if="showForm"
      :project="editingProject"
      :clients="clients"
      @close="showForm = false"
      @saved="onSaved"
    />
  </div>
</template>

<style scoped>
@reference "../assets/main.css";
.page-header {
  @apply flex items-center justify-between mb-4;
}

.toolbar {
  @apply flex flex-wrap items-center justify-between gap-3 mb-4;
}

.toolbar-filters {
  @apply flex flex-wrap items-center gap-2;
}

.toolbar-right {
  @apply flex items-center gap-2;
}

.search-input {
  @apply w-52;
}

.per-page-select {
  @apply w-20;
}

.loading-center {
  @apply flex justify-center py-12;
}

.projects-grid {
  @apply grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4;
}

.project-card {
  @apply bg-white rounded-lg border border-gray-200 shadow-sm p-4 cursor-pointer hover:shadow-md transition-shadow;
}

.project-card-header {
  @apply flex items-start gap-3 mb-4;
}

.project-card-info {
  @apply flex-1 min-w-0;
}

.project-card-name {
  @apply text-sm font-semibold text-gray-900 truncate;
}

.project-card-actions {
  @apply flex gap-1 shrink-0;
}

.project-card-stats {
  @apply grid grid-cols-2 gap-2 mb-3;
}

.project-stat {
  @apply flex flex-col;
}

.project-stat-label {
  @apply text-xs text-gray-500;
}

.project-stat-value {
  @apply text-sm font-semibold text-gray-900 tabular-nums;
}

.project-budget-bar {
  @apply flex items-center gap-2 mb-3;
}

.budget-bar-bg {
  @apply flex-1 h-2 rounded-full bg-gray-200 overflow-hidden;
}

.budget-bar-fill {
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

.budget-bar-text {
  @apply text-xs font-medium text-gray-500 tabular-nums w-10 text-right;
}

.project-card-footer {
  @apply flex items-center gap-2;
}

.btn-icon-sm {
  @apply h-4 w-4;
}

.pagination {
  @apply flex items-center gap-2 mt-6;
}

.pagination-pages {
  @apply flex gap-1;
}

.page-btn {
  @apply w-8 h-8 rounded text-sm text-gray-700 hover:bg-gray-100 transition-colors;
}

.page-btn-active {
  @apply w-8 h-8 rounded text-sm font-semibold bg-primary-600 text-white;
}

.pagination-info {
  @apply text-sm text-gray-500 ml-2;
}
</style>
