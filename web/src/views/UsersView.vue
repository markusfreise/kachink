<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { User } from '@/types'
import { PlusIcon, PencilIcon, XMarkIcon } from '@heroicons/vue/24/outline'

const { t } = useI18n()
const users = ref<User[]>([])
const loading = ref(true)
const showForm = ref(false)
const editingUser = ref<User | null>(null)
const successMessage = ref('')

const formName = ref('')
const formEmail = ref('')
const formRole = ref<'admin' | 'member'>('member')
const saving = ref(false)
const formError = ref('')

async function fetchUsers() {
  loading.value = true
  try {
    const { data } = await api.get('/users')
    users.value = data.data
  } finally {
    loading.value = false
  }
}

function openInvite() {
  editingUser.value = null
  formName.value = ''
  formEmail.value = ''
  formRole.value = 'member'
  formError.value = ''
  showForm.value = true
}

function openEdit(user: User) {
  editingUser.value = user
  formName.value = user.name
  formEmail.value = user.email
  formRole.value = user.role
  formError.value = ''
  showForm.value = true
}

async function handleSave() {
  formError.value = ''
  saving.value = true
  try {
    if (editingUser.value) {
      await api.put(`/users/${editingUser.value.id}`, {
        name: formName.value,
        role: formRole.value,
      })
    } else {
      await api.post('/users', {
        name: formName.value,
        email: formEmail.value,
        role: formRole.value,
      })
      successMessage.value = t('users.inviteSent')
      setTimeout(() => (successMessage.value = ''), 5000)
    }
    showForm.value = false
    fetchUsers()
  } catch (e: any) {
    formError.value = e.response?.data?.message || t('common.failedToSave')
  } finally {
    saving.value = false
  }
}

async function toggleActive(user: User) {
  const msg = user.is_active
    ? t('users.deactivateConfirm', { name: user.name })
    : t('users.activateConfirm', { name: user.name })
  if (!confirm(msg)) return
  await api.put(`/users/${user.id}`, { is_active: !user.is_active })
  fetchUsers()
}

onMounted(fetchUsers)
</script>

<template>
  <div class="page-container">
    <div class="page-header">
      <h1 class="heading-1">{{ $t('users.title') }}</h1>
      <button class="btn-primary" @click="openInvite">
        <PlusIcon class="btn-icon-sm" />
        {{ $t('users.inviteUser') }}
      </button>
    </div>

    <div v-if="successMessage" class="success-banner">{{ successMessage }}</div>

    <div v-if="loading" class="loading-center">
      <div class="loading-spinner"></div>
    </div>

    <div v-else class="users-table">
      <table class="table">
        <thead>
          <tr>
            <th class="th">{{ $t('common.name') }}</th>
            <th class="th">{{ $t('common.email') }}</th>
            <th class="th">{{ $t('users.role') }}</th>
            <th class="th">{{ $t('clients.status') }}</th>
            <th class="th th-actions">{{ $t('common.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="users.length === 0">
            <td colspan="5" class="td-empty">{{ $t('users.noUsers') }}</td>
          </tr>
          <tr v-for="user in users" :key="user.id" class="tr">
            <td class="td">
              <div class="user-cell">
                <div class="user-avatar">{{ user.name.charAt(0) }}</div>
                <span class="user-name">{{ user.name }}</span>
              </div>
            </td>
            <td class="td text-gray-600">{{ user.email }}</td>
            <td class="td">
              <span :class="user.role === 'admin' ? 'badge-admin' : 'badge-member'">
                {{ user.role === 'admin' ? $t('users.roleAdmin') : $t('users.roleMember') }}
              </span>
            </td>
            <td class="td">
              <span :class="user.is_active ? 'badge-active' : 'badge-inactive'">
                {{ user.is_active ? $t('users.statusActive') : $t('users.statusInactive') }}
              </span>
            </td>
            <td class="td td-actions">
              <button class="btn-ghost btn-icon btn-sm" @click="openEdit(user)">
                <PencilIcon class="action-icon" />
              </button>
              <button class="btn-ghost btn-sm text-xs" @click="toggleActive(user)">
                {{ user.is_active ? $t('common.archive') : $t('common.active') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Form Modal -->
    <div v-if="showForm" class="modal-overlay" @click.self="showForm = false">
      <div class="modal-panel-sm">
        <div class="modal-header">
          <h2 class="heading-2">{{ editingUser ? $t('users.editUser') : $t('users.inviteUser') }}</h2>
          <button class="btn-ghost btn-icon" @click="showForm = false">
            <XMarkIcon class="modal-close-icon" />
          </button>
        </div>
        <form class="modal-body" @submit.prevent="handleSave">
          <div v-if="formError" class="form-error-box">{{ formError }}</div>
          <div class="form-group">
            <label class="form-label">{{ $t('users.nameRequired') }}</label>
            <input v-model="formName" type="text" class="form-input" required />
          </div>
          <div v-if="!editingUser" class="form-group">
            <label class="form-label">{{ $t('users.emailRequired') }}</label>
            <input v-model="formEmail" type="email" class="form-input" required />
          </div>
          <div class="form-group">
            <label class="form-label">{{ $t('users.role') }}</label>
            <select v-model="formRole" class="form-input">
              <option value="member">{{ $t('users.roleMember') }}</option>
              <option value="admin">{{ $t('users.roleAdmin') }}</option>
            </select>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn-secondary" @click="showForm = false">{{ $t('common.cancel') }}</button>
            <button type="submit" class="btn-primary" :disabled="saving">
              {{ saving ? $t('common.saving') : (editingUser ? $t('common.save') : $t('users.inviteUser')) }}
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

.success-banner {
  @apply mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700 border border-green-200;
}

.loading-center {
  @apply flex justify-center py-12;
}

.users-table {
  @apply bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden;
}

.table {
  @apply w-full text-sm;
}

.th {
  @apply px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide bg-gray-50 border-b border-gray-200;
}

.th-actions {
  @apply text-right;
}

.tr {
  @apply border-b border-gray-100 last:border-0 hover:bg-gray-50;
}

.td {
  @apply px-4 py-3 text-sm text-gray-900;
}

.td-empty {
  @apply px-4 py-8 text-center text-sm text-gray-400;
}

.td-actions {
  @apply text-right;
}

.user-cell {
  @apply flex items-center gap-2;
}

.user-avatar {
  @apply flex h-7 w-7 items-center justify-center rounded-full bg-primary-100 text-xs font-medium text-primary-700 shrink-0;
}

.user-name {
  @apply font-medium;
}

.badge-admin {
  @apply inline-flex rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700;
}

.badge-member {
  @apply inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600;
}

.badge-active {
  @apply inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700;
}

.badge-inactive {
  @apply inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-600;
}

.action-icon {
  @apply h-4 w-4;
}

.btn-icon-sm {
  @apply h-4 w-4;
}

.modal-overlay {
  @apply fixed inset-0 z-50 flex items-center justify-center bg-black/50;
}

.modal-panel-sm {
  @apply w-full max-w-sm rounded-xl bg-white shadow-xl;
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

.modal-actions {
  @apply flex justify-end gap-3 pt-2;
}
</style>
