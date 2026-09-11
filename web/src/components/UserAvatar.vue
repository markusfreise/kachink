<script setup lang="ts">
import { computed } from 'vue'

/** Round user picture with the initial as fallback. */
const props = withDefaults(defineProps<{
  name?: string | null
  avatarUrl?: string | null
  size?: 'sm' | 'md' | 'lg'
}>(), { name: '', avatarUrl: null, size: 'md' })

const initial = computed(() => (props.name?.trim().charAt(0) || '?').toUpperCase())
</script>

<template>
  <span class="avatar" :class="`avatar--${size}`" :title="name ?? undefined" aria-hidden="true">
    <img v-if="avatarUrl" class="avatar__img" :src="avatarUrl" :alt="name ?? ''" loading="lazy" />
    <span v-else class="avatar__initial">{{ initial }}</span>
  </span>
</template>
