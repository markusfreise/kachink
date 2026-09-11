<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import type { Client, PaginationMeta } from '@/types'
import { monthlyReportRange } from '@/composables/useReportPeriod'
import { useToastStore, errorMessage } from '@/stores/toast'
import BaseModal from '@/components/BaseModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import AppPagination from '@/components/AppPagination.vue'
import { PlusIcon, DocumentTextIcon, UsersIcon } from '@heroicons/vue/24/outline'

type StatusFilter = 'active' | 'archived' | 'all'

const { t } = useI18n()
const router = useRouter()
const toast = useToastStore()

function openMonthlyReport(client: Client) {
  const range = monthlyReportRange()
  router.push({ name: 'report-detail', params: { scope: 'clients', id: client.id }, query: { from: range.from, to: range.to } })
}

const clients = ref<Client[]>([])
const loading = ref(true)
const showForm = ref(false)
const editingClient = ref<Client | null>(null)

// Filters / sort / pagination
const search = ref('')
const status = ref<StatusFilter>('active')
const sort = ref('name')
const page = ref(1)
const perPage = ref(25)
const meta = ref<PaginationMeta | null>(null)

const perPageOptions = [10, 25, 50, 100]
const sortOptions = computed(() => [
  { value: 'name', label: t('clients.sortName') },
  { value: '-name', label: t('clients.sortNameDesc') },
  { value: '-created_at', label: t('clients.sortNewest') },
  { value: 'created_at', label: t('clients.sortOldest') },
])
const statusOptions = computed<{ value: StatusFilter; label: string }[]>(() => [
  { value: 'active', label: t('common.active') },
  { value: 'archived', label: t('common.archived') },
  { value: 'all', label: t('common.all') },
])

const hasFilters = computed(() => search.value !== '' || status.value !== 'active')

let searchTimer: ReturnType<typeof setTimeout> | null = null

// Form
const formName = ref('')
const formColor = ref('#6B7280')
const formNotes = ref('')
const formRate = ref<number | null>(null)
const saving = ref(false)
const formError = ref('')

// Archive dialog
const archiveTarget = ref<Client | null>(null)
const archiving = ref(false)

async function fetchClients() {
  loading.value = true
  try {
    const params: Record<string, unknown> = {
      per_page: perPage.value,
      page: page.value,
      sort: sort.value,
    }
    if (search.value) params['filter[name]'] = search.value
    if (status.value !== 'all') params['filter[is_active]'] = status.value === 'active' ? 1 : 0
    const { data } = await api.get('/clients', { params })
    clients.value = data.data
    meta.value = data.meta ?? null
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
  }
}

watch(perPage, () => { page.value = 1; fetchClients() })
watch(page, fetchClients)
watch(sort, () => { page.value = 1; fetchClients() })
watch(status, () => { page.value = 1; fetchClients() })
watch(search, () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; fetchClients() }, 300)
})

function clearFilters() {
  search.value = ''
  status.value = 'active'
}

function openCreate() {
  editingClient.value = null
  formName.value = ''
  formColor.value = '#6B7280'
  formNotes.value = ''
  formRate.value = null
  formError.value = ''
  showForm.value = true
}

function openEdit(client: Client) {
  editingClient.value = client
  formName.value = client.name
  formColor.value = client.color
  formNotes.value = client.notes || ''
  formRate.value = client.hourly_rate != null ? Number(client.hourly_rate) : null
  formError.value = ''
  showForm.value = true
}

async function handleSave() {
  formError.value = ''
  saving.value = true
  try {
    const payload = {
      name: formName.value,
      color: formColor.value,
      notes: formNotes.value || null,
      hourly_rate: formRate.value === null || Number.isNaN(formRate.value) ? null : formRate.value,
    }
    if (editingClient.value) {
      await api.put(`/clients/${editingClient.value.id}`, payload)
    } else {
      await api.post('/clients', payload)
    }
    showForm.value = false
    toast.success(t('clients.saved'))
    fetchClients()
  } catch (e) {
    formError.value = errorMessage(e, t('common.failedToSave'))
  } finally {
    saving.value = false
  }
}

async function confirmArchive() {
  if (!archiveTarget.value) return
  archiving.value = true
  try {
    await api.delete(`/clients/${archiveTarget.value.id}`)
    archiveTarget.value = null
    toast.success(t('clients.archived'))
    fetchClients()
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
  } finally {
    archiving.value = false
  }
}

async function unarchiveClient(client: Client) {
  try {
    await api.put(`/clients/${client.id}`, { is_active: true })
    toast.success(t('clients.unarchived'))
    fetchClients()
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
  }
}

onMounted(fetchClients)
</script>

<template>
  <div class="page clients">
    <div class="page__header">
      <h1 class="heading-1 page__title">{{ $t('clients.title') }}</h1>
      <div class="page__actions">
        <button type="button" class="btn btn--primary" @click="openCreate">
          <PlusIcon class="btn__icon" aria-hidden="true" />
          {{ $t('clients.newClient') }}
        </button>
      </div>
    </div>

    <div class="toolbar">
      <div class="toolbar__group">
        <input
          id="clients-search"
          v-model="search"
          type="search"
          class="form__input form__input--sm toolbar__search"
          :placeholder="$t('clients.search')"
          :aria-label="$t('clients.search')"
        />
        <div class="segmented" role="group" :aria-label="$t('clients.filterStatus')">
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
        <select id="clients-sort" v-model="sort" class="form__select form__select--sm form__select--inline" :aria-label="$t('clients.sortBy')">
          <option v-for="o in sortOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
      </div>
      <div class="toolbar__group">
        <span v-if="meta" class="toolbar__count">{{ $t('clients.count', { count: meta.total }) }}</span>
        <label for="clients-per-page" class="toolbar__label">{{ $t('common.perPage') }}</label>
        <select id="clients-per-page" v-model="perPage" class="form__select form__select--sm form__select--inline clients__per-page">
          <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="loading">
      <div class="spinner" role="status"></div>
    </div>

    <div v-else class="card">
      <div v-if="clients.length === 0" class="empty">
        <UsersIcon class="empty__icon" aria-hidden="true" />
        <template v-if="hasFilters">
          <p class="empty__title">{{ $t('common.noResults') }}</p>
          <p class="empty__text">{{ $t('clients.noMatches') }}</p>
          <button type="button" class="btn btn--secondary btn--sm" @click="clearFilters">{{ $t('clients.clearFilters') }}</button>
        </template>
        <template v-else>
          <p class="empty__text">{{ $t('clients.noClients') }}</p>
          <button type="button" class="btn btn--primary btn--sm" @click="openCreate">
            <PlusIcon class="btn__icon" aria-hidden="true" />
            {{ $t('clients.newClient') }}
          </button>
        </template>
      </div>

      <div v-else class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>{{ $t('clients.client') }}</th>
              <th class="table__num">{{ $t('clients.projects') }}</th>
              <th>{{ $t('clients.status') }}</th>
              <th>{{ $t('common.notes') }}</th>
              <th class="clients__actions-head">{{ $t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="client in clients"
              :key="client.id"
              class="table__row"
              :class="{ 'clients__row--archived': !client.is_active }"
            >
              <td>
                <div class="cell">
                  <span class="color-dot" :style="{ backgroundColor: client.color }" aria-hidden="true"></span>
                  <span class="cell__title">{{ client.name }}</span>
                </div>
              </td>
              <td class="table__num">{{ client.projects_count ?? 0 }}</td>
              <td>
                <span class="badge" :class="client.is_active ? 'badge--success' : 'badge--neutral'">
                  {{ client.is_active ? $t('common.active') : $t('common.archived') }}
                </span>
              </td>
              <td>
                <div class="clients__notes" :title="client.notes || undefined">{{ client.notes || '–' }}</div>
              </td>
              <td>
                <div class="table__actions">
                  <button type="button" class="btn btn--secondary btn--sm" @click="openMonthlyReport(client)">
                    <DocumentTextIcon class="btn__icon" aria-hidden="true" />
                    {{ $t('reportDetail.monthlyReport') }}
                  </button>
                  <button type="button" class="btn btn--ghost btn--sm" @click="openEdit(client)">{{ $t('common.edit') }}</button>
                  <button
                    v-if="client.is_active"
                    type="button"
                    class="btn btn--ghost btn--sm"
                    @click="archiveTarget = client"
                  >
                    {{ $t('common.archive') }}
                  </button>
                  <button
                    v-else
                    type="button"
                    class="btn btn--ghost btn--sm"
                    @click="unarchiveClient(client)"
                  >
                    {{ $t('clients.unarchive') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <AppPagination :meta="meta" :page="page" @update:page="page = $event" />

    <BaseModal
      v-if="showForm"
      :title="editingClient ? $t('clients.editClient') : $t('clients.newClient')"
      @close="showForm = false"
    >
      <form id="client-form" class="form" @submit.prevent="handleSave">
        <div v-if="formError" class="form__alert" role="alert">{{ formError }}</div>
        <div class="form__group">
          <label for="client-name" class="form__label">{{ $t('clients.nameRequired') }}</label>
          <input id="client-name" v-model="formName" type="text" class="form__input" required autofocus />
        </div>
        <div class="form__group">
          <label for="client-color" class="form__label">{{ $t('common.color') }}</label>
          <input id="client-color" v-model="formColor" type="color" class="form__input form__color" />
          <span class="form__hint">{{ $t('clients.colorHint') }}</span>
        </div>
        <div class="form__group">
          <label for="client-rate" class="form__label">{{ $t('rates.clientRate') }} (EUR/h)</label>
          <input id="client-rate" v-model.number="formRate" type="number" min="0" step="0.01" inputmode="decimal" class="form__input" :placeholder="$t('common.optional')" />
          <span class="form__hint">{{ $t('rates.clientRateHint') }}</span>
        </div>
        <div class="form__group">
          <label for="client-notes" class="form__label">{{ $t('common.notes') }}</label>
          <textarea id="client-notes" v-model="formNotes" class="form__textarea" rows="3"></textarea>
        </div>
      </form>
      <template #footer>
        <button type="button" class="btn btn--secondary" @click="showForm = false">{{ $t('common.cancel') }}</button>
        <button type="submit" form="client-form" class="btn btn--primary" :disabled="saving">
          {{ saving ? $t('common.saving') : $t('common.save') }}
        </button>
      </template>
    </BaseModal>

    <ConfirmDialog
      v-if="archiveTarget"
      :title="$t('clients.archiveTitle')"
      :text="$t('clients.archiveConfirm', { name: archiveTarget.name })"
      :confirm-label="$t('common.archive')"
      :busy="archiving"
      @confirm="confirmArchive"
      @cancel="archiveTarget = null"
    />
  </div>
</template>
