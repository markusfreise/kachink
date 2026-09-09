<script setup lang="ts">
import { computed } from 'vue'
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import type { PaginationMeta } from '@/types'

const props = defineProps<{ meta: PaginationMeta | null; page: number }>()
const emit = defineEmits<{ (e: 'update:page', page: number): void }>()

const pages = computed(() => {
  const last = props.meta?.last_page ?? 1
  const current = props.page
  const items: (number | '...')[] = []
  const add = (n: number) => { if (!items.includes(n)) items.push(n) }
  if (last <= 7) {
    for (let i = 1; i <= last; i++) add(i)
    return items
  }
  add(1)
  if (current > 3) items.push('...')
  for (let i = Math.max(2, current - 1); i <= Math.min(last - 1, current + 1); i++) add(i)
  if (current < last - 2) items.push('...')
  add(last)
  return items
})

function go(p: number) {
  const last = props.meta?.last_page ?? 1
  if (p < 1 || p > last || p === props.page) return
  emit('update:page', p)
}
</script>

<template>
  <nav v-if="meta && meta.last_page > 1" class="pagination" :aria-label="$t('common.pagination')">
    <button type="button" class="btn btn--secondary btn--sm btn--icon" :disabled="page === 1" :aria-label="$t('common.previous')" @click="go(page - 1)">
      <ChevronLeftIcon class="btn__icon" />
    </button>
    <div class="pagination__pages">
      <template v-for="(p, i) in pages" :key="i">
        <span v-if="p === '...'" class="pagination__page" aria-hidden="true">...</span>
        <button
          v-else
          type="button"
          class="pagination__page"
          :class="{ 'pagination__page--active': p === page }"
          :aria-current="p === page ? 'page' : undefined"
          @click="go(p)"
        >
          {{ p }}
        </button>
      </template>
    </div>
    <button type="button" class="btn btn--secondary btn--sm btn--icon" :disabled="page === meta.last_page" :aria-label="$t('common.next')" @click="go(page + 1)">
      <ChevronRightIcon class="btn__icon" />
    </button>
    <span class="pagination__info">{{ $t('common.page', { current: page, last: meta.last_page }) }}</span>
  </nav>
</template>
