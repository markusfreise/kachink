import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api, { getCsrfCookie } from '@/api/client'
import { useOrgStore } from '@/stores/org'
import type { User } from '@/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const loading = ref(false)

  const isAuthenticated = computed(() => !!user.value)
  const isAdmin = computed(() => user.value?.role === 'admin')

  async function login(email: string, password: string) {
    await getCsrfCookie()
    const { data } = await api.post('/auth/login', { email, password })
    user.value = data.data
  }

  async function logout() {
    await api.post('/auth/logout')
    user.value = null
    useOrgStore().clear()
  }

  async function fetchUser() {
    try {
      loading.value = true
      const { data } = await api.get('/auth/me')
      user.value = data.data
    } catch {
      user.value = null
    } finally {
      loading.value = false
    }
  }

  return { user, loading, isAuthenticated, isAdmin, login, logout, fetchUser }
})
