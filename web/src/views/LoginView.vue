<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function handleLogin() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push({ name: 'dashboard' })
  } catch (e: any) {
    error.value = e.response?.data?.message || t('auth.invalidCredentials')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <div class="login-card">
      <div class="login-header">
        <img src="/appicon.png" alt="kaCHINK!" class="login-logo" />
        <h1 class="login-title">kaCHINK!</h1>
        <p class="login-subtitle">{{ $t('auth.signInSubtitle') }}</p>
      </div>

      <form class="login-form" @submit.prevent="handleLogin">
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

        <div class="form-group">
          <div class="form-label-row">
            <label class="form-label" for="password">{{ $t('auth.password') }}</label>
            <router-link :to="{ name: 'forgot-password' }" class="forgot-link">
              {{ $t('auth.forgotPassword') }}
            </router-link>
          </div>
          <input
            id="password"
            v-model="password"
            type="password"
            class="form-input"
            :placeholder="$t('auth.passwordPlaceholder')"
            required
          />
        </div>

        <button type="submit" class="btn-primary login-submit" :disabled="loading">
          {{ loading ? $t('auth.signingIn') : $t('auth.signIn') }}
        </button>
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
  @apply h-16 w-16 rounded-2xl;
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

.login-submit {
  @apply w-full;
}

.form-label-row {
  @apply flex items-center justify-between mb-1;
}

.form-label-row .form-label {
  @apply mb-0;
}

.forgot-link {
  @apply text-xs text-primary-600 hover:text-primary-800;
}
</style>
