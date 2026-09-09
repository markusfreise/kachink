<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { Project, Client, PaginationMeta } from '@/types'
import { PlusIcon, FolderIcon, DocumentTextIcon } from '@heroicons/vue/24/outline'
import ProjectFormModal from '@/components/ProjectFormModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import AppPagination from '@/components/AppPagination.vue'
import ComboBox from '@/components/ComboBox.vue'
import { useToastStore, errorMessage } from '@/stores/toast'
import { formatDuration, formatHoursDecimal, formatCurrency } from '@/utils/format'

/** Project with the time summary the API adds for `include_time_summary=1`. */
interface ProjectWithSummary extends Project {
  tracked_seconds?: number
  billable_seconds?: number
  billable_hours?: number
}

type StatusFilter = 'active' | 'archived' | 'all'

const { t } = useI18n()
const router = useRouter()
const toast = useToastStore()

const projects = ref<ProjectWithSummary[]>([])
const clients = ref<Client[]>([])
const loading = ref(true)
const showForm = ref(false)
const editingProject = ref<Project | null>(null)
const archiving = ref<Project | null>(null)
const archiveBusy = ref(false)

const page = ref(1)
const perPage = ref(25)
const meta = ref<PaginationMeta | null>(null)
const search = ref('')
const sort = ref('name')
const clientFilter = ref('')
const status = ref<StatusFilter>('active')

const perPageOptions = [10, 25, 50, 100]
const sortOptions = computed(() => [
  { value: 'name', label: t('projects.sortName') },
  { value: '-name', label: t('projects.sortNameDesc') },
  { value: '-created_at', label: t('projects.sortNewest') },
  { value: 'created_at', label: t('projects.sortOldest') },
])
const statusOptions = computed<{ value: StatusFilter; label: string }[]>(() => [
  { value: 'active', label: t('common.active') },
  { value: 'archived', label: t('common.archived') },
  { value: 'all', label: t('common.all') },
])

const clientOptions = computed(() => clients.value.map((c) => ({ id: c.id, label: c.name, color: c.color })))
const hasFilters = computed(() => search.value !== '' || clientFilter.value !== '' || status.value !== 'active')

let searchTimer: ReturnType<typeof setTimeout> | null = null

async function fetchProjects() {
  loading.value = true
  try {
    const params: Record<string, unknown> = {
      per_page: perPage.value,
      page: page.value,
      include_time_summary: 1,
      sort: sort.value,
    }
    if (status.value !== 'all') params['filter[is_active]'] = status.value === 'active' ? 1 : 0
    if (search.value) params['filter[name]'] = search.value
    if (clientFilter.value) params['filter[client_id]'] = clientFilter.value
    const { data } = await api.get('/projects', { params })
    projects.value = data.data
    meta.value = data.meta ?? null
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
  }
}

async function fetchClients() {
  try {
    const { data } = await api.get('/clients', { params: { 'filter[is_active]': 1, per_page: 500, sort: 'name' } })
    clients.value = data.data
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  }
}

function resetAndFetch() {
  page.value = 1
  fetchProjects()
}

watch(perPage, resetAndFetch)
watch(page, fetchProjects)
watch(sort, resetAndFetch)
watch(clientFilter, resetAndFetch)
watch(status, resetAndFetch)
watch(search, () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(resetAndFetch, 300)
})

function resetFilters() {
  search.value = ''
  clientFilter.value = ''
  status.value = 'active'
}

function openProject(project: Project) {
  router.push({ name: 'project-detail', params: { id: project.id } })
}

function reportRoute(project: Project) {
  return { name: 'report-detail', params: { scope: 'projects', id: project.id } }
}

function openCreate() {
  editingProject.value = null
  showForm.value = true
}

function openEdit(project: Project) {
  editingProject.value = project
  showForm.value = true
}

async function setActive(project: Project, isActive: boolean) {
  archiveBusy.value = true
  try {
    await api.put(`/projects/${project.id}`, { is_active: isActive })
    toast.success(isActive ? t('projects.restored') : t('projects.archived'))
    archiving.value = null
    await fetchProjects()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    archiveBusy.value = false
  }
}

function onSaved() {
  toast.success(editingProject.value ? t('projects.updated') : t('projects.created'))
  showForm.value = false
  fetchProjects()
}

function budgetTone(percent: number | null | undefined) {
  if (percent == null) return 'bar__fill--success'
  if (percent >= 100) return 'bar__fill--danger'
  if (percent >= 80) return 'bar__fill--warning'
  return 'bar__fill--success'
}

function budgetWidth(percent: number | null | undefined) {
  return `${Math.min(Math.max(percent ?? 0, 0), 100)}%`
}

function formatPercent(percent: number) {
  return `${Math.round(percent)}%`
}

onMounted(() => {
  fetchProjects()
  fetchClients()
})
</script>

<template>
  <div class="page projects">
    <div class="page__header">
      <div>
        <h1 class="heading-1">{{ $t('projects.title') }}</h1>
        <p v-if="meta" class="page__subtitle">{{ $t('projects.count', { count: meta.total }) }}</p>
      </div>
      <div class="page__actions">
        <button type="button" class="btn btn--primary" @click="openCreate">
          <PlusIcon class="btn__icon" aria-hidden="true" />
          {{ $t('projects.newProject') }}
        </button>
      </div>
    </div>

    <div class="toolbar">
      <div class="toolbar__group">
        <label class="sr-only" for="projects-search">{{ $t('projects.search') }}</label>
        <input
          id="projects-search"
          v-model="search"
          type="search"
          class="form__input form__input--sm toolbar__search"
          :placeholder="$t('projects.search')"
        />
        <div class="projects__client-filter">
          <ComboBox
            id="projects-client"
            v-model="clientFilter"
            :options="clientOptions"
            :placeholder="$t('projects.allClients')"
            :clear-label="$t('projects.allClients')"
            clearable
            size="sm"
          />
        </div>
        <div class="segmented" role="group" :aria-label="$t('projects.status')">
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
      </div>
      <div class="toolbar__group">
        <label class="toolbar__label" for="projects-sort">{{ $t('projects.sortBy') }}</label>
        <select id="projects-sort" v-model="sort" class="form__select form__select--sm form__select--inline">
          <option v-for="o in sortOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
        <label class="toolbar__label" for="projects-per-page">{{ $t('common.perPage') }}</label>
        <select id="projects-per-page" v-model="perPage" class="form__select form__select--sm form__select--inline">
          <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="loading">
      <span class="spinner" role="status" :aria-label="$t('common.loadFailed')"></span>
    </div>

    <div v-else-if="projects.length === 0" class="empty card">
      <FolderIcon class="empty__icon" aria-hidden="true" />
      <p class="empty__title">{{ hasFilters ? $t('projects.noResults') : $t('projects.noProjects') }}</p>
      <p class="empty__text">{{ hasFilters ? $t('projects.noResultsText') : $t('projects.noProjectsText') }}</p>
      <button v-if="hasFilters" type="button" class="btn btn--secondary btn--sm" @click="resetFilters">
        {{ $t('projects.resetFilters') }}
      </button>
      <button v-else type="button" class="btn btn--primary btn--sm" @click="openCreate">
        <PlusIcon class="btn__icon" aria-hidden="true" />
        {{ $t('projects.newProject') }}
      </button>
    </div>

    <div v-else class="grid grid--3">
      <article
        v-for="project in projects"
        :key="project.id"
        class="card card--clickable project-card"
        :class="{ 'project-card--archived': !project.is_active }"
        @click="openProject(project)"
      >
        <div class="project-card__head">
          <span class="color-dot color-dot--lg project-card__dot" :style="{ backgroundColor: project.color }" aria-hidden="true"></span>
          <div class="project-card__ident">
            <h2 class="project-card__name">
              <RouterLink
                class="project-card__link"
                :to="{ name: 'project-detail', params: { id: project.id } }"
                :aria-label="$t('projects.openProject', { name: project.name })"
                @click.stop
              >
                {{ project.name }}
              </RouterLink>
            </h2>
            <p class="project-card__client">{{ project.client?.name }}</p>
          </div>
          <div class="project-card__badges">
            <span v-if="!project.is_active" class="badge badge--neutral">{{ $t('common.archived') }}</span>
            <span class="badge" :class="project.is_billable ? 'badge--success' : 'badge--neutral'">
              {{ project.is_billable ? $t('common.billable') : $t('common.nonBillable') }}
            </span>
          </div>
        </div>

        <div class="project-card__budget">
          <template v-if="project.budget_hours">
            <div class="project-card__budget-row">
              <span class="project-card__budget-label">{{ $t('projects.budget') }}</span>
              <span class="project-card__budget-value">
                {{ $t('projects.budgetUsage', { used: formatHoursDecimal(project.tracked_seconds), total: formatHoursDecimal(project.budget_hours * 3600) }) }}
                <span class="project-card__budget-percent" :class="{ 'project-card__budget-percent--over': (project.budget_used_percentage ?? 0) >= 100 }">
                  {{ formatPercent(project.budget_used_percentage ?? 0) }}
                </span>
              </span>
            </div>
            <div class="bar bar--block" role="progressbar" :aria-valuenow="Math.round(project.budget_used_percentage ?? 0)" aria-valuemin="0" aria-valuemax="100">
              <span class="bar__fill" :class="budgetTone(project.budget_used_percentage)" :style="{ width: budgetWidth(project.budget_used_percentage) }"></span>
            </div>
          </template>
          <div v-else class="project-card__budget-row">
            <span class="project-card__budget-label">{{ $t('projects.budget') }}</span>
            <span class="project-card__budget-value project-card__budget-value--muted">{{ $t('projects.noBudget') }}</span>
          </div>
        </div>

        <dl class="project-card__stats">
          <div class="project-card__stat">
            <dt class="project-card__stat-label">{{ $t('projects.tracked') }}</dt>
            <dd class="project-card__stat-value">{{ $t('projects.trackedValue', { hours: formatDuration(project.tracked_seconds) }) }}</dd>
          </div>
          <div class="project-card__stat">
            <dt class="project-card__stat-label">{{ $t('projects.rate') }}</dt>
            <dd class="project-card__stat-value" :class="{ 'project-card__stat-value--muted': project.hourly_rate == null }">
              {{ project.hourly_rate != null ? $t('projects.ratePerHour', { rate: formatCurrency(Number(project.hourly_rate)) }) : '–' }}
            </dd>
          </div>
        </dl>

        <div class="project-card__actions" @click.stop>
          <RouterLink class="btn btn--ghost btn--sm" :to="reportRoute(project)">
            <DocumentTextIcon class="btn__icon" aria-hidden="true" />
            {{ $t('reportDetail.report') }}
          </RouterLink>
          <button type="button" class="btn btn--ghost btn--sm" @click="openEdit(project)">{{ $t('common.edit') }}</button>
          <button v-if="project.is_active" type="button" class="btn btn--ghost btn--sm" @click="archiving = project">
            {{ $t('common.archive') }}
          </button>
          <button v-else type="button" class="btn btn--ghost btn--sm" :disabled="archiveBusy" @click="setActive(project, true)">
            {{ $t('projects.unarchive') }}
          </button>
        </div>
      </article>
    </div>

    <AppPagination :meta="meta" :page="page" @update:page="page = $event" />

    <ProjectFormModal
      v-if="showForm"
      :project="editingProject"
      :clients="clients"
      @close="showForm = false"
      @saved="onSaved"
    />

    <ConfirmDialog
      v-if="archiving"
      :title="$t('projects.archiveTitle')"
      :text="$t('projects.archiveText', { name: archiving.name })"
      :confirm-label="$t('common.archive')"
      :busy="archiveBusy"
      @confirm="setActive(archiving, false)"
      @cancel="archiving = null"
    />
  </div>
</template>
