<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { Project, Client, RateMode, BillingMode } from '@/types'
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
const rateMode = ref<RateMode>('standard')
const RATE_MODES: RateMode[] = ['standard', 'user', 'client', 'project']
const billingMode = ref<BillingMode>('hourly')
const budgetAmount = ref<number | null>(null)
const billedAmount = ref<number | null>(null)
const BILLING_MODES: BillingMode[] = ['none', 'fixed', 'hourly']
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
    billingMode.value = props.project.billing_mode ?? (props.project.is_billable ? 'hourly' : 'none')
    budgetAmount.value = props.project.budget_amount != null ? Number(props.project.budget_amount) : null
    billedAmount.value = props.project.billed_amount != null ? Number(props.project.billed_amount) : null
    rateMode.value = props.project.rate_mode ?? (props.project.hourly_rate != null ? 'project' : 'standard')
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
      billing_mode: billingMode.value,
      budget_amount: budgetAmount.value === null || Number.isNaN(budgetAmount.value) ? null : budgetAmount.value,
      billed_amount: billedAmount.value === null || Number.isNaN(billedAmount.value) ? 0 : billedAmount.value,
      rate_mode: rateMode.value,
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

      <div class="form__group">
        <label class="form__label" for="project-form-billing">{{ $t('billing.mode') }}</label>
        <select id="project-form-billing" v-model="billingMode" class="form__select">
          <option v-for="m in BILLING_MODES" :key="m" :value="m">{{ $t(`billing.modes.${m}`) }}</option>
        </select>
        <span class="form__hint">{{ $t(`billing.hints.${billingMode}`) }}</span>
      </div>

      <div v-if="billingMode === 'fixed'" class="form__row">
        <div class="form__group">
          <label class="form__label" for="project-form-budget-amount">{{ $t('billing.budgetAmount') }} (EUR)</label>
          <input id="project-form-budget-amount" v-model.number="budgetAmount" type="number" step="0.01" min="0" inputmode="decimal" class="form__input" :placeholder="$t('common.optional')" />
        </div>
        <div class="form__group">
          <label class="form__label" for="project-form-billed">{{ $t('billing.billedAmount') }} (EUR)</label>
          <input id="project-form-billed" v-model.number="billedAmount" type="number" step="0.01" min="0" inputmode="decimal" class="form__input" placeholder="0" />
        </div>
      </div>

      <div v-if="billingMode !== 'none'" class="form__row">
        <div v-if="billingMode === 'hourly'" class="form__group">
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
          <label class="form__label" for="project-form-rate-mode">{{ $t('rates.mode') }}</label>
          <select id="project-form-rate-mode" v-model="rateMode" class="form__select">
            <option v-for="m in RATE_MODES" :key="m" :value="m">{{ $t(`rates.modes.${m}`) }}</option>
          </select>
          <span class="form__hint">{{ $t(`rates.modeHints.${rateMode}`) }}</span>
        </div>
      </div>

      <div v-if="billingMode !== 'none' && rateMode === 'project'" class="form__group">
        <label class="form__label" for="project-form-rate">{{ $t('rates.projectRate') }} (EUR/h)</label>
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

      <div class="form__group">
        <label class="form__label" for="project-form-color">{{ $t('common.color') }}</label>
        <input id="project-form-color" v-model="color" type="color" class="form__input form__color" />
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
