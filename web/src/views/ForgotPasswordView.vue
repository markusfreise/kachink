<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import { errorMessage } from '@/stores/toast'

const { t } = useI18n()
const email = ref('')
const loading = ref(false)
const success = ref(false)
const error = ref('')

async function handleSubmit() {
  error.value = ''
  loading.value = true
  try {
    await api.post('/auth/forgot-password', { email: email.value })
    success.value = true
  } catch (e) {
    error.value = errorMessage(e, t('forgotPassword.error'))
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
        <p class="auth__subtitle">{{ $t('forgotPassword.title') }}</p>
      </div>

      <div v-if="success" class="form auth__form">
        <div class="form__alert form__alert--success" role="status">
          {{ $t('forgotPassword.successMessage') }}
        </div>
        <RouterLink :to="{ name: 'login' }" class="btn btn--primary btn--block">
          {{ $t('auth.backToSignIn') }}
        </RouterLink>
      </div>

      <form v-else class="form auth__form" novalidate @submit.prevent="handleSubmit">
        <div v-if="error" class="form__alert" role="alert">{{ error }}</div>

        <div class="form__group">
          <label class="form__label" for="forgot-email">{{ $t('auth.email') }}</label>
          <input
            id="forgot-email"
            v-model="email"
            type="email"
            class="form__input"
            :placeholder="$t('auth.emailPlaceholder')"
            autocomplete="email"
            required
            autofocus
          />
        </div>

        <button type="submit" class="btn btn--primary btn--block" :disabled="loading">
          <span v-if="loading" class="spinner" aria-hidden="true"></span>
          {{ loading ? $t('forgotPassword.sending') : $t('forgotPassword.sendResetLink') }}
        </button>
      </form>

      <p v-if="!success" class="auth__footer">
        <RouterLink :to="{ name: 'login' }" class="auth__link">{{ $t('auth.backToSignIn') }}</RouterLink>
      </p>
    </div>
  </div>
</template>
