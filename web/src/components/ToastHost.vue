<script setup lang="ts">
import { useToastStore } from '@/stores/toast'
import { XMarkIcon } from '@heroicons/vue/24/outline'

const toast = useToastStore()
</script>

<template>
  <div class="toasts" aria-live="polite" aria-atomic="false">
    <div
      v-for="t in toast.toasts"
      :key="t.id"
      class="toast"
      :class="{ 'toast--error': t.kind === 'error', 'toast--success': t.kind === 'success' }"
      role="status"
    >
      <span class="toast__text">{{ t.text }}</span>
      <button class="toast__close" type="button" :aria-label="$t('common.close')" @click="toast.dismiss(t.id)">
        <XMarkIcon class="btn__icon" />
      </button>
    </div>
  </div>
</template>
