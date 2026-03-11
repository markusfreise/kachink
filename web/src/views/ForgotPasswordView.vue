<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ClockIcon } from '@heroicons/vue/24/outline'
import api from '@/api/client'

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
  } catch (e: any) {
    error.value = e.response?.data?.message || t('forgotPassword.error')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <div class="login-card">
      <div class="login-header">
        <ClockIcon class="login-logo" />
        <h1 class="login-title">klingeLING!</h1>
        <p class="login-subtitle">{{ $t('forgotPassword.title') }}</p>
      </div>

      <div v-if="success" class="login-form">
        <div class="success-box">
          {{ $t('forgotPassword.successMessage') }}
        </div>
        <router-link :to="{ name: 'login' }" class="btn-primary back-btn">
          {{ $t('auth.backToSignIn') }}
        </router-link>
      </div>

      <form v-else class="login-form" @submit.prevent="handleSubmit">
        <div v-if="error" class="login-error">{{ error }}</div>

        <div class="form-group">
          <label class="form-label" for="email">{{ $t('auth.email') }}</label>
          <input
            id="email"
            v-model="email"
            type="email"
            class="form-input"
            :placeholder="$t('auth.emailPlaceholder')"
            required
            autofocus
          />
        </div>

        <button type="submit" class="btn-primary login-submit" :disabled="loading">
          {{ loading ? $t('forgotPassword.sending') : $t('forgotPassword.sendResetLink') }}
        </button>

        <router-link :to="{ name: 'login' }" class="back-link">
          {{ $t('auth.backToSignInArrow') }}
        </router-link>
      </form>
    </div>
  </div>
</template>

<style scoped>
@reference "../assets/main.css";
.login-page {
  @apply flex min-h-screen items-center justify-center bg-gray-50 px-4;
}

.login-card {
  @apply w-full max-w-sm;
}

.login-header {
  @apply flex flex-col items-center mb-8;
}

.login-logo {
  @apply h-12 w-12 text-primary-600;
}

.login-title {
  @apply mt-3 text-2xl font-bold text-gray-900;
}

.login-subtitle {
  @apply mt-1 text-sm text-gray-500;
}

.login-form {
  @apply bg-white rounded-lg border border-gray-200 shadow-sm px-6 py-4 space-y-4;
}

.login-error {
  @apply rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 border border-red-200;
}

.success-box {
  @apply rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700 border border-green-200;
}

.login-submit {
  @apply w-full;
}

.back-btn {
  @apply w-full text-center;
}

.back-link {
  @apply block text-center text-sm text-gray-500 hover:text-gray-700;
}
</style>
