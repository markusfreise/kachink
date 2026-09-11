<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { Project, Task, TimeEntry, ProjectTaskRef } from '@/types'
import BaseModal from '@/components/BaseModal.vue'
import ComboBox from '@/components/ComboBox.vue'
import { errorMessage } from '@/stores/toast'
import { formatDuration, toDateString } from '@/utils/format'

type Mode = 'range' | 'duration'

const props = defineProps<{
  projects: Project[]
  tasks: Task[]
  entry?: TimeEntry | null
  /** Book the new entry on this project task (preselects its project). */
  projectTask?: (ProjectTaskRef & { project_id: string }) | null
}>()

const emit = defineEmits<{
  close: []
  saved: [entry: TimeEntry]
}>()

const { t } = useI18n()

const isEdit = computed(() => !!props.entry)

const projectId = ref('')
const taskId = ref('')
const description = ref('')
const date = ref(toDateString(new Date()))
const startTime = ref('')
const endTime = ref('')
const durationInput = ref('')
const isBillable = ref(true)
const createTask = ref(false)
const completeTask = ref(false)
const mode = ref<Mode>('duration')
const saving = ref(false)
const error = ref('')

const projectOptions = computed(() =>
  props.projects.map((p) => ({
    id: p.id,
    label: p.name,
    subtitle: p.client?.name,
    color: p.color,
  }))
)

const taskOptions = computed(() => props.tasks.map((task) => ({ id: task.id, label: task.name })))

/** "HH:MM" in local time */
function toTimeInput(d: Date): string {
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

/** Local date + "HH:MM" -> Date */
function toLocalDate(dateStr: string, time: string): Date {
  return new Date(`${dateStr}T${time}:00`)
}

/**
 * Parses a duration string into seconds.
 * "1:30" -> h:mm, "1.5" / "1,5" -> decimal hours, "90" -> minutes,
 * "1h 30m" / "1h" / "45m" -> explicit units.
 */
function parseDuration(input: string): number | null {
  const raw = input.trim().toLowerCase()
  if (!raw) return null

  let m = raw.match(/^(\d{1,3}):([0-5]?\d)$/)
  if (m) return parseInt(m[1]!, 10) * 3600 + parseInt(m[2]!, 10) * 60

  m = raw.match(/^(\d{1,3})[.,](\d{1,2})$/)
  if (m) return Math.round(parseFloat(`${m[1]}.${m[2]}`) * 3600)

  m = raw.match(/^(?:(\d{1,3})\s*h)?\s*(?:(\d{1,3})\s*m(?:in)?)?$/)
  if (m && (m[1] || m[2])) return (parseInt(m[1] ?? '0', 10) * 60 + parseInt(m[2] ?? '0', 10)) * 60

  m = raw.match(/^(\d{1,4})$/)
  if (m) return parseInt(m[1]!, 10) * 60

  return null
}

const parsedDuration = computed(() => parseDuration(durationInput.value))

const rangeSeconds = computed(() => {
  if (!date.value || !startTime.value || !endTime.value) return null
  const diff = (toLocalDate(date.value, endTime.value).getTime() - toLocalDate(date.value, startTime.value).getTime()) / 1000
  return diff > 0 ? diff : null
})

const preview = computed(() => {
  const seconds = mode.value === 'duration' ? parsedDuration.value : rangeSeconds.value
  return seconds ? formatDuration(seconds) : ''
})

onMounted(() => {
  const entry = props.entry
  if (!entry && props.projectTask) {
    projectId.value = props.projectTask.project_id
    description.value = props.projectTask.title
    const project = props.projects.find((p) => p.id === props.projectTask?.project_id)
    if (project) isBillable.value = project.is_billable
  }
  if (!entry) return
  projectId.value = entry.project_id
  taskId.value = entry.task_id ?? ''
  description.value = entry.description ?? ''
  isBillable.value = entry.is_billable
  const started = new Date(entry.started_at)
  date.value = toDateString(started)
  startTime.value = toTimeInput(started)
  if (entry.stopped_at) endTime.value = toTimeInput(new Date(entry.stopped_at))
  if (entry.duration_seconds) durationInput.value = formatDuration(entry.duration_seconds)
  mode.value = entry.source === 'manual' || !entry.stopped_at ? 'duration' : 'range'
})

function validate(): string {
  if (!projectId.value) return t('timeEntryModal.projectRequired')
  if (!date.value) return t('timeEntryModal.dateRequired')
  if (mode.value === 'duration') {
    if (!parsedDuration.value) return t('manualEntry.invalidDuration')
  } else {
    if (!startTime.value || !endTime.value) return t('timeEntryModal.timesRequired')
    if (!rangeSeconds.value) return t('timeEntryModal.endBeforeStart')
  }
  return ''
}

async function handleSave() {
  error.value = validate()
  if (error.value) return

  const base: Record<string, unknown> = {
    project_id: projectId.value,
    task_id: taskId.value || null,
    description: description.value.trim() || null,
    is_billable: isBillable.value,
  }
  if (!isEdit.value) {
    if (props.projectTask) base.project_task_id = props.projectTask.id
    else if (createTask.value) {
      base.create_task = true
      base.complete_task = completeTask.value
    }
  } else if (props.entry?.project_task_id) {
    base.project_task_id = props.entry.project_task_id
  }

  let payload: Record<string, unknown>
  if (mode.value === 'range') {
    payload = {
      ...base,
      started_at: toLocalDate(date.value, startTime.value).toISOString(),
      stopped_at: toLocalDate(date.value, endTime.value).toISOString(),
    }
  } else if (isEdit.value) {
    // Keep the original start time-of-day on the chosen date, move the end accordingly
    const seconds = parsedDuration.value!
    const started = toLocalDate(date.value, startTime.value || '09:00')
    payload = {
      ...base,
      started_at: started.toISOString(),
      stopped_at: new Date(started.getTime() + seconds * 1000).toISOString(),
      duration_seconds: seconds,
    }
  } else {
    payload = { ...base, date: date.value, duration_seconds: parsedDuration.value }
  }

  saving.value = true
  try {
    const { data } = isEdit.value
      ? await api.put(`/time-entries/${props.entry!.id}`, payload)
      : await api.post('/time-entries', { ...payload, source: 'manual' })
    emit('saved', data.data)
  } catch (e) {
    error.value = errorMessage(e, t('common.failedToSave'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :title="isEdit ? $t('timeEntryModal.editTitle') : $t('timeEntryModal.createTitle')" @close="emit('close')">
    <form id="time-entry-form" class="form time-entry-modal" novalidate @submit.prevent="handleSave">
      <div v-if="error" class="form__alert" role="alert">{{ error }}</div>

      <div class="form__group">
        <label class="form__label" for="te-project">{{ $t('manualEntry.projectRequired') }}</label>
        <ComboBox
          id="te-project"
          v-model="projectId"
          :options="projectOptions"
          :placeholder="$t('manualEntry.selectProject')"
        />
      </div>

      <div class="form__group">
        <label class="form__label" for="te-task">{{ $t('manualEntry.task') }}</label>
        <ComboBox
          id="te-task"
          v-model="taskId"
          :options="taskOptions"
          :placeholder="$t('manualEntry.none')"
          :clearable="true"
          :clear-label="$t('manualEntry.none')"
          :disabled="tasks.length === 0"
        />
      </div>

      <div class="form__group">
        <label class="form__label" for="te-description">{{ $t('manualEntry.description') }}</label>
        <input
          id="te-description"
          v-model="description"
          type="text"
          class="form__input"
          :placeholder="$t('manualEntry.whatDidYouWorkOn')"
        />
      </div>

      <div class="form__row">
        <div class="form__group">
          <label class="form__label" for="te-date">{{ $t('manualEntry.dateRequired') }}</label>
          <input id="te-date" v-model="date" type="date" class="form__input" required />
        </div>

        <div class="form__group time-entry-modal__mode">
          <span id="te-mode-label" class="time-entry-modal__mode-label">{{ $t('timeEntryModal.modeLabel') }}</span>
          <div class="segmented" role="group" aria-labelledby="te-mode-label">
            <button
              type="button"
              class="segmented__item"
              :class="{ 'segmented__item--active': mode === 'duration' }"
              :aria-pressed="mode === 'duration'"
              @click="mode = 'duration'"
            >
              {{ $t('timeEntryModal.modeDuration') }}
            </button>
            <button
              type="button"
              class="segmented__item"
              :class="{ 'segmented__item--active': mode === 'range' }"
              :aria-pressed="mode === 'range'"
              @click="mode = 'range'"
            >
              {{ $t('timeEntryModal.modeRange') }}
            </button>
          </div>
        </div>
      </div>

      <div v-if="mode === 'duration'" class="form__group">
        <label class="form__label" for="te-duration">{{ $t('manualEntry.durationRequired') }}</label>
        <input
          id="te-duration"
          v-model="durationInput"
          type="text"
          inputmode="decimal"
          class="form__input time-entry-modal__duration-input"
          :placeholder="$t('manualEntry.durationPlaceholder')"
          autocomplete="off"
        />
        <span class="form__hint">
          <template v-if="preview">= {{ preview }} h</template>
          <template v-else>{{ $t('timeEntryModal.durationHint') }}</template>
        </span>
      </div>

      <div v-else class="form__group">
        <div class="time-entry-modal__times">
          <div class="form__group">
            <label class="form__label" for="te-start">{{ $t('common.start') }}</label>
            <input id="te-start" v-model="startTime" type="time" class="form__input" required />
          </div>
          <div class="form__group">
            <label class="form__label" for="te-end">{{ $t('common.end') }}</label>
            <input id="te-end" v-model="endTime" type="time" class="form__input" required />
          </div>
        </div>
        <span v-if="preview" class="form__hint time-entry-modal__preview">= {{ preview }} h</span>
      </div>

      <label class="form__check" for="te-billable">
        <input id="te-billable" v-model="isBillable" type="checkbox" />
        <span>{{ $t('manualEntry.billable') }}</span>
      </label>
      <p v-if="projectTask" class="form__hint time-entry-modal__task">{{ $t('timer.bookedOnTask', { title: projectTask.title }) }}</p>
      <template v-else-if="!isEdit">
        <label class="form__check" for="te-create-task" :title="$t('timer.createTaskHint')">
          <input id="te-create-task" v-model="createTask" type="checkbox" :disabled="!description.trim()" />
          <span>{{ $t('timer.createTask') }}</span>
        </label>
        <label v-if="createTask" class="form__check" for="te-complete-task">
          <input id="te-complete-task" v-model="completeTask" type="checkbox" />
          <span>{{ $t('timer.completeTask') }}</span>
        </label>
      </template>
    </form>

    <template #footer>
      <button type="button" class="btn btn--secondary" @click="emit('close')">{{ $t('common.cancel') }}</button>
      <button type="submit" form="time-entry-form" class="btn btn--primary" :disabled="saving">
        {{ saving ? $t('common.saving') : $t('common.save') }}
      </button>
    </template>
  </BaseModal>
</template>
