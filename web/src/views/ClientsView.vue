<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { Client } from '@/types'
import { PlusIcon, XMarkIcon } from '@heroicons/vue/24/outline'

const { t } = useI18n()
const clients = ref<Client[]>([])
const loading = ref(true)
const showForm = ref(false)
const editingClient = ref<Client | null>(null)

// Form
const formName = ref('')
const formColor = ref('#6B7280')
const formNotes = ref('')
const saving = ref(false)
const formError = ref('')

async function fetchClients() {
  loading.value = true
  try {
    const { data } = await api.get('/clients', { params: { per_page: 100 } })
    clients.value = data.data
  } finally {
    loading.value = false
  }
}

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
