<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useToastStore, errorMessage } from '@/stores/toast'
import { formatDate, formatDuration } from '@/utils/format'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import {
  ClipboardDocumentIcon,
  TrashIcon,
  KeyIcon,
  CheckIcon,
  CommandLineIcon,
  ArrowRightEndOnRectangleIcon,
  MoonIcon,
} from '@heroicons/vue/24/outline'

interface TokenInfo {
  id: string
  name: string
  last_used_at: string | null
  created_at: string
}

const { t } = useI18n()
const auth = useAuthStore()
const settings = useSettingsStore()
const toast = useToastStore()

// Profile
const roleLabel = computed(() => (auth.user?.role === 'admin' ? t('users.roleAdmin') : t('users.roleMember')))
const roleClass = computed(() => (auth.user?.role === 'admin' ? 'badge--brand' : 'badge--neutral'))

// Reporting: rounding example (7 minutes rounded up with the current interval)
const roundingExample = computed(() => {
  const raw = 7 * 60
  return { from: formatDuration(raw), to: formatDuration(settings.roundUpSeconds(raw)) }
})

// Menu bar app
const serverAddress = window.location.origin
const shortcut = 'Cmd + Shift + T'

// Tokens
const tokens = ref<TokenInfo[]>([])
const loadingTokens = ref(true)
const tokenName = ref('kaCHINK! Menu Bar')
const generating = ref(false)
const generatedToken = ref<string | null>(null)
const copied = ref(false)
const revoking = ref<TokenInfo | null>(null)
const revokeBusy = ref(false)

let copiedTimer: ReturnType<typeof setTimeout> | null = null

async function fetchTokens() {
  loadingTokens.value = true
  try {
    const { data } = await api.get('/auth/tokens')
    tokens.value = data.data
  } catch (e) {
    tokens.value = []
    toast.error(errorMessage(e, t('settings.tokensLoadFailed')))
  } finally {
    loadingTokens.value = false
  }
}

async function generateToken() {
  if (!tokenName.value.trim()) return
  generating.value = true
  generatedToken.value = null
  copied.value = false
  try {
    const { data } = await api.post('/auth/token', { device_name: tokenName.value.trim() })
    generatedToken.value = data.data.token
    toast.success(t('settings.tokenGenerated'))
    await fetchTokens()
  } catch (e) {
    toast.error(errorMessage(e, t('settings.failedToGenerate')))
  } finally {
    generating.value = false
  }
}

async function copyToken() {
  if (!generatedToken.value) return
  try {
    await navigator.clipboard.writeText(generatedToken.value)
    copied.value = true
    if (copiedTimer) clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => (copied.value = false), 2000)
  } catch {
    toast.error(t('settings.copyFailed'))
  }
}

async function confirmRevoke() {
  if (!revoking.value) return
  revokeBusy.value = true
  try {
    await api.delete(`/auth/tokens/${revoking.value.id}`)
    tokens.value = tokens.value.filter((token) => token.id !== revoking.value?.id)
    toast.success(t('settings.tokenRevoked'))
    revoking.value = null
  } catch (e) {
    toast.error(errorMessage(e, t('settings.revokeFailed')))
  } finally {
    revokeBusy.value = false
  }
}

fetchTokens()
</script>

<template>
  <div class="page settings">
    <header class="page__header">
      <div>
        <h1 class="heading-1 page__title">{{ $t('settings.title') }}</h1>
      </div>
    </header>

    <div class="settings__stack">
      <!-- Profile -->
      <section class="card" aria-labelledby="settings-profile">
        <div class="card__header">
          <h2 id="settings-profile" class="card__title">{{ $t('settings.profile') }}</h2>
        </div>
        <div class="card__body">
          <dl class="settings__profile">
            <dt class="settings__profile-label">{{ $t('settings.name') }}</dt>
            <dd class="settings__profile-value">{{ auth.user?.name }}</dd>
            <dt class="settings__profile-label">{{ $t('settings.email') }}</dt>
            <dd class="settings__profile-value">{{ auth.user?.email }}</dd>
            <dt class="settings__profile-label">{{ $t('settings.role') }}</dt>
            <dd class="settings__profile-value">
              <span class="badge" :class="roleClass">{{ roleLabel }}</span>
            </dd>
          </dl>
        </div>
      </section>

      <!-- Reporting -->
      <section class="card" aria-labelledby="settings-reporting">
        <div class="card__header">
          <div>
            <h2 id="settings-reporting" class="card__title">{{ $t('settings.reporting') }}</h2>
            <p class="settings__intro">{{ $t('settings.reportingIntro') }}</p>
          </div>
        </div>
        <div class="card__body">
          <div class="form__group">
            <label class="form__label" for="settings-rounding">{{ $t('settings.timeRounding') }}</label>
            <select id="settings-rounding" v-model.number="settings.roundingInterval" class="form__select settings__select">
              <option :value="0">{{ $t('settings.noRounding') }}</option>
              <option :value="5">{{ $t('settings.minutes5') }}</option>
              <option :value="10">{{ $t('settings.minutes10') }}</option>
              <option :value="15">{{ $t('settings.minutes15') }}</option>
              <option :value="30">{{ $t('settings.minutes30') }}</option>
              <option :value="60">{{ $t('settings.hour1') }}</option>
            </select>
            <p class="form__hint">{{ $t('settings.roundingHint') }}</p>
          </div>
          <div class="form__group">
            <label class="form__label" for="settings-pdf-locale">{{ $t('settings.pdfLanguage') }}</label>
            <select id="settings-pdf-locale" v-model="settings.pdfLocale" class="form__select settings__select">
              <option value="de">{{ $t('settings.pdfLanguageDe') }}</option>
              <option value="en">{{ $t('settings.pdfLanguageEn') }}</option>
            </select>
            <p class="form__hint">{{ $t('settings.pdfLanguageHint') }}</p>
            <p class="settings__example">
              <i18n-t keypath="settings.roundingExample" tag="span" scope="global">
                <template #from><strong>{{ roundingExample.from }}</strong></template>
                <template #to><strong>{{ roundingExample.to }}</strong></template>
              </i18n-t>
            </p>
          </div>
        </div>
      </section>

      <!-- Language -->
      <section class="card" aria-labelledby="settings-language">
        <div class="card__header">
          <div>
            <h2 id="settings-language" class="card__title">{{ $t('settings.language') }}</h2>
            <p class="settings__intro">{{ $t('settings.languageIntro') }}</p>
          </div>
        </div>
        <div class="card__body">
          <div class="form__group">
            <label class="form__label" for="settings-locale">{{ $t('settings.language') }}</label>
            <select id="settings-locale" v-model="settings.locale" class="form__select settings__select">
              <option value="auto">{{ $t('settings.languageAuto') }}</option>
              <option value="en">English</option>
              <option value="de">Deutsch</option>
            </select>
            <p class="form__hint">{{ $t('settings.languageHint') }}</p>
          </div>
        </div>
      </section>

      <!-- API tokens -->
      <section class="card" aria-labelledby="settings-tokens">
        <div class="card__header">
          <div>
            <h2 id="settings-tokens" class="card__title">{{ $t('settings.apiTokens') }}</h2>
            <p class="settings__intro">{{ $t('settings.tokenDescription') }}</p>
          </div>
        </div>
        <div class="card__body">
          <form class="settings__token-form" @submit.prevent="generateToken">
            <div class="form__group settings__token-name">
              <label class="form__label" for="settings-token-name">{{ $t('settings.deviceName') }}</label>
              <input
                id="settings-token-name"
                v-model="tokenName"
                type="text"
                class="form__input"
                :placeholder="$t('settings.devicePlaceholder')"
                autocomplete="off"
              />
            </div>
            <button type="submit" class="btn btn--primary" :disabled="generating || !tokenName.trim()">
              <span v-if="generating" class="spinner" aria-hidden="true"></span>
              <KeyIcon v-else class="btn__icon" aria-hidden="true" />
              {{ generating ? $t('settings.generating') : $t('settings.generateToken') }}
            </button>
          </form>

          <div v-if="generatedToken" class="settings__reveal" role="status">
            <p class="settings__reveal-title">{{ $t('settings.tokenWarning') }}</p>
            <div class="settings__reveal-row">
              <code class="settings__code">{{ generatedToken }}</code>
              <button type="button" class="btn btn--secondary btn--sm" @click="copyToken">
                <CheckIcon v-if="copied" class="btn__icon" aria-hidden="true" />
                <ClipboardDocumentIcon v-else class="btn__icon" aria-hidden="true" />
                {{ copied ? $t('settings.copied') : $t('settings.copy') }}
              </button>
            </div>
            <p class="form__label">{{ $t('settings.howToConnect') }}</p>
            <ol class="settings__steps">
              <li>{{ $t('settings.step1') }}</li>
              <li>{{ $t('settings.step2') }}</li>
              <li>{{ $t('settings.step3') }}</li>
            </ol>
          </div>

          <div class="settings__tokens">
            <h3 class="kicker settings__tokens-title">{{ $t('settings.activeTokens') }}</h3>

            <div v-if="loadingTokens" class="loading" :aria-label="$t('settings.loadingTokens')">
              <span class="spinner"></span>
            </div>

            <p v-else-if="tokens.length === 0" class="settings__token-empty">{{ $t('settings.noTokens') }}</p>

            <ul v-else class="settings__token-list">
              <li v-for="token in tokens" :key="token.id" class="settings__token">
                <div class="settings__token-info">
                  <span class="settings__token-title">{{ token.name }}</span>
                  <span class="settings__token-meta">
                    {{ $t('settings.tokenCreated', { date: formatDate(token.created_at) }) }}
                    <template v-if="token.last_used_at">
                      &middot; {{ $t('settings.tokenLastUsed', { date: formatDate(token.last_used_at) }) }}
                    </template>
                  </span>
                </div>
                <button
                  type="button"
                  class="btn btn--danger-ghost btn--icon btn--sm"
                  :aria-label="$t('settings.revokeToken')"
                  :title="$t('settings.revokeToken')"
                  @click="revoking = token"
                >
                  <TrashIcon class="btn__icon" aria-hidden="true" />
                </button>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <!-- Menu bar app -->
      <section class="card" aria-labelledby="settings-menubar">
        <div class="card__header">
          <div>
            <h2 id="settings-menubar" class="card__title">{{ $t('settings.menuBarApp') }}</h2>
            <p class="settings__intro">{{ $t('settings.menuBarIntro') }}</p>
          </div>
        </div>
        <div class="card__body">
          <div class="settings__features">
            <div class="settings__feature">
              <ArrowRightEndOnRectangleIcon class="settings__feature-icon" aria-hidden="true" />
              <span class="settings__feature-title">{{ $t('settings.menuBarSignInTitle') }}</span>
              <p class="settings__feature-text">{{ $t('settings.menuBarSignInText') }}</p>
            </div>
            <div class="settings__feature">
              <CommandLineIcon class="settings__feature-icon" aria-hidden="true" />
              <span class="settings__feature-title">{{ $t('settings.menuBarShortcutTitle') }}</span>
              <p class="settings__feature-text">
                <i18n-t keypath="settings.menuBarShortcutText" tag="span" scope="global">
                  <template #shortcut><kbd class="settings__kbd">{{ shortcut }}</kbd></template>
                </i18n-t>
              </p>
            </div>
            <div class="settings__feature">
              <MoonIcon class="settings__feature-icon" aria-hidden="true" />
              <span class="settings__feature-title">{{ $t('settings.menuBarIdleTitle') }}</span>
              <p class="settings__feature-text">{{ $t('settings.menuBarIdleText') }}</p>
            </div>
          </div>

          <div class="settings__server">
            <span class="settings__server-label">{{ $t('settings.serverAddress') }}</span>
            <code class="settings__server-value">{{ serverAddress }}</code>
          </div>
        </div>
      </section>
    </div>

    <ConfirmDialog
      v-if="revoking"
      :title="$t('settings.revokeTitle')"
      :text="$t('settings.revokeConfirm')"
      :confirm-label="$t('settings.revokeToken')"
      danger
      :busy="revokeBusy"
      @confirm="confirmRevoke"
      @cancel="revoking = null"
    />
  </div>
</template>
