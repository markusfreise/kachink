<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import {
  ClipboardDocumentIcon,
  TrashIcon,
  KeyIcon,
  ComputerDesktopIcon,
  CheckIcon,
} from '@heroicons/vue/24/outline'

const { t } = useI18n()
const auth = useAuthStore()
const settings = useSettingsStore()

const generatedToken = ref<string | null>(null)
const tokenName = ref('kaCHINK! Menu Bar')
const generating = ref(false)
const copied = ref(false)
const error = ref('')

async function generateToken() {
  error.value = ''
  generating.value = true
  generatedToken.value = null
  try {
    const { data } = await api.post('/auth/token', {
      device_name: tokenName.value,
    })
    generatedToken.value = data.data.token
  } catch (e: any) {
    error.value = e.response?.data?.message || t('settings.failedToGenerate')
  } finally {
    generating.value = false
  }
}

async function copyToken() {
  if (!generatedToken.value) return
  await navigator.clipboard.writeText(generatedToken.value)
  copied.value = true
  setTimeout(() => (copied.value = false), 2000)
}

// Fetch existing tokens
interface TokenInfo {
  id: string
  name: string
  last_used_at: string | null
  created_at: string
}

const tokens = ref<TokenInfo[]>([])
const loadingTokens = ref(true)

async function fetchTokens() {
  loadingTokens.value = true
  try {
    const { data } = await api.get('/auth/tokens')
    tokens.value = data.data
  } catch {
    // Endpoint may not exist yet
    tokens.value = []
  } finally {
    loadingTokens.value = false
  }
}

async function revokeToken(id: string) {
  if (!confirm(t('settings.revokeConfirm'))) return
  try {
    await api.delete(`/auth/tokens/${id}`)
    tokens.value = tokens.value.filter((t) => t.id !== id)
  } catch {
    // silently fail
  }
}

fetchTokens()
</script>

<template>
  <div class="page-container">
    <h1 class="heading-1 page-title">{{ $t('settings.title') }}</h1>

    <!-- Profile Info -->
    <div class="card settings-section">
      <div class="card-header">
        <h2 class="heading-3">{{ $t('settings.profile') }}</h2>
      </div>
      <div class="card-body">
        <div class="profile-grid">
          <div class="profile-row">
            <span class="text-label">{{ $t('settings.name') }}</span>
            <span class="profile-value">{{ auth.user?.name }}</span>
          </div>
          <div class="profile-row">
            <span class="text-label">{{ $t('settings.email') }}</span>
            <span class="profile-value">{{ auth.user?.email }}</span>
          </div>
          <div class="profile-row">
            <span class="text-label">{{ $t('settings.role') }}</span>
            <span class="profile-value profile-role">{{ auth.user?.role }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- API Token Generation -->
    <div class="card settings-section">
      <div class="card-header">
        <div class="token-header">
          <div>
            <h2 class="heading-3">{{ $t('settings.apiTokens') }}</h2>
            <p class="text-muted">{{ $t('settings.tokenDescription') }}</p>
          </div>
          <ComputerDesktopIcon class="token-header-icon" />
        </div>
      </div>
      <div class="card-body">
        <!-- Token generation form -->
        <div class="token-form">
          <div class="form-group token-name-group">
            <label class="form-label">{{ $t('settings.deviceName') }}</label>
            <input v-model="tokenName" type="text" class="form-input" :placeholder="$t('settings.devicePlaceholder')" />
          </div>
          <button class="btn-primary token-generate-btn" :disabled="generating || !tokenName.trim()" @click="generateToken">
            <KeyIcon class="btn-icon-sm" />
            {{ generating ? $t('settings.generating') : $t('settings.generateToken') }}
          </button>
        </div>

        <div v-if="error" class="form-error-box">{{ error }}</div>

        <!-- Show generated token -->
        <div v-if="generatedToken" class="token-result">
          <div class="token-warning">
            {{ $t('settings.tokenWarning') }}
          </div>
          <div class="token-display">
            <code class="token-code">{{ generatedToken }}</code>
            <button class="btn-secondary btn-sm" @click="copyToken">
              <CheckIcon v-if="copied" class="btn-icon-sm" />
              <ClipboardDocumentIcon v-else class="btn-icon-sm" />
              {{ copied ? $t('settings.copied') : $t('settings.copy') }}
            </button>
          </div>
          <div class="token-steps">
            <p class="text-label">{{ $t('settings.howToConnect') }}</p>
            <ol class="token-steps-list">
              <li>{{ $t('settings.step1') }}</li>
              <li>{{ $t('settings.step2') }}</li>
              <li>{{ $t('settings.step3') }}</li>
            </ol>
          </div>
        </div>

        <!-- Existing tokens -->
        <div v-if="!loadingTokens && tokens.length > 0" class="tokens-list">
          <h3 class="text-label tokens-list-title">{{ $t('settings.activeTokens') }}</h3>
          <div v-for="token in tokens" :key="token.id" class="token-item">
            <div class="token-item-info">
              <span class="token-item-name">{{ token.name }}</span>
              <span class="text-muted">
                {{ $t('settings.tokenCreated', { date: new Date(token.created_at).toLocaleDateString() }) }}
                <template v-if="token.last_used_at">
                  · {{ $t('settings.tokenLastUsed', { date: new Date(token.last_used_at).toLocaleDateString() }) }}
                </template>
              </span>
            </div>
            <button class="btn-ghost btn-sm" @click="revokeToken(token.id)">
              <TrashIcon class="btn-icon-sm" />
            </button>
          </div>
        </div>
      </div>
    </div>
    <!-- Reporting Settings -->
    <div class="card settings-section">
      <div class="card-header">
        <h2 class="heading-3">{{ $t('settings.reporting') }}</h2>
      </div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label">{{ $t('settings.timeRounding') }}</label>
          <select v-model.number="settings.roundingInterval" class="form-select rounding-select">
            <option :value="0">{{ $t('settings.noRounding') }}</option>
            <option :value="5">{{ $t('settings.minutes5') }}</option>
            <option :value="10">{{ $t('settings.minutes10') }}</option>
            <option :value="15">{{ $t('settings.minutes15') }}</option>
            <option :value="30">{{ $t('settings.minutes30') }}</option>
            <option :value="60">{{ $t('settings.hour1') }}</option>
          </select>
          <p class="form-hint">{{ $t('settings.roundingHint') }}</p>
        </div>
      </div>
    </div>

    <!-- Language -->
    <div class="card settings-section">
      <div class="card-header">
        <h2 class="heading-3">{{ $t('settings.language') }}</h2>
      </div>
      <div class="card-body">
        <div class="form-group">
          <select v-model="settings.locale" class="form-select rounding-select">
            <option value="auto">{{ $t('settings.languageAuto') }}</option>
            <option value="en">English</option>
            <option value="de">Deutsch</option>
          </select>
          <p class="form-hint">{{ $t('settings.languageHint') }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../assets/main.css";

.page-title {
  @apply mb-6;
}

.settings-section {
  @apply mb-6;
}

.profile-grid {
  @apply space-y-3;
}

.profile-row {
  @apply flex items-center gap-4;
}

.profile-value {
  @apply text-sm text-gray-900;
}

.profile-role {
  @apply capitalize;
}

.token-header {
  @apply flex items-start justify-between;
}

.token-header-icon {
  @apply h-6 w-6 text-gray-400;
}

.token-form {
  @apply flex items-end gap-3 mb-4;
}

.token-name-group {
  @apply flex-1;
}

.token-generate-btn {
  @apply shrink-0;
}

.btn-icon-sm {
  @apply h-4 w-4;
}

.form-error-box {
  @apply rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 border border-red-200 mb-4;
}

.token-result {
  @apply rounded-lg border border-green-200 bg-green-50 p-4 mb-4;
}

.token-warning {
  @apply text-sm font-medium text-green-800 mb-2;
}

.token-display {
  @apply flex items-center gap-3 rounded-lg bg-white border border-green-300 p-3;
}

.token-code {
  @apply flex-1 font-mono text-sm text-gray-900 break-all select-all;
}

.token-steps {
  @apply mt-3;
}

.token-steps-list {
  @apply mt-1 text-sm text-green-700 list-decimal list-inside space-y-0.5;
}

.tokens-list {
  @apply border-t border-gray-200 pt-4;
}

.tokens-list-title {
  @apply mb-3;
}

.token-item {
  @apply flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3 mb-2;
}

.token-item-info {
  @apply flex flex-col gap-0.5;
}

.token-item-name {
  @apply text-sm font-medium text-gray-900;
}

.rounding-select {
  @apply w-48;
}

.form-hint {
  @apply text-xs text-gray-500 mt-1;
}
</style>
