<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { Client, PaginationMeta } from '@/types'
import { PlusIcon, XMarkIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'

const { t } = useI18n()
const clients = ref<Client[]>([])
const loading = ref(true)
const showForm = ref(false)
const editingClient = ref<Client | null>(null)

// Filters / sort / pagination
const search = ref('')
const sort = ref('name')
const page = ref(1)
const perPage = ref(25)
const meta = ref<PaginationMeta | null>(null)

const perPageOptions = [10, 25, 50, 100]
const sortOptions = [
  { value: 'name', label: t('clients.sortName') },
  { value: '-name', label: t('clients.sortNameDesc') },
  { value: '-created_at', label: t('clients.sortNewest') },
  { value: 'created_at', label: t('clients.sortOldest') },
]

let searchTimer: ReturnType<typeof setTimeout> | null = null

// Form
const formName = ref('')
const formColor = ref('#6B7280')
const formNotes = ref('')
const saving = ref(false)
const formError = ref('')

async function fetchClients() {
  loading.value = true
  try {
    const params: Record<string, unknown> = {
      per_page: perPage.value,
      page: page.value,
      sort: sort.value,
    }
    if (search.value) params['filter[name]'] = search.value
    const { data } = await api.get('/clients', { params })
    clients.value = data.data
    meta.value = data.meta ?? null
  } finally {
    loading.value = false
  }
}

watch(perPage, () => { page.value = 1; fetchClients() })
watch(page, fetchClients)
watch(sort, () => { page.value = 1; fetchClients() })
watch(search, () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; fetchClients() }, 300)
})

function openCreate() {
  editingClient.value = null
  formName.value = ''
  formColor.value = '#6B7280'
  formNotes.value = ''
  showForm.value = true
}

function openEdit(client: Client) {
  editingClient.value = client
  formName.value = client.name
  formColor.value = client.color
  formNotes.value = client.notes || ''
  showForm.value = true
}

async function handleSave() {
  formError.value = ''
  saving.value = true
  try {
    const payload = { name: formName.value, color: formColor.value, notes: formNotes.value || null }
    if (editingClient.value) {
      await api.put(`/clients/${editingClient.value.id}`, payload)
    } else {
      await api.post('/clients', payload)
    }
    showForm.value = false
    fetchClients()
  } catch (e: any) {
    formError.value = e.response?.data?.message || t('common.failedToSave')
  } finally {
    saving.value = false
  }
}

async function archiveClient(client: Client) {
  if (!confirm(t('clients.archiveConfirm', { name: client.name }))) return
  await api.delete(`/clients/${client.id}`)
  fetchClients()
}

onMounted(fetchClients)
</script>

<template>
  <div class="page-container">
    <div class="page-header">
      <h1 class="heading-1">{{ $t('clients.title') }}</h1>
      <button class="btn-primary" @click="openCreate">
        <PlusIcon class="btn-icon-sm" />
        {{ $t('clients.newClient') }}
      </button>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-filters">
        <input
          v-model="search"
          type="search"
          class="form-input search-input"
          :placeholder="$t('clients.search')"
        />
        <select v-model="sort" class="form-select">
          <option v-for="o in sortOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
      </div>
      <div class="toolbar-right">
        <span class="text-muted">
          <template v-if="meta">{{ meta.total }} {{ $t('clients.title').toLowerCase() }}</template>
        </span>
        <label class="form-label">{{ $t('common.perPage') }}</label>
        <select v-model="perPage" class="form-select per-page-select">
          <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="loading-center">
      <div class="loading-spinner"></div>
    </div>

    <div v-else class="card">
      <div v-if="clients.length === 0" class="empty-state">
        <p class="empty-state-text">{{ $t('clients.noClients') }}</p>
      </div>
      <div v-else class="table-container">
        <table class="table">
          <thead class="table-header">
            <tr>
              <th class="table-th">{{ $t('clients.client') }}</th>
              <th class="table-th">{{ $t('clients.projects') }}</th>
              <th class="table-th">{{ $t('clients.status') }}</th>
              <th class="table-th">{{ $t('common.notes') }}</th>
              <th class="table-th">{{ $t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="client in clients" :key="client.id" class="table-row">
              <td class="table-td">
                <div class="client-cell">
                  <span class="color-dot" :style="{ backgroundColor: client.color }"></span>
                  <span class="client-name">{{ client.name }}</span>
                </div>
              </td>
              <td class="table-td">{{ client.projects_count ?? 0 }}</td>
              <td class="table-td">
                <span :class="client.is_active ? 'badge-green' : 'badge-gray'">
                  {{ client.is_active ? $t('common.active') : $t('common.archived') }}
                </span>
              </td>
              <td class="table-td table-td-notes">{{ client.notes || '—' }}</td>
              <td class="table-td">
                <div class="action-btns">
                  <button class="btn-ghost btn-sm" @click="openEdit(client)">{{ $t('common.edit') }}</button>
                  <button
                    v-if="client.is_active"
                    class="btn-ghost btn-sm"
                    @click="archiveClient(client)"
                  >
                    {{ $t('common.archive') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Pagination -->
    <div v-if="meta && meta.last_page > 1" class="pagination">
      <button class="btn-secondary btn-sm" :disabled="page === 1" @click="page--">
        <ChevronLeftIcon class="btn-icon-sm" />
      </button>
      <div class="pagination-pages">
        <button
          v-for="p in meta.last_page"
          :key="p"
          :class="p === page ? 'page-btn-active' : 'page-btn'"
          @click="page = p"
        >
          {{ p }}
        </button>
      </div>
      <button class="btn-secondary btn-sm" :disabled="page === meta.last_page" @click="page++">
        <ChevronRightIcon class="btn-icon-sm" />
      </button>
      <span class="pagination-info">
        {{ $t('common.page', { current: page, last: meta.last_page }) }}
      </span>
    </div>

    <!-- Form Modal -->
    <div v-if="showForm" class="modal-overlay" @click.self="showForm = false">
      <div class="modal-panel">
        <div class="modal-header">
          <h2 class="heading-2">{{ editingClient ? $t('clients.editClient') : $t('clients.newClient') }}</h2>
          <button class="btn-ghost btn-icon" @click="showForm = false">
            <XMarkIcon class="modal-close-icon" />
          </button>
        </div>
        <form class="modal-body" @submit.prevent="handleSave">
          <div v-if="formError" class="form-error-box">{{ formError }}</div>
          <div class="form-group">
            <label class="form-label">{{ $t('clients.nameRequired') }}</label>
            <input v-model="formName" type="text" class="form-input" required />
          </div>
          <div class="form-group">
            <label class="form-label">{{ $t('common.color') }}</label>
            <input v-model="formColor" type="color" class="form-input form-color" />
          </div>
          <div class="form-group">
            <label class="form-label">{{ $t('common.notes') }}</label>
            <textarea v-model="formNotes" class="form-textarea" rows="3"></textarea>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn-secondary" @click="showForm = false">{{ $t('common.cancel') }}</button>
            <button type="submit" class="btn-primary" :disabled="saving">
              {{ saving ? $t('common.saving') : $t('common.save') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../assets/main.css";
.page-header {
  @apply flex items-center justify-between mb-6;
}

.toolbar {
  @apply flex flex-wrap items-center justify-between gap-3 mb-4;
}

.toolbar-filters {
  @apply flex flex-wrap items-center gap-2;
}

.toolbar-right {
  @apply flex items-center gap-2;
}

.search-input {
  @apply w-52;
}

.per-page-select {
  @apply w-20;
}

.loading-center {
  @apply flex justify-center py-12;
}

.client-cell {
  @apply flex items-center gap-2;
}

.client-name {
  @apply font-medium;
}

.table-td-notes {
  @apply max-w-xs truncate text-gray-500;
}

.action-btns {
  @apply flex gap-1;
}

.btn-icon-sm {
  @apply h-4 w-4;
}

.pagination {
  @apply flex items-center gap-2 mt-6;
}

.pagination-pages {
  @apply flex gap-1;
}

.page-btn {
  @apply w-8 h-8 rounded text-sm text-gray-700 hover:bg-gray-100 transition-colors;
}

.page-btn-active {
  @apply w-8 h-8 rounded text-sm font-semibold bg-primary-600 text-white;
}

.pagination-info {
  @apply text-sm text-gray-500 ml-2;
}

.modal-overlay {
  @apply fixed inset-0 z-50 flex items-center justify-center bg-black/50;
}

.modal-panel {
  @apply w-full max-w-md rounded-xl bg-white shadow-xl;
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

.form-color {
  @apply h-10 p-1 cursor-pointer;
}

.modal-actions {
  @apply flex justify-end gap-3 pt-2;
}
</style>
