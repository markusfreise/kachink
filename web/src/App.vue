<script setup lang="ts">
import { RouterView } from 'vue-router'
import { onMounted } from 'vue'
import { useTimerStore } from '@/stores/timer'
import { useAuthStore } from '@/stores/auth'

const timer = useTimerStore()
const auth = useAuthStore()

onMounted(async () => {
  await auth.fetchUser()
  if (auth.isAuthenticated) {
    await timer.fetchRunning()
  }
})
</script>

<template>
  <RouterView />
</template>
