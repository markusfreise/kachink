<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import api from '@/api/client'
import type { User } from '@/types'
import { useAuthStore } from '@/stores/auth'
import { useToastStore, errorMessage } from '@/stores/toast'
import { formatCurrency } from '@/utils/format'
import BaseModal from '@/components/BaseModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { PlusIcon, UserGroupIcon } from '@heroicons/vue/24/outline'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToastStore()

const users = ref<User[]>([])
const loading = ref(true)
const showForm = ref(false)
const editingUser = ref<User | null>(null)

const formName = ref('')
const formEmail = ref('')
const formRole = ref<'admin' | 'member'>('member')
const formRate = ref<number | null>(null)
const saving = ref(false)
const formError = ref('')

const toggleTarget = ref<User | null>(null)
const toggling = ref(false)

const currentUserId = computed(() => auth.user?.id ?? null)

async function fetchUsers() {
  loading.value = true
  try {
    const { data } = await api.get('/users')
    users.value = data.data
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
  }
}

function openInvite() {
  editingUser.value = null
  formName.value = ''
  formEmail.value = ''
  formRole.value = 'member'
  formRate.value = null
  formError.value = ''
  showForm.value = true
}

function openEdit(user: User) {
  editingUser.value = user
  formName.value = user.name
  formEmail.value = user.email
  formRole.value = user.role
  formRate.value = user.hourly_rate != null ? Number(user.hourly_rate) : null
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
        hourly_rate: formRate.value === null || Number.isNaN(formRate.value) ? null : formRate.value,
      })
      toast.success(t('users.saved'))
    } else {
      await api.post('/users', {
        name: formName.value,
        email: formEmail.value,
        role: formRole.value,
      })
      toast.success(t('users.inviteSent'))
    }
    showForm.value = false
    fetchUsers()
  } catch (e) {
    formError.value = errorMessage(e, t('common.failedToSave'))
  } finally {
    saving.value = false
  }
}

async function confirmToggle() {
  const user = toggleTarget.value
  if (!user) return
  toggling.value = true
  try {
    await api.put(`/users/${user.id}`, { is_active: !user.is_active })
    toast.success(user.is_active ? t('users.deactivated', { name: user.name }) : t('users.activated', { name: user.name }))
    toggleTarget.value = null
    fetchUsers()
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
  } finally {
    toggling.value = false
  }
}

onMounted(fetchUsers)
</script>

<template>
  <div class="page users">
    <div class="page__header">
      <div>
        <h1 class="heading-1 page__title">{{ $t('users.title') }}</h1>
        <p v-if="!loading && users.length > 0" class="page__subtitle small">{{ $t('users.count', { count: users.length }) }}</p>
      </div>
      <div class="page__actions">
        <button type="button" class="btn btn--primary" @click="openInvite">
          <PlusIcon class="btn__icon" aria-hidden="true" />
          {{ $t('users.inviteUser') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="loading">
      <div class="spinner" role="status"></div>
    </div>

    <div v-else class="card">
      <div v-if="users.length === 0" class="empty">
        <UserGroupIcon class="empty__icon" aria-hidden="true" />
        <p class="empty__text">{{ $t('users.noUsers') }}</p>
        <button type="button" class="btn btn--primary btn--sm" @click="openInvite">
          <PlusIcon class="btn__icon" aria-hidden="true" />
          {{ $t('users.inviteUser') }}
        </button>
      </div>

      <div v-else class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>{{ $t('common.name') }}</th>
              <th>{{ $t('common.email') }}</th>
              <th>{{ $t('users.role') }}</th>
              <th>{{ $t('users.status') }}</th>
              <th class="table__num">{{ $t('rates.memberRate') }}</th>
              <th class="users__actions-head">{{ $t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="user in users"
              :key="user.id"
              class="table__row"
              :class="{ 'users__row--inactive': !user.is_active }"
            >
              <td>
                <div class="cell">
                  <span class="users__avatar" aria-hidden="true">{{ user.name.charAt(0) }}</span>
                  <span class="cell__title">
                    {{ user.name }}<template v-if="user.id === currentUserId"> ({{ $t('users.you') }})</template>
                  </span>
                </div>
              </td>
              <td class="users__email">{{ user.email }}</td>
              <td>
                <span class="badge" :class="user.role === 'admin' ? 'badge--brand' : 'badge--neutral'">
                  {{ user.role === 'admin' ? $t('users.roleAdmin') : $t('users.roleMember') }}
                </span>
              </td>
              <td>
                <span class="badge" :class="user.is_active ? 'badge--success' : 'badge--danger'">
                  {{ user.is_active ? $t('users.statusActive') : $t('users.statusInactive') }}
                </span>
              </td>
              <td class="table__num" :class="{ 'table__muted': user.hourly_rate == null }">
                {{ user.hourly_rate != null ? formatCurrency(Number(user.hourly_rate)) + '/h' : '–' }}
              </td>
              <td>
                <div class="table__actions">
                  <RouterLink
                    class="btn btn--ghost btn--sm"
                    :to="{ name: 'report-detail', params: { scope: 'users', id: user.id } }"
                  >
                    {{ $t('reportDetail.report') }}
                  </RouterLink>
                  <button type="button" class="btn btn--ghost btn--sm" @click="openEdit(user)">{{ $t('common.edit') }}</button>
                  <button
                    v-if="user.id !== currentUserId"
                    type="button"
                    class="btn btn--ghost btn--sm"
                    @click="toggleTarget = user"
                  >
                    {{ user.is_active ? $t('users.deactivate') : $t('users.activate') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <BaseModal
      v-if="showForm"
      :title="editingUser ? $t('users.editUser') : $t('users.inviteUser')"
      size="narrow"
      @close="showForm = false"
    >
      <form id="user-form" class="form" @submit.prevent="handleSave">
        <div v-if="formError" class="form__alert" role="alert">{{ formError }}</div>
        <div class="form__group">
          <label for="user-name" class="form__label">{{ $t('users.nameRequired') }}</label>
          <input id="user-name" v-model="formName" type="text" class="form__input" required autofocus />
        </div>
        <div v-if="!editingUser" class="form__group">
          <label for="user-email" class="form__label">{{ $t('users.emailRequired') }}</label>
          <input id="user-email" v-model="formEmail" type="email" class="form__input" required />
          <span class="form__hint">{{ $t('users.emailHint') }}</span>
        </div>
        <div class="form__group">
          <label for="user-role" class="form__label">{{ $t('users.role') }}</label>
          <select id="user-role" v-model="formRole" class="form__select">
            <option value="member">{{ $t('users.roleMember') }}</option>
            <option value="admin">{{ $t('users.roleAdmin') }}</option>
          </select>
        </div>
        <div v-if="editingUser" class="form__group">
          <label for="user-rate" class="form__label">{{ $t('rates.memberRate') }} (EUR/h)</label>
          <input id="user-rate" v-model.number="formRate" type="number" min="0" step="0.01" inputmode="decimal" class="form__input" :placeholder="$t('common.optional')" />
          <span class="form__hint">{{ $t('rates.memberRateHint') }}</span>
        </div>
      </form>
      <template #footer>
        <button type="button" class="btn btn--secondary" @click="showForm = false">{{ $t('common.cancel') }}</button>
        <button type="submit" form="user-form" class="btn btn--primary" :disabled="saving">
          {{ saving ? $t('common.saving') : (editingUser ? $t('common.save') : $t('users.inviteUser')) }}
        </button>
      </template>
    </BaseModal>

    <ConfirmDialog
      v-if="toggleTarget"
      :title="toggleTarget.is_active ? $t('users.deactivateTitle') : $t('users.activateTitle')"
      :text="toggleTarget.is_active ? $t('users.deactivateText', { name: toggleTarget.name }) : $t('users.activateText', { name: toggleTarget.name })"
      :confirm-label="toggleTarget.is_active ? $t('users.deactivate') : $t('users.activate')"
      :danger="toggleTarget.is_active"
      :busy="toggling"
      @confirm="confirmToggle"
      @cancel="toggleTarget = null"
    />
  </div>
</template>
