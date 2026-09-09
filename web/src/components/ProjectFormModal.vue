<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { Project, Client } from '@/types'
import BaseModal from '@/components/BaseModal.vue'
import ComboBox from '@/components/ComboBox.vue'
import { useToastStore, errorMessage } from '@/stores/toast'

const props = defineProps<{
  project: Project | null
  clients: Client[]
}>()

const emit = defineEmits<{
  close: []
  saved: [project: Project]
}>()

const { t } = useI18n()
const toast = useToastStore()

const localClients = ref<Client[]>([...props.clients])
watch(() => props.clients, (c) => { localClients.value = [...c] })

const clientOptions = computed(() =>
  localClients.value.map((c) => ({ id: c.id, label: c.name, color: c.color }))
)

const name = ref('')
const clientId = ref('')
const color = ref('#6D4FC2')
const budgetHours = ref<number | null>(null)
const hourlyRate = ref<number | null>(null)
const isBillable = ref(true)
const saving = ref(false)
const creatingClient = ref(false)
const error = ref('')

const title = computed(() => (props.project ? t('projectForm.editProject') : t('projectForm.newProject')))

onMounted(() => {
  if (props.project) {
    name.value = props.project.name
    clientId.value = props.project.client_id
    color.value = props.project.color
    budgetHours.value = props.project.budget_hours != null ? Number(props.project.budget_hours) : null
    hourlyRate.value = props.project.hourly_rate != null ? Number(props.project.hourly_rate) : null
    isBillable.value = props.project.is_billable
  }
})

async function handleCreateClient(clientName: string) {
  const trimmed = clientName.trim()
  if (!trimmed || creatingClient.value) return
  creatingClient.value = true
  error.value = ''
  try {
    const { data } = await api.post('/clients', { name: trimmed })
    const client: Client = data.data
    localClients.value.push(client)
    clientId.value = client.id
    toast.success(t('projectForm.clientCreated', { name: client.name }))
  } catch (e) {
    error.value = errorMessage(e, t('projectForm.failedToCreateClient'))
  } finally {
    creatingClient.value = false
  }
}

async function handleSave() {
  error.value = ''
  if (!clientId.value) {
    error.value = t('projectForm.selectClient')
    return
  }
  saving.value = true
  try {
    const payload = {
      name: name.value.trim(),
      client_id: clientId.value,
      color: color.value,
      budget_hours: budgetHours.value === null || Number.isNaN(budgetHours.value) ? null : budgetHours.value,
      hourly_rate: hourlyRate.value === null || Number.isNaN(hourlyRate.value) ? null : hourlyRate.value,
      is_billable: isBillable.value,
    }
    const { data } = props.project
      ? await api.put(`/projects/${props.project.id}`, payload)
      : await api.post('/projects', payload)
    emit('saved', data.data as Project)
  } catch (e) {
    error.value = errorMessage(e, t('common.failedToSave'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :title="title" @close="emit('close')">
    <form id="project-form" class="form" novalidate @submit.prevent="handleSave">
      <div v-if="error" class="form__alert" role="alert">{{ error }}</div>

      <div class="form__group">
        <label class="form__label" for="project-form-name">{{ $t('projectForm.projectNameRequired') }}</label>
        <input
          id="project-form-name"
          v-model="name"
          type="text"
          class="form__input"
          required
          autofocus
          autocomplete="off"
        />
      </div>

      <div class="form__group">
        <label class="form__label" for="project-form-client">{{ $t('projectForm.clientRequired') }}</label>
        <ComboBox
          id="project-form-client"
          v-model="clientId"
          :options="clientOptions"
          :placeholder="$t('projectForm.selectClient')"
          :allow-create="true"
          :disabled="creatingClient"
          @create="handleCreateClient"
        />
      </div>

      <div class="form__row">
        <div class="form__group">
          <label class="form__label" for="project-form-budget">{{ $t('projectForm.budgetHours') }}</label>
          <input
            id="project-form-budget"
            v-model.number="budgetHours"
            type="number"
            step="0.5"
            min="0"
            inputmode="decimal"
            class="form__input"
            :placeholder="$t('projectForm.budgetPlaceholder')"
          />
        </div>
        <div class="form__group">
          <label class="form__label" for="project-form-rate">{{ $t('projectForm.hourlyRate') }}</label>
          <input
            id="project-form-rate"
            v-model.number="hourlyRate"
            type="number"
            step="0.01"
            min="0"
            inputmode="decimal"
            class="form__input"
            :placeholder="$t('projectForm.ratePlaceholder')"
          />
        </div>
      </div>

      <div class="form__row project-form__meta">
        <div class="form__group">
          <label class="form__label" for="project-form-color">{{ $t('common.color') }}</label>
          <input id="project-form-color" v-model="color" type="color" class="form__input form__color" />
        </div>
        <div class="form__group">
          <span class="form__label">{{ $t('projectForm.billable') }}</span>
          <label class="form__check project-form__check">
            <input v-model="isBillable" type="checkbox" />
            <span>{{ $t('projectForm.billableLabel') }}</span>
          </label>
        </div>
      </div>
    </form>

    <template #footer>
      <button type="button" class="btn btn--secondary" @click="emit('close')">{{ $t('common.cancel') }}</button>
      <button type="submit" form="project-form" class="btn btn--primary" :disabled="saving || !name.trim()">
        {{ saving ? $t('common.saving') : $t('common.save') }}
      </button>
    </template>
  </BaseModal>
</template>
