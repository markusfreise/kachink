<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import { errorMessage } from '@/stores/toast'

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
  } catch (e) {
    const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response?.data
    if (data?.errors) {
      // Map Laravel validation errors to their fields
      const errs: Record<string, string> = {}
      for (const [key, messages] of Object.entries(data.errors)) {
        errs[key] = messages[0] ?? ''
      }
      fieldErrors.value = errs
    } else {
      error.value = errorMessage(e, t('resetPassword.error'))
    }
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
        <p class="auth__subtitle">{{ $t('resetPassword.title') }}</p>
      </div>

      <div v-if="success" class="form auth__form">
        <div class="form__alert form__alert--success" role="status">
          {{ $t('resetPassword.successMessage') }}
        </div>
      </div>

      <form v-else class="form auth__form" novalidate @submit.prevent="handleSubmit">
        <div v-if="error" class="form__alert" role="alert">{{ error }}</div>

        <div class="form__group">
          <label class="form__label" for="reset-email">{{ $t('auth.email') }}</label>
          <input
            id="reset-email"
            v-model="email"
            type="email"
            class="form__input"
            autocomplete="email"
            :aria-invalid="fieldErrors.email ? 'true' : undefined"
            :aria-describedby="fieldErrors.email ? 'reset-email-error' : undefined"
            required
          />
          <span v-if="fieldErrors.email" id="reset-email-error" class="form__error">{{ fieldErrors.email }}</span>
        </div>

        <div class="form__group">
          <label class="form__label" for="reset-password">{{ $t('resetPassword.newPassword') }}</label>
          <input
            id="reset-password"
            v-model="password"
            type="password"
            class="form__input"
            :placeholder="$t('resetPassword.minChars')"
            autocomplete="new-password"
            :aria-invalid="fieldErrors.password ? 'true' : undefined"
            :aria-describedby="fieldErrors.password ? 'reset-password-error' : undefined"
            required
            autofocus
          />
          <span v-if="fieldErrors.password" id="reset-password-error" class="form__error">{{ fieldErrors.password }}</span>
        </div>

        <div class="form__group">
          <label class="form__label" for="reset-password-confirmation">{{ $t('resetPassword.confirmPassword') }}</label>
          <input
            id="reset-password-confirmation"
            v-model="passwordConfirmation"
            type="password"
            class="form__input"
            :placeholder="$t('resetPassword.repeatPassword')"
            autocomplete="new-password"
            required
          />
        </div>

        <button type="submit" class="btn btn--primary btn--block" :disabled="loading">
          <span v-if="loading" class="spinner" aria-hidden="true"></span>
          {{ loading ? $t('common.saving') : $t('resetPassword.setNewPassword') }}
        </button>
      </form>

      <p v-if="!success" class="auth__footer">
        <RouterLink :to="{ name: 'login' }" class="auth__link">{{ $t('auth.backToSignIn') }}</RouterLink>
      </p>
    </div>
  </div>
</template>
