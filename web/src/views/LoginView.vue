<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { errorMessage } from '@/stores/toast'

const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function handleLogin() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : null
    router.push(redirect ?? { name: 'dashboard' })
  } catch (e) {
    error.value = errorMessage(e, t('auth.invalidCredentials'))
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="auth">
    <div class="auth__card">
      <div class="auth__brand">
        <img src="/appicon.png" alt="" class="auth__logo" />
        <h1 class="auth__title">kaCHINK!</h1>
        <p class="auth__subtitle">{{ $t('auth.signInSubtitle') }}</p>
      </div>

      <form class="form auth__form" novalidate @submit.prevent="handleLogin">
        <div v-if="error" class="form__alert" role="alert">{{ error }}</div>

        <div class="form__group">
          <label class="form__label" for="login-email">{{ $t('auth.email') }}</label>
          <input
            id="login-email"
            v-model="email"
            type="email"
            class="form__input"
            :placeholder="$t('auth.emailPlaceholder')"
            autocomplete="email"
            required
            autofocus
          />
        </div>

        <div class="form__group">
          <div class="auth__label-row">
            <label class="form__label" for="login-password">{{ $t('auth.password') }}</label>
            <RouterLink :to="{ name: 'forgot-password' }" class="auth__link">
              {{ $t('auth.forgotPassword') }}
            </RouterLink>
          </div>
          <input
            id="login-password"
            v-model="password"
            type="password"
            class="form__input"
            :placeholder="$t('auth.passwordPlaceholder')"
            autocomplete="current-password"
            required
          />
        </div>

        <button type="submit" class="btn btn--primary btn--block" :class="{ 'is-loading': loading }" :disabled="loading">
          <span v-if="loading" class="spinner" aria-hidden="true"></span>
          {{ loading ? $t('auth.signingIn') : $t('auth.signIn') }}
        </button>
      </form>
    </div>
  </div>
</template>
