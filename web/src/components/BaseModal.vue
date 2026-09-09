<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, nextTick } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'

const props = withDefaults(defineProps<{
  title: string
  size?: 'narrow' | 'default' | 'wide'
  closeOnBackdrop?: boolean
}>(), {
  size: 'default',
  closeOnBackdrop: true,
})

const emit = defineEmits<{ (e: 'close'): void }>()

const panel = ref<HTMLElement | null>(null)
let previouslyFocused: HTMLElement | null = null

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

function focusables(): HTMLElement[] {
  if (!panel.value) return []
  return Array.from(panel.value.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((el) => el.offsetParent !== null)
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    e.stopPropagation()
    emit('close')
    return
  }
  if (e.key !== 'Tab') return
  const items = focusables()
  if (items.length === 0) return
  const first = items[0]!
  const last = items[items.length - 1]!
  const active = document.activeElement as HTMLElement | null
  if (e.shiftKey && (active === first || !panel.value?.contains(active))) {
    e.preventDefault()
    last.focus()
  } else if (!e.shiftKey && active === last) {
    e.preventDefault()
    first.focus()
  }
}

onMounted(async () => {
  previouslyFocused = document.activeElement as HTMLElement | null
  document.addEventListener('keydown', onKeydown)
  document.body.classList.add('is-scroll-locked')
  await nextTick()
  const target = panel.value?.querySelector<HTMLElement>('[autofocus]') ?? focusables().find((el) => !el.classList.contains('modal__close'))
  target?.focus()
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  document.body.classList.remove('is-scroll-locked')
  previouslyFocused?.focus?.()
})
</script>

<template>
  <Teleport to="body">
    <div class="modal" @mousedown.self="closeOnBackdrop && emit('close')">
      <div
        ref="panel"
        class="modal__panel"
        :class="{ 'modal__panel--wide': size === 'wide', 'modal__panel--narrow': size === 'narrow' }"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
      >
        <div class="modal__header">
          <h2 class="modal__title">{{ title }}</h2>
          <button type="button" class="btn btn--ghost btn--icon btn--sm modal__close" :aria-label="$t('common.close')" @click="emit('close')">
            <XMarkIcon class="btn__icon" />
          </button>
        </div>
        <div class="modal__body">
          <slot />
        </div>
        <div v-if="$slots.footer" class="modal__footer">
          <slot name="footer" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
