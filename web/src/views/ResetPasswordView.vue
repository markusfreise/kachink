<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ClockIcon } from '@heroicons/vue/24/outline'
import api from '@/api/client'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()

const token = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const loading = ref(false)
const success = ref(false)
const error = ref('')
const fieldErrors = ref<Record<string, string>>({})

onMounted(() => {
  token.value = (route.query.token as string) || ''
  email.value = (route.query.email as string) || ''
})

async function handleSubmit() {
  error.value = ''
  fieldErrors.value = {}
  loading.value = true
  try {
    await api.post('/auth/reset-password', {
      token: token.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    success.value = true
    setTimeout(() => router.push({ name: 'login' }), 2500)
  } catch (e: any) {
    const data = e.response?.data
    if (data?.errors) {
      // Map Laravel validation errors
      const errs: Record<string, string> = {}
      for (const [key, messages] of Object.entries(data.errors as Record<string, string[]>)) {
        errs[key] = (messages as string[])[0] ?? ''
      }
      fieldErrors.value = errs
    } else {
      error.value = data?.message || t('resetPassword.error')
    }
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
        <h1 class="login-title">kaching.</h1>
        <p class="login-subtitle">{{ $t('resetPassword.title') }}</p>
      </div>

      <div v-if="success" class="login-form">
        <div class="success-box">
          {{ $t('resetPassword.successMessage') }}
        </div>
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
            required
          />
          <span v-if="fieldErrors.email" class="field-error">{{ fieldErrors.email }}</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="password">{{ $t('resetPassword.newPassword') }}</label>
          <input
            id="password"
            v-model="password"
            type="password"
            class="form-input"
            :placeholder="$t('resetPassword.minChars')"
            required
            autofocus
          />
          <span v-if="fieldErrors.password" class="field-error">{{ fieldErrors.password }}</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="password_confirmation">{{ $t('resetPassword.confirmPassword') }}</label>
          <input
            id="password_confirmation"
            v-model="passwordConfirmation"
            type="password"
            class="form-input"
            :placeholder="$t('resetPassword.repeatPassword')"
            required
          />
        </div>

        <button type="submit" class="btn-primary login-submit" :disabled="loading">
          {{ loading ? $t('common.saving') : $t('resetPassword.setNewPassword') }}
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

.back-link {
  @apply block text-center text-sm text-gray-500 hover:text-gray-700;
}

.field-error {
  @apply text-xs text-red-600 mt-1;
}
</style>
