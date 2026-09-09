<script setup lang="ts">
import BaseModal from '@/components/BaseModal.vue'

withDefaults(defineProps<{
  title: string
  text?: string
  confirmLabel?: string
  danger?: boolean
  busy?: boolean
}>(), { danger: false, busy: false })

const emit = defineEmits<{ (e: 'confirm'): void; (e: 'cancel'): void }>()
</script>

<template>
  <BaseModal :title="title" size="narrow" @close="emit('cancel')">
    <p v-if="text">{{ text }}</p>
    <template #footer>
      <button type="button" class="btn btn--secondary" @click="emit('cancel')">{{ $t('common.cancel') }}</button>
      <button type="button" class="btn" :class="danger ? 'btn--danger' : 'btn--primary'" :disabled="busy" autofocus @click="emit('confirm')">
        {{ confirmLabel ?? $t('common.confirm') }}
      </button>
    </template>
  </BaseModal>
</template>
