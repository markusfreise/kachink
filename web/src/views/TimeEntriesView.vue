<script setup lang="ts">
import { RouterLink } from 'vue-router'
import UserAvatar from '@/components/UserAvatar.vue'
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { TimeEntry, Project, Task, User, PaginationMeta } from '@/types'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useToastStore, errorMessage } from '@/stores/toast'
import { formatDuration, formatDateLong, formatTime, toDateString } from '@/utils/format'
import { PencilIcon, TrashIcon, PlusIcon, ClockIcon } from '@heroicons/vue/24/outline'
import TimeEntryModal from '@/components/TimeEntryModal.vue'
import ComboBox from '@/components/ComboBox.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import AppPagination from '@/components/AppPagination.vue'

type Preset = 'today' | 'week' | 'lastWeek' | 'month' | 'custom'
type BillableFilter = 'all' | 'billable' | 'nonBillable'

interface DayGroup {
  date: string
  label: string
  totalSeconds: number
  entries: TimeEntry[]
}

const { t } = useI18n()
const auth = useAuthStore()
const settings = useSettingsStore()
const toast = useToastStore()

const entries = ref<TimeEntry[]>([])
const projects = ref<Project[]>([])
const tasks = ref<Task[]>([])
const users = ref<User[]>([])
const loading = ref(true)
const meta = ref<PaginationMeta | null>(null)
const page = ref(1)
const PER_PAGE = 50

// Modal / dialogs
const showModal = ref(false)
const editingEntry = ref<TimeEntry | null>(null)
const deletingEntry = ref<TimeEntry | null>(null)
const deleting = ref(false)

// Filters
const preset = ref<Preset>('week')
const dateFrom = ref('')
const dateTo = ref('')
const filterProjectId = ref('')
const billableFilter = ref<BillableFilter>('all')
const memberFilter = ref<string>('me') // 'me' | 'all' | user id

const presets: { id: Preset; label: () => string }[] = [
  { id: 'today', label: () => t('common.today') },
  { id: 'week', label: () => t('timeEntries.thisWeek') },
  { id: 'lastWeek', label: () => t('timeEntries.lastWeek') },
  { id: 'month', label: () => t('timeEntries.thisMonth') },
  { id: 'custom', label: () => t('timeEntries.custom') },
]

const billableOptions: { id: BillableFilter; label: () => string }[] = [
  { id: 'all', label: () => t('common.all') },
  { id: 'billable', label: () => t('common.billable') },
  { id: 'nonBillable', label: () => t('common.nonBillable') },
]

function rangeForPreset(id: Preset): { from: string; to: string } | null {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  if (id === 'today') return { from: toDateString(today), to: toDateString(today) }
  if (id === 'week' || id === 'lastWeek') {
    const monday = new Date(today)
    const offset = (today.getDay() + 6) % 7 // Monday = 0
    monday.setDate(today.getDate() - offset - (id === 'lastWeek' ? 7 : 0))
    const sunday = new Date(monday)
    sunday.setDate(monday.getDate() + 6)
    return { from: toDateString(monday), to: toDateString(sunday) }
  }
  if (id === 'month') {
    return {
      from: toDateString(new Date(today.getFullYear(), today.getMonth(), 1)),
      to: toDateString(new Date(today.getFullYear(), today.getMonth() + 1, 0)),
    }
  }
  return null
}

function selectPreset(id: Preset) {
  preset.value = id
  const range = rangeForPreset(id)
  if (range) {
    dateFrom.value = range.from
    dateTo.value = range.to
  }
}

function onCustomDate() {
  preset.value = 'custom'
}

const projectOptions = computed(() =>
  projects.value.map((p) => ({ id: p.id, label: p.name, subtitle: p.client?.name, color: p.color }))
)

const showMemberFilter = computed(() => auth.isAdmin && users.value.length > 1)
const otherUsers = computed(() => users.value.filter((u) => u.id !== auth.user?.id))
const showUserColumn = computed(() => auth.isAdmin && memberFilter.value === 'all')

async function fetchEntries() {
  loading.value = true
  try {
    const params: Record<string, string | number> = {
      'filter[date_from]': dateFrom.value,
      'filter[date_to]': dateTo.value,
      per_page: PER_PAGE,
      page: page.value,
      sort: '-started_at',
    }
    if (filterProjectId.value) params['filter[project_id]'] = filterProjectId.value
    if (billableFilter.value !== 'all') params['filter[is_billable]'] = billableFilter.value === 'billable' ? 1 : 0
    if (auth.isAdmin && memberFilter.value !== 'me') {
      params.all_users = 1
      if (memberFilter.value !== 'all') params['filter[user_id]'] = memberFilter.value
    }
    const { data } = await api.get('/time-entries', { params })
    entries.value = data.data
    meta.value = data.meta ?? null
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
  }
}

async function fetchLookups() {
  try {
    const requests: Promise<{ data: { data: unknown[] } }>[] = [
      api.get('/projects', { params: { 'filter[is_active]': true, per_page: 100 } }),
      api.get('/tasks'),
    ]
    if (auth.isAdmin) requests.push(api.get('/users', { params: { 'filter[is_active]': true } }))
    const [projectsRes, tasksRes, usersRes] = await Promise.all(requests)
    projects.value = projectsRes!.data.data as Project[]
    tasks.value = tasksRes!.data.data as Task[]
    if (usersRes) users.value = usersRes.data.data as User[]
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  }
}

const entrySeconds = (entry: TimeEntry) => entry.duration_seconds ?? 0

const groups = computed<DayGroup[]>(() => {
  const map = new Map<string, DayGroup>()
  for (const entry of entries.value) {
    const key = toDateString(new Date(entry.started_at))
    let group = map.get(key)
    if (!group) {
      group = { date: key, label: formatDateLong(entry.started_at), totalSeconds: 0, entries: [] }
      map.set(key, group)
    }
    group.entries.push(entry)
    group.totalSeconds += entrySeconds(entry)
  }
  return Array.from(map.values())
})

const periodSeconds = computed(() => entries.value.reduce((sum, e) => sum + entrySeconds(e), 0))
const isPartialTotal = computed(() => (meta.value?.last_page ?? 1) > 1)

const columnCount = computed(() => 7 + (showUserColumn.value ? 1 : 0))

function openCreate() {
  editingEntry.value = null
  showModal.value = true
}

function openEdit(entry: TimeEntry) {
  editingEntry.value = entry
  showModal.value = true
}

function onSaved() {
  showModal.value = false
  editingEntry.value = null
  toast.success(t('timeEntries.saved'))
  fetchEntries()
}

async function confirmDelete() {
  if (!deletingEntry.value) return
  deleting.value = true
  try {
    await api.delete(`/time-entries/${deletingEntry.value.id}`)
    deletingEntry.value = null
    toast.success(t('timeEntries.deleted'))
    fetchEntries()
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
  } finally {
    deleting.value = false
  }
}

function timeRange(entry: TimeEntry): string {
  const start = formatTime(entry.started_at)
  return entry.stopped_at ? `${start} - ${formatTime(entry.stopped_at)}` : start
}

watch([dateFrom, dateTo, filterProjectId, billableFilter, memberFilter], () => {
  page.value = 1
  fetchEntries()
})
watch(page, fetchEntries)

onMounted(() => {
  selectPreset('week')
  fetchLookups()
})
</script>

<template>
  <div class="page time-entries">
    <div class="page__header">
      <h1 class="heading-1 page__title">{{ $t('timeEntries.title') }}</h1>
      <div class="page__actions">
        <button type="button" class="btn btn--primary" @click="openCreate">
          <PlusIcon class="btn__icon" />
          {{ $t('timeEntries.manualEntry') }}
        </button>
      </div>
    </div>

    <div class="time-entries__toolbar">
      <div class="time-entries__filters">
        <div class="segmented" role="group" :aria-label="$t('timeEntries.period')">
          <button
            v-for="p in presets"
            :key="p.id"
            type="button"
            class="segmented__item"
            :class="{ 'segmented__item--active': preset === p.id }"
            :aria-pressed="preset === p.id"
            @click="selectPreset(p.id)"
          >
            {{ p.label() }}
          </button>
        </div>

        <div class="time-entries__range">
          <label class="sr-only" for="te-from">{{ $t('common.from') }}</label>
          <input id="te-from" v-model="dateFrom" type="date" class="form__input form__input--sm" :max="dateTo" @change="onCustomDate" />
          <span class="time-entries__range-sep" aria-hidden="true">-</span>
          <label class="sr-only" for="te-to">{{ $t('common.to') }}</label>
          <input id="te-to" v-model="dateTo" type="date" class="form__input form__input--sm" :min="dateFrom" @change="onCustomDate" />
        </div>
      </div>

      <div class="time-entries__filters">
        <div class="time-entries__project">
          <label class="sr-only" for="te-filter-project">{{ $t('timeEntries.project') }}</label>
          <ComboBox
            id="te-filter-project"
            v-model="filterProjectId"
            :options="projectOptions"
            :placeholder="$t('timeEntries.allProjects')"
            :clearable="true"
            :clear-label="$t('timeEntries.allProjects')"
            size="sm"
          />
        </div>

        <div class="segmented" role="group" :aria-label="$t('timeEntries.billableFilter')">
          <button
            v-for="o in billableOptions"
            :key="o.id"
            type="button"
            class="segmented__item"
            :class="{ 'segmented__item--active': billableFilter === o.id }"
            :aria-pressed="billableFilter === o.id"
            @click="billableFilter = o.id"
          >
            {{ o.label() }}
          </button>
        </div>

        <div v-if="showMemberFilter" class="time-entries__member">
          <label class="sr-only" for="te-filter-member">{{ $t('timeEntries.teamMember') }}</label>
          <select id="te-filter-member" v-model="memberFilter" class="form__select form__select--sm">
            <option value="me">{{ auth.user?.name }}</option>
            <option value="all">{{ $t('timeEntries.allMembers') }}</option>
            <option v-for="u in otherUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
          </select>
        </div>
      </div>

      <div class="time-entries__summary">
        <div class="time-entries__total">
          <span>{{ $t('timeEntries.total') }}<template v-if="isPartialTotal"> ({{ $t('timeEntries.pageTotalNote') }})</template></span>
          <span class="time-entries__total-value">{{ formatDuration(periodSeconds) }}</span>
          <span v-if="meta" class="toolbar__count">{{ $t('common.entries', { count: meta.total }) }}</span>
        </div>
        <span v-if="settings.roundingInterval > 0" class="time-entries__note">
          {{ $t('timeEntries.roundingNote', { minutes: settings.roundingInterval }) }}
        </span>
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="loading">
        <span class="spinner" role="status" :aria-label="$t('common.loadFailed')"></span>
      </div>

      <div v-else-if="entries.length === 0" class="empty">
        <ClockIcon class="empty__icon" aria-hidden="true" />
        <p class="empty__title">{{ $t('timeEntries.noEntries') }}</p>
        <p class="empty__text">{{ $t('timeEntries.noEntriesHint') }}</p>
      </div>

      <div v-else class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>{{ $t('common.date') }}</th>
              <th>{{ $t('timeEntries.project') }}</th>
              <th>{{ $t('timeEntries.task') }}</th>
              <th>{{ $t('timeEntries.description') }}</th>
              <th v-if="showUserColumn">{{ $t('timeEntries.teamMember') }}</th>
              <th class="time-entries__billable">{{ $t('timeEntries.billable') }}</th>
              <th class="table__num">{{ $t('timeEntries.duration') }}</th>
              <th class="time-entries__actions"><span class="sr-only">{{ $t('common.actions') }}</span></th>
            </tr>
          </thead>
          <tbody v-for="group in groups" :key="group.date">
            <tr class="table__group">
              <td :colspan="columnCount">
                <div class="time-entries__group-cell">
                  <span>{{ group.label }}</span>
                  <span class="time-entries__group-total">{{ formatDuration(group.totalSeconds) }}</span>
                </div>
              </td>
            </tr>
            <tr v-for="entry in group.entries" :key="entry.id" class="table__row">
              <td class="table__time">{{ timeRange(entry) }}</td>
              <td>
                <div class="cell time-entries__project-cell">
                  <span class="color-dot" :style="{ backgroundColor: entry.project?.color }"></span>
                  <div class="cell__stack">
                    <span class="cell__title">{{ entry.project?.name }}</span>
                    <span v-if="entry.project?.client?.name" class="cell__sub">{{ entry.project.client.name }}</span>
                  </div>
                </div>
              </td>
              <td :class="{ table__muted: !entry.task }">{{ entry.task?.name ?? $t('timeEntries.noTask') }}</td>
              <td class="time-entries__desc" :class="{ table__muted: !entry.description }" :title="entry.description ?? undefined">
                <RouterLink v-if="entry.project_task" class="badge badge--brand time-entries__ptask" :to="{ name: 'task-detail', params: { id: entry.project_task.id } }">{{ entry.project_task.title }}</RouterLink>
                {{ entry.description || $t('timeEntries.noDescription') }}
              </td>
              <td v-if="showUserColumn">
                <span class="cell">
                  <UserAvatar :name="entry.user?.name" :avatar-url="entry.user?.avatar_url" size="sm" />
                  <span class="cell__title">{{ entry.user?.name }}</span>
                </span>
              </td>
              <td class="time-entries__billable">
                <span
                  class="time-entries__billable-dot"
                  :class="{ 'time-entries__billable-dot--on': entry.is_billable }"
                  :title="entry.is_billable ? $t('common.billable') : $t('common.nonBillable')"
                ></span>
                <span class="sr-only">{{ entry.is_billable ? $t('common.billable') : $t('common.nonBillable') }}</span>
              </td>
              <td class="table__num time-entries__duration">
                <span v-if="entry.is_running" class="badge badge--success">{{ $t('timeEntries.running') }}</span>
                <template v-else>{{ formatDuration(entry.duration_seconds) }}</template>
              </td>
              <td class="time-entries__actions">
                <div class="table__actions">
                  <button
                    type="button"
                    class="btn btn--ghost btn--icon btn--sm"
                    :aria-label="$t('timeEntries.editEntry')"
                    :title="$t('common.edit')"
                    :disabled="entry.is_running"
                    @click="openEdit(entry)"
                  >
                    <PencilIcon class="btn__icon" />
                  </button>
                  <button
                    type="button"
                    class="btn btn--danger-ghost btn--icon btn--sm"
                    :aria-label="$t('timeEntries.deleteEntry')"
                    :title="$t('common.delete')"
                    @click="deletingEntry = entry"
                  >
                    <TrashIcon class="btn__icon" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
          <tfoot v-if="groups.length > 1">
            <tr class="table__total">
              <td :colspan="columnCount - 2">{{ $t('timeEntries.total') }}</td>
              <td class="table__num">{{ formatDuration(periodSeconds) }}</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <AppPagination :meta="meta" :page="page" @update:page="page = $event" />

    <TimeEntryModal
      v-if="showModal"
      :projects="projects"
      :tasks="tasks"
      :entry="editingEntry"
      @close="showModal = false"
      @saved="onSaved"
    />

    <ConfirmDialog
      v-if="deletingEntry"
      :title="$t('timeEntries.deleteTitle')"
      :text="$t('timeEntries.deleteText')"
      :confirm-label="$t('common.delete')"
      danger
      :busy="deleting"
      @confirm="confirmDelete"
      @cancel="deletingEntry = null"
    />
  </div>
</template>
