<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { Project, Client } from '@/types'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import ComboBox from '@/components/ComboBox.vue'

const props = defineProps<{
  project: Project | null
  clients: Client[]
}>()

const emit = defineEmits<{
  close: []
  saved: []
}>()

const { t } = useI18n()
const localClients = ref<Client[]>([...props.clients])
watch(() => props.clients, (c) => { localClients.value = [...c] })

const clientOptions = computed(() =>
  localClients.value.map(c => ({ id: c.id, label: c.name }))
)

async function handleCreateClient(clientName: string) {
  if (!confirm(t('projectForm.createClientConfirm', { name: clientName }))) return
  try {
    const { data } = await api.post('/clients', { name: clientName })
    localClients.value.push(data.data)
    clientId.value = data.data.id
  } catch (e: any) {
    error.value = e.response?.data?.message || t('projectForm.failedToCreateClient')
  }
}

const name = ref('')
const clientId = ref('')
const color = ref('#3B82F6')
const budgetHours = ref<number | null>(null)
const hourlyRate = ref<number | null>(null)
const isBillable = ref(true)
const saving = ref(false)
const error = ref('')

onMounted(() => {
  if (props.project) {
    name.value = props.project.name
    clientId.value = props.project.client_id
    color.value = props.project.color
    budgetHours.value = props.project.budget_hours ? Number(props.project.budget_hours) : null
    hourlyRate.value = props.project.hourly_rate ? Number(props.project.hourly_rate) : null
    isBillable.value = props.project.is_billable
  }
})

async function handleSave() {
  error.value = ''
  saving.value = true
  try {
    const payload = {
      name: name.value,
      client_id: clientId.value,
      color: color.value,
      budget_hours: budgetHours.value,
      hourly_rate: hourlyRate.value,
      is_billable: isBillable.value,
    }
    if (props.project) {
      await api.put(`/projects/${props.project.id}`, payload)
    } else {
      await api.post('/projects', payload)
    }
    emit('saved')
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
        <h2 class="heading-2">{{ project ? $t('projectForm.editProject') : $t('projectForm.newProject') }}</h2>
        <button class="btn-ghost btn-icon" @click="emit('close')">
          <XMarkIcon class="modal-close-icon" />
        </button>
      </div>

      <form class="modal-body" @submit.prevent="handleSave">
        <div v-if="error" class="form-error-box">{{ error }}</div>

        <div class="form-group">
          <label class="form-label">{{ $t('projectForm.clientRequired') }}</label>
          <ComboBox
            v-model="clientId"
            :options="clientOptions"
            :placeholder="$t('projectForm.selectClient')"
            :allow-create="true"
            @create="handleCreateClient"
          />
        </div>

        <div class="form-group">
          <label class="form-label">{{ $t('projectForm.projectNameRequired') }}</label>
          <input v-model="name" type="text" class="form-input" required />
        </div>

        <div class="form-row-2">
          <div class="form-group">
            <label class="form-label">{{ $t('common.color') }}</label>
            <input v-model="color" type="color" class="form-input form-color" />
          </div>
          <div class="form-group">
            <label class="form-label">{{ $t('projectForm.billable') }}</label>
            <label class="form-checkbox-label">
              <input v-model="isBillable" type="checkbox" class="form-checkbox" />
              <span>{{ $t('projectForm.billableLabel') }}</span>
            </label>
          </div>
        </div>

        <div class="form-row-2">
          <div class="form-group">
            <label class="form-label">{{ $t('projectForm.budgetHours') }}</label>
            <input v-model.number="budgetHours" type="number" step="0.5" min="0" class="form-input" :placeholder="$t('projectForm.budgetPlaceholder')" />
          </div>
          <div class="form-group">
            <label class="form-label">{{ $t('projectForm.hourlyRate') }}</label>
            <input v-model.number="hourlyRate" type="number" step="0.01" min="0" class="form-input" :placeholder="$t('projectForm.ratePlaceholder')" />
          </div>
        </div>

        <div class="modal-actions">
          <button type="button" class="btn-secondary" @click="emit('close')">{{ $t('common.cancel') }}</button>
          <button type="submit" class="btn-primary" :disabled="saving">
            {{ saving ? $t('common.saving') : $t('common.save') }}
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
  @apply grid grid-cols-2 gap-4;
}

.form-color {
  @apply h-10 p-1 cursor-pointer;
}

.form-checkbox-label {
  @apply flex items-center gap-2 text-sm text-gray-700 mt-2;
}

.modal-actions {
  @apply flex justify-end gap-3 pt-2;
}
</style>
