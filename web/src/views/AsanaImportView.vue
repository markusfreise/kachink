<script setup lang="ts">
import { ref, computed, onMounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import api from '@/api/client'
import type { Client } from '@/types'
import { useToastStore, errorMessage } from '@/stores/toast'
import ComboBox from '@/components/ComboBox.vue'
import { formatCurrency, formatDate } from '@/utils/format'
import { ArrowLeftIcon, ArrowPathIcon, CheckCircleIcon } from '@heroicons/vue/24/outline'

interface AsanaProject {
  gid: string
  name: string
  num_incomplete_tasks: number | null
  num_completed_tasks: number | null
  client: { id: string; name: string; color: string } | null
}

interface AsanaTask {
  gid: string
  name: string
  notes: string
  due_on: string | null
  assignee: string | null
  section: string | null
  tags: string[]
  num_subtasks: number
  amount: number | null
  imported: { type: 'task'; id: string; project_id: string; project_name: string | null } | { type: 'project'; id: string; name: string } | null
}

type Mode = 'skip' | 'task' | 'task_new_project' | 'project'

interface Decision {
  mode: Mode
  project_id: string
  new_project_name: string
}

interface Summary {
  tasks_created: number
  tasks_updated: number
  projects_created: number
  comments: number
  errors: string[]
  completed_remaining: number
}

const { t } = useI18n()
const toast = useToastStore()

const settings = ref<{ configured: boolean; workspace_gid: string | null; amount_field: string; done_project_name: string } | null>(null)
const token = ref('')
const savingToken = ref(false)

const projects = ref<AsanaProject[]>([])
const projectsLoading = ref(false)
const clients = ref<Client[]>([])
const mappingBusy = ref<string | null>(null)
const filter = ref('')

const selected = ref<AsanaProject | null>(null)
const tasks = ref<AsanaTask[]>([])
const tasksLoading = ref(false)
const clientProjects = ref<{ id: string; name: string; color: string }[]>([])
const completedImported = ref(0)
const decisions = ref<Record<string, Decision>>({})
const importCompleted = ref(true)
const stepTwo = ref<HTMLElement | null>(null)
const withComments = ref(true)
const completedWithComments = ref(false)
const sectionsAsStatus = ref(true)
const importing = ref(false)

// Batch: tick tasks, then apply one decision to all of them
const checked = ref<Set<string>>(new Set())
const batchMode = ref<Mode>('task')
const batchProjectId = ref('')
const batchNewProjectName = ref('')
const allChecked = computed(() => tasks.value.length > 0 && tasks.value.every((task) => checked.value.has(task.gid)))

interface SectionGroup {
  name: string | null
  tasks: AsanaTask[]
}

/** Tasks grouped by their Asana section, in the order they appear. */
const sections = computed<SectionGroup[]>(() => {
  const groups: SectionGroup[] = []
  for (const task of tasks.value) {
    const key = task.section ?? null
    let group = groups.find((g) => g.name === key)
    if (!group) {
      group = { name: key, tasks: [] }
      groups.push(group)
    }
    group.tasks.push(task)
  }
  return groups
})

function sectionChecked(group: SectionGroup) {
  return group.tasks.every((task) => checked.value.has(task.gid))
}

function toggleSection(group: SectionGroup) {
  const next = new Set(checked.value)
  const all = sectionChecked(group)
  for (const task of group.tasks) {
    if (all) next.delete(task.gid)
    else next.add(task.gid)
  }
  checked.value = next
}

function toggleChecked(gid: string) {
  const next = new Set(checked.value)
  if (next.has(gid)) next.delete(gid)
  else next.add(gid)
  checked.value = next
}

function toggleAll() {
  checked.value = allChecked.value ? new Set() : new Set(tasks.value.map((task) => task.gid))
}

function applyBatch() {
  for (const gid of checked.value) {
    const d = decisions.value[gid]
    const task = tasks.value.find((x) => x.gid === gid)
    if (!d || task?.imported?.type === 'project') continue
    d.mode = batchMode.value
    d.project_id = batchMode.value === 'task' ? batchProjectId.value : ''
    d.new_project_name = batchMode.value === 'task_new_project' ? batchNewProjectName.value : ''
  }
  checked.value = new Set()
}
const summary = ref<Summary | null>(null)
const totals = ref<Summary | null>(null)

const clientOptions = computed(() => clients.value.map((c) => ({ id: c.id, label: c.name, color: c.color })))
const projectOptions = computed(() => clientProjects.value.map((p) => ({ id: p.id, label: p.name, color: p.color })))
const filteredProjects = computed(() => {
  const q = filter.value.trim().toLowerCase()
  return q ? projects.value.filter((p) => p.name.toLowerCase().includes(q)) : projects.value
})
const decisionCount = computed(() => Object.values(decisions.value).filter((d) => d.mode !== 'skip').length)
const canImport = computed(() => !!selected.value?.client && (decisionCount.value > 0 || importCompleted.value) && !importing.value)

async function loadSettings() {
  try {
    const { data } = await api.get('/asana/settings')
    settings.value = data.data
    if (settings.value?.configured) await loadProjects()
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  }
}

async function saveToken() {
  if (!token.value.trim()) return
  savingToken.value = true
  try {
    const { data } = await api.put('/asana/settings', { token: token.value.trim() })
    token.value = ''
    toast.success(t('asana.tokenSaved', { name: data.data.user?.name ?? '', workspace: data.data.workspace_name ?? '' }))
    await loadSettings()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    savingToken.value = false
  }
}

async function removeToken() {
  try {
    await api.delete('/asana/settings')
    settings.value = { configured: false, workspace_gid: null, amount_field: 'Betrag', done_project_name: '' }
    projects.value = []
    selected.value = null
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
  }
}

async function loadProjects() {
  projectsLoading.value = true
  try {
    const [p, c] = await Promise.all([
      api.get('/asana/projects'),
      api.get('/clients', { params: { per_page: 500, sort: 'name' } }),
    ])
    projects.value = p.data.data
    clients.value = c.data.data
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    projectsLoading.value = false
  }
}

async function mapClient(project: AsanaProject, clientId: string) {
  mappingBusy.value = project.gid
  try {
    const { data } = await api.put(`/asana/projects/${project.gid}/client`, { client_id: clientId || null })
    project.client = data.data
    if (selected.value?.gid === project.gid) await loadTasks(project)
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    mappingBusy.value = null
  }
}

async function createClient(project: AsanaProject, name: string) {
  mappingBusy.value = project.gid
  try {
    const { data } = await api.put(`/asana/projects/${project.gid}/client`, { client_name: name })
    project.client = data.data
    const { data: c } = await api.get('/clients', { params: { per_page: 500, sort: 'name' } })
    clients.value = c.data
    toast.success(t('projectForm.clientCreated', { name }))
    if (selected.value?.gid === project.gid) await loadTasks(project)
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    mappingBusy.value = null
  }
}

async function loadTasks(project: AsanaProject) {
  selected.value = project
  summary.value = null
  totals.value = null
  tasksLoading.value = true
  await nextTick()
  stepTwo.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  try {
    const { data } = await api.get(`/asana/projects/${project.gid}/tasks`)
    tasks.value = data.data
    clientProjects.value = data.meta.projects ?? []
    completedImported.value = data.meta.completed_imported ?? 0
    const next: Record<string, Decision> = {}
    for (const task of tasks.value) {
      if (task.imported?.type === 'task') next[task.gid] = { mode: 'task', project_id: task.imported.project_id, new_project_name: '' }
      else if (task.imported?.type === 'project') next[task.gid] = { mode: 'project', project_id: '', new_project_name: '' }
      else next[task.gid] = { mode: 'skip', project_id: '', new_project_name: '' }
    }
    decisions.value = next
    checked.value = new Set()
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    tasksLoading.value = false
  }
}

function setAll(mode: Mode) {
  for (const task of tasks.value) {
    if (!task.imported) decisions.value[task.gid]!.mode = mode
  }
}

function decisionValid(task: AsanaTask): boolean {
  const d = decisions.value[task.gid]
  if (!d) return true
  if (d.mode === 'task') return !!d.project_id
  if (d.mode === 'task_new_project') return !!d.new_project_name.trim()
  return true
}

async function runImport() {
  if (!selected.value || !canImport.value) return
  const invalid = tasks.value.filter((task) => !decisionValid(task))
  if (invalid.length) {
    toast.error(t('asana.decisionIncomplete', { count: invalid.length }))
    return
  }
  importing.value = true
  summary.value = null
  const acc: Summary = { tasks_created: 0, tasks_updated: 0, projects_created: 0, comments: 0, errors: [], completed_remaining: 0 }
  try {
    let first = true
    let remaining = 0
    do {
      const { data } = await api.post(`/asana/projects/${selected.value.gid}/import`, {
        decisions: first
          ? Object.entries(decisions.value).map(([gid, d]) => ({ gid, mode: d.mode, project_id: d.project_id || null, new_project_name: d.new_project_name || null }))
          : [],
        import_completed: importCompleted.value,
        with_comments: withComments.value,
        completed_with_comments: completedWithComments.value,
        sections_as_status: sectionsAsStatus.value,
      })
      const s: Summary = data.data
      acc.tasks_created += s.tasks_created
      acc.tasks_updated += s.tasks_updated
      acc.projects_created += s.projects_created
      acc.comments += s.comments
      acc.errors.push(...s.errors)
      remaining = s.completed_remaining
      totals.value = { ...acc, completed_remaining: remaining }
      first = false
    } while (remaining > 0 && importCompleted.value)
    summary.value = { ...acc, completed_remaining: 0 }
    toast.success(t('asana.done'))
    await loadTasks(selected.value)
    await loadProjects()
  } catch (e) {
    toast.error(errorMessage(e, t('asana.failed')))
  } finally {
    importing.value = false
  }
}

onMounted(loadSettings)
</script>

<template>
  <div class="page asana">
    <RouterLink class="asana__back" :to="{ name: 'settings' }">
      <ArrowLeftIcon class="asana__back-icon" aria-hidden="true" />
      {{ $t('nav.settings') }}
    </RouterLink>

    <div class="page__header">
      <div>
        <h1 class="heading-1 page__title">{{ $t('asana.title') }}</h1>
        <p class="page__subtitle small">{{ $t('asana.intro') }}</p>
      </div>
    </div>

    <section class="card page__section">
      <div class="card__header">
        <h2 class="card__title">{{ $t('asana.tokenTitle') }}</h2>
        <span v-if="settings?.configured" class="badge badge--success">{{ $t('asana.connected') }}</span>
      </div>
      <div class="card__body">
        <form v-if="!settings?.configured" class="form asana__token" @submit.prevent="saveToken">
          <div class="form__group">
            <label class="form__label" for="asana-token">{{ $t('asana.token') }}</label>
            <input id="asana-token" v-model="token" type="password" class="form__input" autocomplete="off" :placeholder="$t('asana.tokenPlaceholder')" />
            <span class="form__hint">{{ $t('asana.tokenHint') }}</span>
          </div>
          <div class="form__actions">
            <button type="submit" class="btn btn--primary" :disabled="savingToken || !token.trim()">{{ savingToken ? $t('common.saving') : $t('asana.connect') }}</button>
          </div>
        </form>
        <div v-else class="asana__connected">
          <p class="muted">{{ $t('asana.connectedText') }}</p>
          <button type="button" class="btn btn--ghost btn--sm" @click="removeToken">{{ $t('asana.disconnect') }}</button>
        </div>
      </div>
    </section>

    <template v-if="settings?.configured">
      <section class="card page__section">
        <div class="card__header">
          <div>
            <h2 class="card__title">{{ $t('asana.step1') }}</h2>
            <p class="small muted">{{ $t('asana.step1Text') }}</p>
          </div>
          <div class="asana__head-actions">
            <input v-model="filter" type="search" class="form__input form__input--sm" :placeholder="$t('asana.filterProjects')" :aria-label="$t('asana.filterProjects')" />
            <button type="button" class="btn btn--ghost btn--icon btn--sm" :aria-label="$t('common.retry')" :disabled="projectsLoading" @click="loadProjects">
              <ArrowPathIcon class="btn__icon" aria-hidden="true" />
            </button>
          </div>
        </div>
        <div v-if="projectsLoading && projects.length === 0" class="loading"><span class="spinner" role="status"></span></div>
        <div v-else class="table-wrap">
          <table class="table table--compact asana__projects">
            <thead>
              <tr>
                <th>{{ $t('asana.asanaProject') }}</th>
                <th class="table__num">{{ $t('tasks.open') }}</th>
                <th class="table__num">{{ $t('tasks.done') }}</th>
                <th>{{ $t('asana.client') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in filteredProjects" :key="p.gid" class="table__row" :class="{ 'asana__row--active': selected?.gid === p.gid }">
                <td><span class="cell__title">{{ p.name }}</span></td>
                <td class="table__num">{{ p.num_incomplete_tasks ?? '–' }}</td>
                <td class="table__num table__muted">{{ p.num_completed_tasks ?? '–' }}</td>
                <td class="asana__client-cell">
                  <ComboBox
                    :model-value="p.client?.id ?? ''"
                    :options="clientOptions"
                    :placeholder="$t('asana.chooseClient')"
                    :clear-label="$t('asana.noClient')"
                    clearable
                    allow-create
                    size="sm"
                    :disabled="mappingBusy === p.gid"
                    @update:model-value="(id) => mapClient(p, id)"
                    @create="(name) => createClient(p, name)"
                  />
                </td>
                <td class="table__num">
                  <button type="button" class="btn btn--secondary btn--sm" :disabled="!p.client" @click="loadTasks(p)">{{ $t('asana.showTasks') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="selected" ref="stepTwo" class="card page__section asana__step2">
        <div class="card__header">
          <div>
            <h2 class="card__title">{{ $t('asana.step2', { name: selected.name }) }}</h2>
            <p class="small muted">{{ $t('asana.step2Text', { client: selected.client?.name ?? '' }) }}</p>
          </div>
          <div class="asana__head-actions">
            <button type="button" class="btn btn--ghost btn--sm" @click="setAll('skip')">{{ $t('asana.allSkip') }}</button>
          </div>
        </div>

        <div v-if="tasksLoading" class="loading"><span class="spinner" role="status"></span></div>
        <template v-else>
          <p v-if="tasks.length === 0" class="empty__text asana__empty">{{ $t('asana.noOpenTasks') }}</p>
          <template v-else>
          <div class="asana__batch form">
            <label class="form__check asana__batch-all">
              <input type="checkbox" :checked="allChecked" @change="toggleAll" />
              <span>{{ checked.size ? $t('asana.selected', { count: checked.size }) : $t('asana.selectAll') }}</span>
            </label>
            <div class="asana__batch-controls" :class="{ 'asana__batch-controls--idle': checked.size === 0 }">
              <select v-model="batchMode" class="form__select form__select--sm form__select--inline" :aria-label="$t('asana.decision')">
                <option value="skip">{{ $t('asana.modeSkip') }}</option>
                <option value="task">{{ $t('asana.modeTask') }}</option>
                <option value="task_new_project">{{ $t('asana.modeTaskNewProject') }}</option>
                <option value="project">{{ $t('asana.modeProject') }}</option>
              </select>
              <ComboBox v-if="batchMode === 'task'" v-model="batchProjectId" :options="projectOptions" :placeholder="$t('asana.chooseProject')" size="sm" class="asana__batch-project" />
              <input v-if="batchMode === 'task_new_project'" v-model="batchNewProjectName" type="text" class="form__input form__input--sm asana__batch-project" :placeholder="$t('asana.newProjectName')" :aria-label="$t('asana.newProjectName')" />
              <button type="button" class="btn btn--secondary btn--sm" :disabled="checked.size === 0" @click="applyBatch">{{ $t('asana.applyToSelected', { count: checked.size }) }}</button>
            </div>
          </div>
          <ul class="asana__tasks">
            <template v-for="group in sections" :key="group.name ?? '__none'">
            <li class="asana__section">
              <label class="form__check asana__section-check">
                <input type="checkbox" :checked="sectionChecked(group)" @change="toggleSection(group)" />
                <span class="asana__section-name">{{ group.name ?? $t('asana.noSection') }}</span>
                <span class="asana__section-count">{{ group.tasks.length }}</span>
              </label>
            </li>
            <li v-for="task in group.tasks" :key="task.gid" class="asana__task" :class="{ 'asana__task--imported': task.imported, 'asana__task--invalid': !decisionValid(task), 'asana__task--checked': checked.has(task.gid) }">
              <label class="asana__task-check">
                <input type="checkbox" :checked="checked.has(task.gid)" :aria-label="task.name" @change="toggleChecked(task.gid)" />
              </label>
              <div class="asana__task-main">
                <div class="asana__task-title">
                  <CheckCircleIcon v-if="task.imported" class="asana__task-imported-icon" aria-hidden="true" />
                  {{ task.name }}
                </div>
                <div class="asana__task-meta">
                  <span v-if="task.assignee">{{ task.assignee }}</span>
                  <span v-if="task.due_on">{{ formatDate(task.due_on) }}</span>
                  <span v-if="task.amount != null">{{ formatCurrency(task.amount) }}</span>
                  <span v-if="task.num_subtasks">{{ $t('asana.subtasks', { count: task.num_subtasks }) }}</span>
                  <span v-for="tag in task.tags" :key="tag" class="task-row__tag">{{ tag }}</span>
                  <span v-if="task.imported?.type === 'task'" class="badge badge--success">{{ $t('asana.importedAsTask', { project: task.imported.project_name ?? '' }) }}</span>
                  <span v-else-if="task.imported?.type === 'project'" class="badge badge--success">{{ $t('asana.importedAsProject') }}</span>
                </div>
                <p v-if="task.notes" class="asana__task-notes">{{ task.notes }}</p>
              </div>
              <div class="asana__task-decision">
                <select v-model="decisions[task.gid]!.mode" class="form__select form__select--sm" :aria-label="$t('asana.decision')" :disabled="task.imported?.type === 'project'">
                  <option value="skip">{{ task.imported ? $t('asana.modeKeep') : $t('asana.modeSkip') }}</option>
                  <option value="task">{{ $t('asana.modeTask') }}</option>
                  <option value="task_new_project">{{ $t('asana.modeTaskNewProject') }}</option>
                  <option value="project">{{ $t('asana.modeProject') }}</option>
                </select>
                <ComboBox
                  v-if="decisions[task.gid]!.mode === 'task'"
                  v-model="decisions[task.gid]!.project_id"
                  :options="projectOptions"
                  :placeholder="$t('asana.chooseProject')"
                  size="sm"
                />
                <input
                  v-if="decisions[task.gid]!.mode === 'task_new_project'"
                  v-model="decisions[task.gid]!.new_project_name"
                  type="text"
                  class="form__input form__input--sm"
                  :placeholder="$t('asana.newProjectName')"
                  :aria-label="$t('asana.newProjectName')"
                />
              </div>
            </li>
            </template>
          </ul>
          </template>

          <div class="asana__options form">
            <label class="form__check">
              <input v-model="sectionsAsStatus" type="checkbox" />
              <span>{{ $t('asana.sectionsAsStatus') }}</span>
            </label>
            <label class="form__check">
              <input v-model="withComments" type="checkbox" />
              <span>{{ $t('asana.withComments') }}</span>
            </label>
            <label class="form__check">
              <input v-model="importCompleted" type="checkbox" />
              <span>{{ $t('asana.importCompleted', { project: settings.done_project_name, count: selected.num_completed_tasks ?? 0 }) }}</span>
            </label>
            <label v-if="importCompleted" class="form__check asana__sub-option">
              <input v-model="completedWithComments" type="checkbox" />
              <span>{{ $t('asana.completedWithComments') }}</span>
            </label>
            <p v-if="completedImported" class="form__hint">{{ $t('asana.completedAlready', { count: completedImported }) }}</p>
          </div>

          <div class="asana__run">
            <span class="muted small">{{ $t('asana.decisionCount', { count: decisionCount }) }}</span>
            <button type="button" class="btn btn--primary" :disabled="!canImport" @click="runImport">
              <ArrowPathIcon v-if="importing" class="btn__icon asana__spin" aria-hidden="true" />
              {{ importing ? $t('asana.importing') : $t('asana.run') }}
            </button>
          </div>

          <div v-if="importing && totals" class="alert alert--info">
            {{ $t('asana.progress', { tasks: totals.tasks_created + totals.tasks_updated, remaining: totals.completed_remaining }) }}
          </div>
          <div v-else-if="summary" class="alert" :class="summary.errors.length ? 'alert--warning' : 'alert--success'">
            <p class="asana__summary-line">{{ $t('asana.summary', summary) }}</p>
            <ul v-if="summary.errors.length" class="asana__errors">
              <li v-for="(err, i) in summary.errors" :key="i">{{ err }}</li>
            </ul>
          </div>
        </template>
      </section>
    </template>
  </div>
</template>
