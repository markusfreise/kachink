<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { Project, Task } from '@/types'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import ComboBox from '@/components/ComboBox.vue'

const props = defineProps<{
  projects: Project[]
  tasks: Task[]
}>()

const emit = defineEmits<{
  close: []
  created: []
}>()

const { t } = useI18n()
const projectId = ref('')
const taskId = ref('')

const projectOptions = computed(() =>
  props.projects.map(p => ({
    id: p.id,
    label: p.name,
    subtitle: p.client?.name,
    color: p.color,
  }))
)

const taskOptions = computed(() =>
  props.tasks.map(t => ({ id: t.id, label: t.name }))
)

watch(projectId, () => { taskId.value = '' })
const description = ref('')
const date = ref(new Date().toISOString().split('T')[0]!)
const duration = ref('')
const isBillable = ref(true)
const saving = ref(false)
const error = ref('')

function parseDuration(input: string): number | null {
  const trimmed = input.trim()
  if (!trimmed) return null

  // "1:30" or "1.30" -> 1h 30m, "1:" or "1." -> 1h 0m
  const match = trimmed.match(/^(\d+)[\:\.](\d{0,2})$/)
  if (match) {
    const hours = parseInt(match[1]!, 10)
    const minutes = match[2] ? parseInt(match[2]!, 10) : 0
    return (hours * 60 + minutes) * 60
  }

  // Plain number -> minutes
  const num = parseInt(trimmed, 10)
  if (!isNaN(num) && num > 0) {
    return num * 60
  }

  return null
}

function durationHint(): string {
  const seconds = parseDuration(duration.value)
  if (seconds === null) return ''
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  if (h > 0 && m > 0) return `= ${h}h ${m}m`
  if (h > 0) return `= ${h}h`
  return `= ${m}m`
}

async function handleSave() {
  error.value = ''
  const seconds = parseDuration(duration.value)
  if (!seconds) {
    error.value = t('manualEntry.invalidDuration')
    return
  }

  saving.value = true
  try {
    await api.post('/time-entries', {
      project_id: projectId.value,
      task_id: taskId.value || null,
      description: description.value || null,
      date: date.value,
      duration_seconds: seconds,
      is_billable: isBillable.value,
      source: 'manual',
    })
    emit('created')
  } catch (e: any) {
    error.value = e.response?.data?.message || t('common.failedToSave')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="modal-overlay" @click.self="emit('close')">
    <div class="modal-panel">
      <div class="modal-header">
        <h2 class="heading-2">{{ $t('manualEntry.title') }}</h2>
        <button class="btn-ghost btn-icon" @click="emit('close')">
          <XMarkIcon class="modal-close-icon" />
        </button>
      </div>

      <form class="modal-body" @submit.prevent="handleSave">
        <div v-if="error" class="form-error-box">{{ error }}</div>

        <div class="form-group">
          <label class="form-label">{{ $t('manualEntry.projectRequired') }}</label>
          <ComboBox
            v-model="projectId"
            :options="projectOptions"
            :placeholder="$t('manualEntry.selectProject')"
          />
        </div>

        <div class="form-group">
          <label class="form-label">{{ $t('manualEntry.task') }}</label>
          <ComboBox
            v-model="taskId"
            :options="taskOptions"
            :placeholder="$t('manualEntry.none')"
            :clearable="true"
            :clear-label="$t('manualEntry.none')"
            :disabled="tasks.length === 0"
          />
        </div>

        <div class="form-group">
          <label class="form-label">{{ $t('manualEntry.description') }}</label>
          <input v-model="description" type="text" class="form-input" :placeholder="$t('manualEntry.whatDidYouWorkOn')" />
        </div>

        <div class="form-row-2">
          <div class="form-group">
            <label class="form-label">{{ $t('manualEntry.dateRequired') }}</label>
            <input v-model="date" type="date" class="form-input" required />
          </div>
          <div class="form-group">
            <label class="form-label">{{ $t('manualEntry.durationRequired') }}</label>
            <input
              v-model="duration"
              type="text"
              class="form-input"
              :placeholder="$t('manualEntry.durationPlaceholder')"
              required
            />
            <span v-if="durationHint()" class="form-hint">{{ durationHint() }}</span>
          </div>
        </div>

        <label class="form-checkbox-label">
          <input v-model="isBillable" type="checkbox" class="form-checkbox" />
          <span>{{ $t('manualEntry.billable') }}</span>
        </label>

        <div class="modal-actions">
          <button type="button" class="btn-secondary" @click="emit('close')">{{ $t('common.cancel') }}</button>
          <button type="submit" class="btn-primary" :disabled="saving || !projectId">
            {{ saving ? $t('common.saving') : $t('manualEntry.saveEntry') }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<style scoped>
@reference "../assets/main.css";
.modal-overlay {
  @apply fixed inset-0 z-50 flex items-center justify-center bg-black/50;
}

.modal-panel {
  @apply w-full max-w-lg rounded-xl bg-white shadow-xl;
}

.modal-header {
  @apply flex items-center justify-between px-6 py-4 border-b border-gray-200;
}

.modal-close-icon {
  @apply h-5 w-5;
}

.modal-body {
  @apply space-y-4 px-6 py-4;
}

.form-error-box {
  @apply rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 border border-red-200;
}

.form-row-2 {
  @apply grid grid-cols-2 gap-3;
}

.form-hint {
  @apply text-xs text-gray-500 mt-1;
}

.form-checkbox-label {
  @apply flex items-center gap-2 text-sm text-gray-700;
}

.modal-actions {
  @apply flex justify-end gap-3 pt-2;
}
</style>
