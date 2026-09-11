<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTimerStore } from '@/stores/timer'
import { useToastStore, errorMessage } from '@/stores/toast'
import { formatTime } from '@/utils/format'
import type { Project, Task } from '@/types'
import { PlayIcon, StopIcon } from '@heroicons/vue/24/solid'
import { ArrowsRightLeftIcon } from '@heroicons/vue/24/outline'
import ComboBox from '@/components/ComboBox.vue'
import { RouterLink } from 'vue-router'

const props = defineProps<{
  projects: Project[]
  tasks: Task[]
}>()

const emit = defineEmits<{ (e: 'changed'): void }>()

const { t } = useI18n()
const timer = useTimerStore()
const toast = useToastStore()

const selectedProjectId = ref('')
const selectedTaskId = ref('')
const description = ref('')
const isBillable = ref(true)
const createTask = ref(false)
const completeTask = ref(false)
const busy = ref(false)

const projectOptions = computed(() =>
  props.projects.map((p) => ({
    id: p.id,
    label: p.name,
    subtitle: p.client?.name,
    color: p.color,
  }))
)

const taskOptions = computed(() => props.tasks.map((task) => ({ id: task.id, label: task.name })))

const running = computed(() => timer.runningEntry)

/** True when the form differs from the running entry, i.e. a Switch would start something new. */
const isDirty = computed(() => {
  const entry = running.value
  if (!entry) return false
  return (
    selectedProjectId.value !== entry.project_id ||
    (selectedTaskId.value || '') !== (entry.task_id || '') ||
    description.value.trim() !== (entry.description || '').trim() ||
    isBillable.value !== entry.is_billable
  )
})

const canSwitch = computed(() => !!running.value && isDirty.value && !!selectedProjectId.value)

/** Sync the form with the running entry whenever a different entry starts or the timer stops. */
watch(
  () => timer.runningEntry?.id ?? null,
  () => {
    const entry = timer.runningEntry
    if (entry) {
      selectedProjectId.value = entry.project_id
      selectedTaskId.value = entry.task_id || ''
      description.value = entry.description || ''
      isBillable.value = entry.is_billable
    } else {
      description.value = ''
      isBillable.value = defaultBillable(selectedProjectId.value)
    }
  },
  { immediate: true }
)

/** When the user picks a project while idle, default the billable flag to the project's setting. */
watch(selectedProjectId, (projectId) => {
  if (running.value && projectId === running.value.project_id) {
    isBillable.value = running.value.is_billable
    return
  }
  isBillable.value = defaultBillable(projectId)
})

function defaultBillable(projectId: string): boolean {
  const project = props.projects.find((p) => p.id === projectId)
  return project ? project.is_billable : true
}

async function startFromForm() {
  if (!selectedProjectId.value || busy.value) return
  busy.value = true
  try {
    await timer.start(
      selectedProjectId.value,
      selectedTaskId.value || undefined,
      description.value.trim() || undefined,
      isBillable.value,
      running.value ? {} : { createTask: createTask.value, completeTask: createTask.value && completeTask.value },
    )
    createTask.value = false
    completeTask.value = false
    emit('changed')
  } catch (e) {
    toast.error(errorMessage(e, t('timer.startFailed')))
  } finally {
    busy.value = false
  }
}

async function handleStart() {
  if (running.value) return
  await startFromForm()
}

async function handleSwitch() {
  if (!canSwitch.value) return
  await startFromForm()
}

async function handleStop() {
  if (busy.value) return
  busy.value = true
  try {
    await timer.stop()
    emit('changed')
  } catch (e) {
    toast.error(errorMessage(e, t('timer.stopFailed')))
  } finally {
    busy.value = false
  }
}

function onDescriptionEnter() {
  if (running.value) {
    if (canSwitch.value) handleSwitch()
  } else {
    handleStart()
  }
}
</script>

<template>
  <section
    class="timer-widget"
    :class="{ 'timer-widget--running': timer.isRunning }"
    :aria-label="$t('timer.widgetLabel')"
  >
    <div class="timer-widget__head">
      <div
        class="timer-widget__clock"
        :class="{ 'timer-widget__clock--running': timer.isRunning }"
        aria-live="off"
      >
        {{ timer.isRunning ? timer.elapsedFormatted : '0:00:00' }}
      </div>

      <button
        v-if="timer.isRunning"
        type="button"
        class="btn btn--danger btn--lg timer-widget__primary"
        :disabled="busy"
        @click="handleStop"
      >
        <StopIcon class="btn__icon" aria-hidden="true" />
        {{ $t('timer.stop') }}
      </button>
      <button
        v-else
        type="button"
        class="btn btn--success btn--lg timer-widget__primary"
        :disabled="!selectedProjectId || busy"
        @click="handleStart"
      >
        <PlayIcon class="btn__icon" aria-hidden="true" />
        {{ $t('timer.start') }}
      </button>
    </div>

    <div v-if="running" class="timer-widget__running">
      <span class="timer-widget__pulse" aria-hidden="true"></span>
      <div class="timer-widget__running-body">
        <div class="timer-widget__running-title">
          <span class="timer-widget__running-project">
            <span class="color-dot color-dot--lg" :style="{ backgroundColor: running.project?.color }"></span>
            {{ running.project?.name }}
          </span>
          <span v-if="running.project?.client" class="timer-widget__running-client">
            {{ running.project.client.name }}
          </span>
          <span v-if="running.task" class="timer-widget__running-task">{{ running.task.name }}</span>
          <RouterLink v-if="running.project_task" class="badge badge--brand timer-widget__running-ptask" :to="{ name: 'task-detail', params: { id: running.project_task.id } }">
            {{ running.project_task.title }}
          </RouterLink>
          <span class="badge" :class="running.is_billable ? 'badge--success' : 'badge--neutral'">
            {{ running.is_billable ? $t('common.billable') : $t('common.nonBillable') }}
          </span>
        </div>
        <p v-if="running.description" class="timer-widget__running-desc">{{ running.description }}</p>
        <p class="timer-widget__running-since">{{ $t('timer.since', { time: formatTime(running.started_at) }) }}</p>
      </div>
    </div>

    <div class="timer-widget__form">
      <p v-if="running" class="timer-widget__form-hint">{{ $t('timer.switchHint') }}</p>

      <div class="timer-widget__selects">
        <div class="form__group">
          <label for="timer-project" class="sr-only">{{ $t('timer.project') }}</label>
          <ComboBox
            id="timer-project"
            v-model="selectedProjectId"
            :options="projectOptions"
            :placeholder="$t('timer.selectProject')"
            :clearable="true"
            :clear-label="$t('timer.noProject')"
            :disabled="busy"
          />
        </div>
        <div class="form__group">
          <label for="timer-task" class="sr-only">{{ $t('timer.task') }}</label>
          <ComboBox
            id="timer-task"
            v-model="selectedTaskId"
            :options="taskOptions"
            :placeholder="$t('timer.selectTask')"
            :clearable="true"
            :clear-label="$t('timer.noTask')"
            :disabled="busy || tasks.length === 0"
          />
        </div>
      </div>

      <div class="timer-widget__row">
        <div class="form__group">
          <label for="timer-description" class="sr-only">{{ $t('timer.description') }}</label>
          <input
            id="timer-description"
            v-model="description"
            type="text"
            class="form__input"
            :placeholder="$t('timer.whatAreYouWorkingOn')"
            :disabled="busy"
            autocomplete="off"
            @keydown.enter.prevent="onDescriptionEnter"
          />
        </div>

        <label class="form__check timer-widget__billable">
          <input v-model="isBillable" type="checkbox" :disabled="busy" />
          {{ $t('common.billable') }}
        </label>
        <label v-if="!running" class="form__check timer-widget__billable" :title="$t('timer.createTaskHint')">
          <input v-model="createTask" type="checkbox" :disabled="busy || !description.trim()" />
          {{ $t('timer.createTask') }}
        </label>
        <label v-if="!running && createTask" class="form__check timer-widget__billable">
          <input v-model="completeTask" type="checkbox" :disabled="busy" />
          {{ $t('timer.completeTask') }}
        </label>

        <button
          v-if="running && canSwitch"
          type="button"
          class="btn btn--primary timer-widget__switch"
          :disabled="busy"
          @click="handleSwitch"
        >
          <ArrowsRightLeftIcon class="btn__icon" aria-hidden="true" />
          {{ $t('timer.switch') }}
        </button>
      </div>
    </div>
  </section>
</template>
