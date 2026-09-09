<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import { useOrgStore } from '@/stores/org'
import { errorMessage } from '@/stores/toast'
import { ComputerDesktopIcon } from '@heroicons/vue/24/outline'

const route = useRoute()
const { t } = useI18n()
const org = useOrgStore()

const code = computed(() => (route.query.code as string) || '')
const deviceName = ref('')
const state = ref<'loading' | 'ready' | 'approved' | 'denied' | 'error'>('loading')
const error = ref('')
const busy = ref(false)
const organizationId = ref<string>('')

onMounted(async () => {
  if (!code.value) {
    state.value = 'error'
    error.value = t('connect.missingCode')
    return
  }
  try {
    const { data } = await api.get(`/auth/device/${code.value}/info`)
    deviceName.value = data.data.device_name
    if (data.data.status !== 'pending') {
      state.value = 'error'
      error.value = t('connect.expired')
      return
    }
    organizationId.value = org.currentOrgId ?? org.organizations[0]?.id ?? ''
    state.value = 'ready'
  } catch (e) {
    state.value = 'error'
    error.value = errorMessage(e, t('connect.expired'))
  }
})

async function approve() {
  busy.value = true
  try {
    await api.post(`/auth/device/${code.value}/approve`, { organization_id: organizationId.value || null })
    state.value = 'approved'
  } catch (e) {
    state.value = 'error'
    error.value = errorMessage(e, t('connect.failed'))
  } finally {
    busy.value = false
  }
}

async function deny() {
  busy.value = true
  try {
    await api.post(`/auth/device/${code.value}/deny`)
    state.value = 'denied'
  } catch (e) {
    error.value = errorMessage(e, t('connect.failed'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page connect">
    <div class="card connect__card">
      <div class="card__body connect__body">
        <ComputerDesktopIcon class="connect__icon" aria-hidden="true" />

        <template v-if="state === 'loading'">
          <div class="loading"><div class="spinner" role="status"></div></div>
        </template>

        <template v-else-if="state === 'ready'">
          <h1 class="heading-2">{{ $t('connect.title') }}</h1>
          <p class="connect__text">{{ $t('connect.question', { device: deviceName }) }}</p>
          <div v-if="org.organizations.length > 1" class="form__group connect__org">
            <label class="form__label" for="connect-org">{{ $t('nav.organization') }}</label>
            <select id="connect-org" v-model="organizationId" class="form__select">
              <option v-for="o in org.organizations" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
          </div>
          <p v-else-if="org.currentOrg" class="muted small">{{ org.currentOrg.name }}</p>
          <div class="connect__actions">
            <button type="button" class="btn btn--secondary" :disabled="busy" @click="deny">{{ $t('connect.deny') }}</button>
            <button type="button" class="btn btn--primary" :disabled="busy" autofocus @click="approve">{{ $t('connect.approve') }}</button>
          </div>
        </template>

        <template v-else-if="state === 'approved'">
          <h1 class="heading-2">{{ $t('connect.approvedTitle') }}</h1>
          <p class="connect__text">{{ $t('connect.approvedText', { device: deviceName }) }}</p>
        </template>

        <template v-else-if="state === 'denied'">
          <h1 class="heading-2">{{ $t('connect.deniedTitle') }}</h1>
          <p class="connect__text">{{ $t('connect.deniedText') }}</p>
        </template>

        <template v-else>
          <h1 class="heading-2">{{ $t('connect.errorTitle') }}</h1>
          <p class="connect__text">{{ error }}</p>
        </template>
      </div>
    </div>
  </div>
</template>
